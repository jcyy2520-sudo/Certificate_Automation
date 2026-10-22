<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\CertificateBatchItem;
use App\Models\CertificateTemplate;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Policies\WebinarPolicy;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CertificateService
{
    /** @var array<string, string> data URI per disk+path, valid for this instance only */
    private array $backgroundUriCache = [];

    public function __construct(
        private EligibilityService $eligibility,
        private NotificationService $notifications,
    ) {}

    /**
     * Issue a certificate to a participant.
     *
     * The participant record is the only source for the printed name. Correct
     * that record before issuing instead of creating a certificate-only spelling.
     */
    public function issue(Participant $participant, ?CertificateBatch $batch = null, ?array $layout = null): Certificate
    {
        $diskName = (string) config('webinar.certificate_disk');
        $certificate = $this->prepare($participant, $batch, $diskName, $layout);

        if ($certificate->status === 'issued') {
            return $certificate;
        }

        $path = $this->expectedPath($certificate);
        $templateSignature = $this->templateSignature($certificate->template);

        try {
            $contents = $this->render($certificate);

            if (! Storage::disk($diskName)->put($path, $contents)) {
                throw new RuntimeException('The certificate could not be stored securely. Nothing was issued.');
            }

            return $this->finalize($certificate, $contents, $templateSignature);
        } catch (Throwable $exception) {
            $this->cleanFailedProcessing($certificate, $path);

            throw $exception;
        }
    }

    /**
     * Persist the one-to-one certificate work item before dispatching a queue
     * job. This is intentionally quick: PDF rendering and email delivery happen
     * after the browser request has returned.
     */
    public function queue(Participant $participant, ?array $layout = null): Certificate
    {
        return $this->prepare(
            $participant,
            null,
            (string) config('webinar.certificate_disk'),
            $layout,
        );
    }

    private function prepare(
        Participant $participant,
        ?CertificateBatch $batch,
        string $diskName,
        ?array $layout,
    ): Certificate {
        return DB::transaction(function () use ($participant, $batch, $diskName, $layout): Certificate {
            $webinar = $this->lockedIssuableWebinar($participant->webinar_id);
            $requester = $batch?->created_by ?? auth()->id();
            $existingRequester = Certificate::query()->where('participant_id', $participant->id)->where('status', 'processing')->whereNull('revoked_at')->value('requested_by');
            $requester ??= $existingRequester;
            $this->assertRequester($requester, $webinar);
            $lockedParticipant = Participant::query()
                ->whereKey($participant->getKey())
                ->where('webinar_id', $webinar->id)
                ->whereNull('privacy_erased_at')
                ->lockForUpdate()
                ->first();

            if (! $lockedParticipant) {
                throw new RuntimeException('The participant record is no longer available for certificate issuance.');
            }
            if (blank($lockedParticipant->full_name)) {
                throw new RuntimeException('Confirm the participant name before issuing a certificate.');
            }

            $this->loadFreshEligibility($lockedParticipant, $webinar);

            if (! $this->eligibility->canPrepareCertificate($lockedParticipant)) {
                throw new RuntimeException('The participant does not currently meet the certificate requirements. Add an authorized exception using Add Recipient Manually in Certificate Studio.');
            }

            $existing = Certificate::query()
                ->where('participant_id', $lockedParticipant->id)
                ->whereNull('revoked_at')
                ->whereIn('status', ['processing', 'issued'])
                ->lockForUpdate()
                ->first();

            if ($existing?->status === 'issued') {
                if ($existing->recipient_name !== $lockedParticipant->full_name) {
                    throw new RuntimeException('The issued certificate has an earlier name. Revoke it before issuing a corrected certificate.');
                }

                return $existing;
            }

            $template = $webinar->certificateTemplates()
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $template) {
                throw new RuntimeException('This webinar has no active certificate template.');
            }

            if (blank($template->background_path)) {
                throw new RuntimeException('Upload a certificate design before issuing.');
            }

            if ($existing) {
                if ($existing->certificate_template_id !== $template->id) {
                    throw new RuntimeException('A certificate is already being prepared with a different template.');
                }

                $existing->touch();
                $existing->setRelation('webinar', $webinar);
                $existing->setRelation('template', $template);

                return $existing;
            }

            // A savepoint lets PostgreSQL recover from a code collision without
            // leaving the outer issuance transaction in an aborted state.
            $certificate = null;
            $lastError = null;
            for ($attempt = 0; $attempt < 3 && $certificate === null; $attempt++) {
                try {
                    $certificate = DB::transaction(fn () => Certificate::query()->create([
                        'verification_code' => 'CERT-'.strtoupper(Str::random(20)),
                        'webinar_id' => $lockedParticipant->webinar_id,
                        'participant_id' => $lockedParticipant->id,
                        'certificate_template_id' => $template->id,
                        'certificate_batch_id' => $batch?->id,
                        'recipient_name' => $lockedParticipant->full_name,
                        'layout' => $layout ?: $template->layout,
                        'storage_disk' => $diskName,
                        'status' => 'processing',
                        'requested_by' => $requester,
                    ]));
                } catch (UniqueConstraintViolationException $exception) {
                    if (! str_contains($exception->getMessage(), 'certificates_verification_code_hash_unique')
                        && ! str_contains($exception->getMessage(), 'certificates.verification_code_hash')) {
                        throw $exception;
                    }
                    $lastError = $exception;
                }
            }
            if ($certificate === null) {
                throw new RuntimeException('A unique certificate number could not be generated. Try again.', 0, $lastError);
            }
            // HasPublicId assigns the identifier during creation. Build the
            // inventory path from that persisted value instead of supplying a
            // mass-assignment-guarded public_id that the model would replace.
            $certificate->update(['file_path' => $this->expectedPath($certificate)]);
            $certificate->setRelation('webinar', $webinar);
            $certificate->setRelation('template', $template);

            return $certificate;
        }, attempts: 3);
    }

    private function finalize(Certificate $certificate, string $contents, string $templateSignature): Certificate
    {
        return DB::transaction(function () use ($certificate, $contents, $templateSignature): Certificate {
            $webinar = $this->lockedIssuableWebinar($certificate->webinar_id);
            $participant = Participant::query()
                ->whereKey($certificate->participant_id)
                ->where('webinar_id', $webinar->id)
                ->whereNull('privacy_erased_at')
                ->lockForUpdate()
                ->first();

            if (! $participant) {
                throw new RuntimeException('The participant record is no longer available for certificate issuance.');
            }

            $lockedCertificate = Certificate::query()->whereKey($certificate->id)->lockForUpdate()->firstOrFail();
            $this->assertRequester($lockedCertificate->requested_by, $webinar);

            if ($lockedCertificate->status === 'issued') {
                return $lockedCertificate;
            }

            if ($lockedCertificate->status !== 'processing'
                || ! hash_equals($this->expectedPath($lockedCertificate), (string) $lockedCertificate->file_path)) {
                throw new RuntimeException('The certificate preparation record is no longer valid.');
            }
            if ($lockedCertificate->recipient_name !== $participant->full_name) {
                throw new RuntimeException('The participant name changed during preparation. Generate the certificate again with the corrected name.');
            }

            $template = CertificateTemplate::query()
                ->whereKey($lockedCertificate->certificate_template_id)
                ->where('webinar_id', $webinar->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (! $template || blank($template->background_path)
                || ! hash_equals($templateSignature, $this->templateSignature($template))) {
                throw new RuntimeException('The certificate template changed while the certificate was being prepared.');
            }

            $this->loadFreshEligibility($participant, $webinar);

            $evaluation = $this->eligibility->evaluate($participant, $webinar->eligibilityRules);
            if (! $evaluation['eligible'] && ! $this->eligibility->hasManualRecipient($participant)) {
                throw new RuntimeException('The participant no longer meets the certificate requirements.');
            }

            // Point-in-time snapshot so "why was this person eligible" stays
            // answerable after rules change, overrides expire, or responses
            // are superseded. No PII beyond requirement keys and scores.
            $snapshot = [
                'issued_at' => now()->toIso8601String(),
                'source' => $this->eligibility->hasManualRecipient($participant) || $evaluation['overridden'] ? 'manual' : 'eligible',
                'manual_authorization_id' => $this->eligibility->hasManualRecipient($participant) ? $participant->certificateApproval->id : null,
                'overridden' => $evaluation['overridden'],
                'requirements' => $evaluation['requirements'],
                'rules' => $webinar->eligibilityRules->map(fn ($rule) => [
                    'requirement' => $rule->requirement,
                    'is_required' => (bool) $rule->is_required,
                    'minimum_score' => $rule->minimum_score,
                ])->values()->all(),
            ];

            $lockedCertificate->update([
                'status' => 'issued',
                'issued_at' => now(),
                'eligibility_snapshot' => $snapshot,
            ]);
            $lockedCertificate->setRelation('webinar', $webinar);
            $lockedCertificate->setRelation('template', $template);
            app(AuditService::class)->record(request(), 'certificate.generated', $lockedCertificate,
                ['execution' => app()->runningInConsole() ? 'queue' : 'request'],
                actor: $lockedCertificate->requested_by ? User::query()->find($lockedCertificate->requested_by) : null);

            if (filled($participant->email)) {
                $statusForm = $webinar->forms()->where('type', 'registration')->first(['public_token']);
                $this->notifications->queue(
                    $webinar,
                    $participant,
                    'certificate',
                    $participant->email,
                    $webinar->certificateEmailSubject($lockedCertificate->recipient_name),
                    view('emails.certificate-issued', [
                        'participant' => $participant,
                        'certificate' => $lockedCertificate,
                        'customMessage' => $webinar->certificateEmailMessage($lockedCertificate->recipient_name),
                        'statusUrl' => $statusForm ? app(ParticipantStatusLinkService::class)->createLink($webinar, $participant, now()->addDay()) : null,
                    ])->render(),
                    $lockedCertificate,
                    [['name' => 'certificate.pdf', 'content' => base64_encode($contents)]],
                    $webinar->retention_due_at,
                );
            }

            return $lockedCertificate;
        }, attempts: 3);
    }

    private function assertRequester(?int $id, Webinar $webinar): void
    {
        // Null preserves historical system work. All new authenticated requests
        // retain their actor and must still be authorized when the worker runs.
        if ($id === null) {
            return;
        }
        $actor = User::query()->find($id);
        $policy = app(WebinarPolicy::class);
        if (! $actor || ! $policy->permission($actor, $webinar, 'manage_certificates') || ! $policy->permission($actor, $webinar, 'send_emails')) {
            throw new RuntimeException('The account that requested issuance no longer has permission.');
        }
    }

    private function lockedIssuableWebinar(int $webinarId): Webinar
    {
        $webinar = Webinar::query()
            ->whereKey($webinarId)
            ->whereNull('deletion_started_at')
            ->whereNull('archived_at')
            ->whereIn('status', ['published', 'completed'])
            ->whereNotNull('retention_due_at')
            ->where('retention_due_at', '>', now())
            ->lockForUpdate()
            ->first();

        if (! $webinar) {
            throw new RuntimeException('This webinar is closed for certificate issuance.');
        }

        $owner = $webinar->creator;
        if ($owner && ! $owner->canAccessPlatform()) {
            throw new RuntimeException('Certificate issuance is paused because the webinar owner account is unavailable.');
        }

        return $webinar;
    }

    private function loadFreshEligibility(Participant $participant, Webinar $webinar): void
    {
        $webinar->unsetRelation('eligibilityRules');
        $webinar->load('eligibilityRules');
        $participant->unsetRelations();
        $participant->load([
            'submissions.form',
            'eligibilityOverrides' => fn ($query) => $query
                ->where(fn ($active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest()
                ->orderByDesc('id'),
        ]);
        $participant->setRelation('webinar', $webinar);
    }

    private function expectedPath(Certificate $certificate): string
    {
        return 'certificates/'.$certificate->public_id.'.pdf';
    }

    private function templateSignature(CertificateTemplate $template): string
    {
        return hash('sha256', implode("\0", [
            (string) $template->id,
            (string) $template->getRawOriginal('updated_at'),
            (string) $template->background_path,
            json_encode($template->layout, JSON_THROW_ON_ERROR),
        ]));
    }

    private function cleanFailedProcessing(Certificate $certificate, string $path): void
    {
        $committed = Certificate::query()
            ->whereKey($certificate->id)
            ->where('status', 'issued')
            ->whereNotNull('issued_at')
            ->exists();

        if ($committed) {
            return;
        }

        try {
            $disk = Storage::disk($certificate->storage_disk);
            $deleted = ! $disk->exists($path) || $disk->delete($path);

            if (! $deleted && $disk->exists($path)) {
                $this->reportOrphanCleanupFailure($certificate->storage_disk, $path);

                return;
            }

            DB::transaction(function () use ($certificate, $path): void {
                $identity = Certificate::query()
                    ->whereKey($certificate->id)
                    ->select(['webinar_id', 'participant_id'])
                    ->first();

                if (! $identity) {
                    return;
                }

                Webinar::query()->whereKey($identity->webinar_id)->lockForUpdate()->first();
                if ($identity->participant_id) {
                    Participant::withTrashed()->whereKey($identity->participant_id)->lockForUpdate()->first();
                }

                $locked = Certificate::query()->whereKey($certificate->id)->lockForUpdate()->first();
                if ($locked?->status === 'processing' && hash_equals($path, (string) $locked->file_path)) {
                    $locked->update([
                        'status' => 'failed',
                        'file_path' => null,
                        'issuance_key' => null,
                    ]);
                }
            }, attempts: 3);
        } catch (Throwable $cleanupException) {
            report($cleanupException);
            $this->reportOrphanCleanupFailure($certificate->storage_disk, $path);
        }
    }

    private function reportOrphanCleanupFailure(string $diskName, string $path): void
    {
        $fingerprint = hash_hmac('sha256', $diskName."\0".$path, (string) config('app.key'));
        Log::critical('A failed certificate file could not be removed.', [
            'disk' => $diskName,
            'path_fingerprint' => $fingerprint,
        ]);
        AuditLog::query()->create([
            'action' => 'certificate.orphan_cleanup_failed',
            'metadata' => ['disk' => $diskName, 'path_fingerprint' => $fingerprint],
        ]);
    }

    /**
     * Issue certificates for every eligible participant of a webinar that does not
     * already hold a valid one, tracking progress on a batch record.
     */
    public function issueBatch(CertificateBatch $batch): CertificateBatch
    {
        $batch->loadMissing('webinar');

        if (in_array($batch->status, ['completed', 'cancelled'], true) || $batch->webinar->deletion_started_at) {
            return $batch;
        }

        // Start from the same query the studio uses. The per-person `issue()`
        // check remains the authoritative locked re-check, but filtering here
        // prevents an otherwise successful batch from reporting ineligible
        // people as failures and avoids needless PDF/job work.
        $candidates = Participant::query()
            ->whereIn('participants.id', $this->eligibility->certificateParticipantsQuery($batch->webinar))
            ->whereNull('privacy_erased_at')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereDoesntHave('certificates', fn ($query) => $query->whereNull('revoked_at')->whereNotNull('issued_at'));

        // A worker can die after committing a PDF but before writing its batch
        // outcome. Recover that durable issuance before counting remaining work.
        $batch->certificates()->whereNotNull('issued_at')->with('participant')
            ->eachById(function (Certificate $certificate) use ($batch): void {
                if ($certificate->participant) {
                    $this->recordBatchItem($batch, $certificate->participant, $certificate, 'issued', null);
                }
            });
        $completed = $batch->items()->where('status', 'issued')
            // A revoked certificate can put this recipient back into the work
            // set. Count each recipient once, after the replacement succeeds.
            ->whereNotIn('participant_id', (clone $candidates)->select('participants.id'))
            ->distinct()->count('participant_id');
        $failed = 0;
        $total = max((int) $batch->fresh()->total_count, $completed + (clone $candidates)->count());

        $claimed = CertificateBatch::query()
            ->whereKey($batch->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->update([
                'status' => 'processing',
                'total_count' => $total,
                'completed_count' => $completed,
                'failed_count' => 0,
                'completed_at' => null,
            ]);

        if ($claimed === 0) {
            return $batch->refresh();
        }

        $candidates
            ->with([
                'webinar.eligibilityRules',
                'webinar.certificateTemplates' => fn ($query) => $query->where('is_active', true),
                'submissions.form',
                'eligibilityOverrides' => fn ($query) => $query
                    ->where(fn ($active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                    ->latest()
                    ->orderByDesc('id'),
                'certificates' => fn ($query) => $query->whereNull('revoked_at')->whereNotNull('issued_at'),
            ])
            ->chunkById(100, function ($participants) use ($batch, &$completed, &$failed) {
                $stillActive = CertificateBatch::query()
                    ->whereKey($batch->id)
                    ->where('status', 'processing')
                    ->exists()
                    && Webinar::query()
                        ->whereKey($batch->webinar_id)
                        ->whereNull('deletion_started_at')
                        ->where(fn ($retention) => $retention
                            ->whereNull('retention_due_at')
                            ->orWhere('retention_due_at', '>', now()))
                        ->exists();

                if (! $stillActive) {
                    return false;
                }

                foreach ($participants as $participant) {
                    try {
                        $certificate = $this->issue($participant, $batch);
                        $completed++;
                        $this->recordBatchItem($batch, $participant, $certificate, 'issued', null);
                    } catch (RuntimeException $exception) {
                        if (! CertificateBatch::query()
                            ->whereKey($batch->id)
                            ->where('status', 'processing')
                            ->exists()) {
                            return false;
                        }

                        // Participants who do not meet the requirements are skipped, not errors.
                        // The per-recipient row keeps the batch recoverable: the
                        // operator sees who failed and why without re-running.
                        $failed++;
                        $this->recordBatchItem($batch, $participant, null, 'failed', mb_substr($exception->getMessage(), 0, 500));
                    }
                    // Keep visible progress and recovery evidence after every
                    // recipient, rather than losing the counters on interruption.
                    CertificateBatch::query()->whereKey($batch->id)->where('status', 'processing')->update([
                        'completed_count' => $completed,
                        'failed_count' => $failed,
                    ]);
                }

                return true;
            });

        CertificateBatch::query()
            ->whereKey($batch->id)
            ->where('status', 'processing')
            ->update([
                'status' => 'completed',
                'completed_count' => $completed,
                'failed_count' => $failed,
                'completed_at' => now(),
            ]);

        return $batch->refresh();
    }

    /**
     * Durable per-recipient batch outcome. Counts alone cannot answer "who
     * failed and why" after a bulk run; this row can. Best-effort: a logging
     * failure must never fail the issuance itself.
     */
    private function recordBatchItem(CertificateBatch $batch, Participant $participant, ?Certificate $certificate, string $status, ?string $message): void
    {
        try {
            CertificateBatchItem::query()->updateOrCreate([
                'certificate_batch_id' => $batch->id,
                'participant_id' => $participant->id,
            ], [
                'webinar_id' => $batch->webinar_id,
                'certificate_id' => $certificate?->id,
                'status' => $status,
                'message' => $message,
            ]);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /**
     * Render the certificate PDF and return its raw bytes: the uploaded design
     * with the recipient's name placed on top of it, and nothing else.
     */
    public function render(Certificate $certificate): string
    {
        $certificate->loadMissing(['webinar', 'template']);
        $template = $certificate->template;

        if (blank($template?->background_path)) {
            throw new RuntimeException('This certificate template has no uploaded design to render.');
        }

        // Size the page to the uploaded design's aspect ratio so the certificate
        // is reproduced exactly, never stretched or cropped. The reference sheet
        // height (595, matching pdf-custom) keeps the name's size and position
        // calibrated regardless of the image's real pixel dimensions.
        $ratio = $template->aspectRatio();
        $height = 595.0;
        $width = round($height * $ratio, 2);
        // Embed only the used glyphs; full font files made a name-only PDF
        // hundreds of KiB and multiplied queue payload/HTTP memory per recipient.
        $pdf = Pdf::setPaper([0, 0, $width, $height])->setOption('isFontSubsettingEnabled', true);
        try {
            $fitted = app(CertificateNameLayout::class)->fit((string) $certificate->recipient_name, $certificate->layout ?? $template->layout ?? [], $ratio, $pdf->getDomPDF());
            // Validate existing as well as new artwork before loadView/output can
            // decode it. Encoded byte size alone does not bound image memory use.
            $backgroundDataUri = $this->backgroundDataUri($template);
            $pdf->loadView('certificates.pdf-custom', [
                'certificate' => $certificate,
                'backgroundDataUri' => $backgroundDataUri,
                'fittedFontSize' => $fitted['font_size'],
                'nameWidthPercent' => $fitted['width_percent'],
            ]);

            return $pdf->output();
        } finally {
            // Dompdf's frame/canvas graph is cyclic. Do not retain hundreds of
            // decoded documents until PHP's automatic cycle threshold fires.
            unset($pdf);
            gc_collect_cycles();
        }
    }

    /** Read an uploaded certificate background off disk and inline it as a data URI, so dompdf never needs filesystem/chroot access to render it. */
    private function backgroundDataUri(CertificateTemplate $template): ?string
    {
        // Bulk batches render the same artwork hundreds of times. Artwork is
        // never overwritten in place (replacement uploads a new timestamped
        // path, then deletes the old one), so memoizing per service instance
        // is safe and keeps a large batch from base64-loading the same file
        // on every render. The instance is short-lived (one request or job).
        $key = $template->storage_disk."\0".$template->background_path;
        if (isset($this->backgroundUriCache[$key])) {
            return $this->backgroundUriCache[$key];
        }

        $disk = Storage::disk($template->storage_disk);

        if (! $disk->exists($template->background_path)) {
            throw new RuntimeException('The certificate artwork is missing. Restore the image before issuing.');
        }

        if ($disk->size($template->background_path) > CertificateArtwork::MAX_BYTES) {
            throw new RuntimeException('Certificate artwork must be at most 8 MB. Export a smaller PNG or JPG and upload it again.');
        }
        $contents = $disk->get($template->background_path);
        $image = CertificateArtwork::inspect($contents);

        return $this->backgroundUriCache[$key] = 'data:'.$image['mime'].';base64,'.base64_encode($contents);
    }
}
