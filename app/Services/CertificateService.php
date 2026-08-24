<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\CertificateTemplate;
use App\Models\Participant;
use App\Models\Webinar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class CertificateService
{
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
            $lockedParticipant = Participant::query()
                ->whereKey($participant->getKey())
                ->where('webinar_id', $webinar->id)
                ->whereNull('privacy_erased_at')
                ->lockForUpdate()
                ->first();

            if (! $lockedParticipant) {
                throw new RuntimeException('The participant record is no longer available for certificate issuance.');
            }

            $this->loadFreshEligibility($lockedParticipant, $webinar);

            if (! $this->eligibility->evaluate($lockedParticipant, $webinar->eligibilityRules)['eligible']) {
                throw new RuntimeException('The participant does not currently meet the certificate requirements.');
            }

            $existing = Certificate::query()
                ->where('participant_id', $lockedParticipant->id)
                ->whereNull('revoked_at')
                ->whereIn('status', ['processing', 'issued'])
                ->lockForUpdate()
                ->first();

            if ($existing?->status === 'issued') {
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

            $certificate = Certificate::query()->create([
                'verification_code' => 'CERT-'.strtoupper(Str::random(20)),
                'webinar_id' => $lockedParticipant->webinar_id,
                'participant_id' => $lockedParticipant->id,
                'certificate_template_id' => $template->id,
                'certificate_batch_id' => $batch?->id,
                'recipient_name' => $lockedParticipant->full_name,
                'layout' => $layout ?: $template->layout,
                'storage_disk' => $diskName,
                'status' => 'processing',
            ]);
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

            if ($lockedCertificate->status === 'issued') {
                return $lockedCertificate;
            }

            if ($lockedCertificate->status !== 'processing'
                || ! hash_equals($this->expectedPath($lockedCertificate), (string) $lockedCertificate->file_path)) {
                throw new RuntimeException('The certificate preparation record is no longer valid.');
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

            if (! $this->eligibility->evaluate($participant, $webinar->eligibilityRules)['eligible']) {
                throw new RuntimeException('The participant no longer meets the certificate requirements.');
            }

            $lockedCertificate->update([
                'status' => 'issued',
                'issued_at' => now(),
            ]);
            $lockedCertificate->setRelation('webinar', $webinar);
            $lockedCertificate->setRelation('template', $template);

            if (filled($participant->email)) {
                $verificationUrl = route('certificates.verify', $lockedCertificate->verification_code);
                $this->notifications->queue(
                    $webinar,
                    $participant,
                    'certificate',
                    $participant->email,
                    'Your certificate for '.$webinar->title,
                    view('emails.certificate-issued', [
                        'participant' => $participant,
                        'certificate' => $lockedCertificate,
                        'verificationUrl' => $verificationUrl,
                    ])->render(),
                    $lockedCertificate,
                    [['name' => 'certificate.pdf', 'content' => base64_encode($contents)]],
                    $webinar->retention_due_at,
                );
            }

            return $lockedCertificate;
        }, attempts: 3);
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

        if ($batch->status === 'cancelled' || $batch->webinar->deletion_started_at) {
            return $batch;
        }

        $candidates = $batch->webinar->participants()
            ->whereNull('privacy_erased_at')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->whereDoesntHave('certificates', fn ($query) => $query->whereNull('revoked_at')->whereNotNull('issued_at'));

        $claimed = CertificateBatch::query()
            ->whereKey($batch->id)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->update([
                'status' => 'processing',
                'total_count' => (clone $candidates)->count(),
                'completed_count' => 0,
                'failed_count' => 0,
            ]);

        if ($claimed === 0) {
            return $batch->refresh();
        }

        $completed = 0;
        $failed = 0;

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
                        $this->issue($participant, $batch);
                        $completed++;
                    } catch (RuntimeException) {
                        if (! CertificateBatch::query()
                            ->whereKey($batch->id)
                            ->where('status', 'processing')
                            ->exists()) {
                            return false;
                        }

                        // Participants who do not meet the requirements are skipped, not errors.
                        $failed++;
                    }
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

        $pdf = Pdf::loadView('certificates.pdf-custom', [
            'certificate' => $certificate,
            'backgroundDataUri' => $this->backgroundDataUri($template),
        ]);

        // Size the page to the uploaded design's aspect ratio so the certificate
        // is reproduced exactly, never stretched or cropped. The reference sheet
        // height (595, matching pdf-custom) keeps the name's size and position
        // calibrated regardless of the image's real pixel dimensions.
        $ratio = $template->aspectRatio();
        $height = 595.0;
        $width = round($height * $ratio, 2);
        $pdf->setPaper([0, 0, $width, $height]);

        return $pdf->output();
    }

    /** Read an uploaded certificate background off disk and inline it as a data URI, so dompdf never needs filesystem/chroot access to render it. */
    private function backgroundDataUri(CertificateTemplate $template): ?string
    {
        $disk = Storage::disk($template->storage_disk);

        if (! $disk->exists($template->background_path)) {
            return null;
        }

        $mime = $disk->mimeType($template->background_path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($disk->get($template->background_path));
    }
}
