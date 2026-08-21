<?php

namespace App\Services;

use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\CertificateTemplate;
use App\Models\AuditLog;
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
        private QrCodeService $qrCodes,
    ) {}

    public function issue(Participant $participant, ?CertificateBatch $batch = null): Certificate
    {
        $participant->loadMissing('webinar');
        $diskName = (string) config('webinar.certificate_disk');
        $attemptedPath = null;
        $certificateId = null;

        try {
            return DB::transaction(function () use ($participant, $batch, $diskName, &$attemptedPath, &$certificateId): Certificate {
                // The global lifecycle lock order is webinar -> participant ->
                // child records. A deletion tombstone is committed under the
                // webinar lock before file cleanup begins.
                $lockedWebinar = Webinar::query()
                    ->whereKey($participant->webinar_id)
                    ->whereNull('deletion_started_at')
                    ->lockForUpdate()
                    ->first();

                if (! $lockedWebinar || $lockedWebinar->status === 'archived') {
                    throw new RuntimeException('This webinar is closed for certificate issuance.');
                }

                if ($lockedWebinar->retention_due_at?->isPast()) {
                    throw new RuntimeException('This webinar has reached its privacy retention deadline.');
                }

                $lockedParticipant = Participant::query()
                    ->whereKey($participant->getKey())
                    ->whereNull('privacy_erased_at')
                    ->lockForUpdate()
                    ->first();

                if (! $lockedParticipant) {
                    throw new RuntimeException('The participant record is no longer available for certificate issuance.');
                }

                if ($participant->relationLoaded('webinar')) {
                    $lockedWebinar->setRelations($participant->webinar->getRelations());
                }
                $lockedParticipant->setRelations($participant->getRelations());
                $lockedParticipant->setRelation('webinar', $lockedWebinar);

                if (! $this->eligibility->evaluate($lockedParticipant)['eligible']) {
                    throw new RuntimeException('The participant does not currently meet the certificate requirements.');
                }

                $existing = Certificate::query()
                    ->where('participant_id', $lockedParticipant->id)
                    ->whereNull('revoked_at')
                    ->whereNotNull('issued_at')
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing;
                }

                $template = $lockedParticipant->webinar->relationLoaded('certificateTemplates')
                    ? $lockedParticipant->webinar->certificateTemplates->firstWhere('is_active', true)
                    : $lockedParticipant->webinar->certificateTemplates()->where('is_active', true)->first();
                if (! $template) {
                    throw new RuntimeException('This webinar has no active certificate template.');
                }

                // The database-level key is defence in depth for databases or
                // code paths where row locks are accidentally weakened.
                $certificate = Certificate::query()->create([
                    'verification_code' => 'CERT-'.strtoupper(Str::random(20)),
                    'webinar_id' => $lockedParticipant->webinar_id,
                    'participant_id' => $lockedParticipant->id,
                    'certificate_template_id' => $template->id,
                    'certificate_batch_id' => $batch?->id,
                    'recipient_name' => $lockedParticipant->full_name,
                    'storage_disk' => $diskName,
                    'issuance_key' => $lockedParticipant->webinar_id.':'.$lockedParticipant->id,
                    'status' => 'processing',
                ]);
                $certificateId = $certificate->id;
                $certificate->setRelation('webinar', $lockedParticipant->webinar);
                $certificate->setRelation('template', $template);

                $contents = $this->render($certificate);
                $attemptedPath = 'certificates/'.$certificate->public_id.'.pdf';

                if (! Storage::disk($diskName)->put($attemptedPath, $contents)) {
                    throw new RuntimeException('The certificate could not be stored securely. Nothing was issued.');
                }

                // Public verification becomes valid only after durable storage
                // succeeds. A failed write rolls this entire transaction back.
                $certificate->update([
                    'file_path' => $attemptedPath,
                    'status' => 'issued',
                    'issued_at' => now(),
                ]);

                if (filled($lockedParticipant->email)) {
                    $verificationUrl = route('certificates.verify', $certificate->verification_code);
                    $this->notifications->queue(
                        $lockedParticipant->webinar,
                        $lockedParticipant,
                        'certificate',
                        $lockedParticipant->email,
                        'Your certificate for '.$lockedParticipant->webinar->title,
                        view('emails.certificate-issued', [
                            'participant' => $lockedParticipant,
                            'certificate' => $certificate,
                            'verificationUrl' => $verificationUrl,
                        ])->render(),
                        $certificate,
                        [['name' => 'certificate.pdf', 'content' => base64_encode($contents)]],
                    );
                }

                return $certificate;
            }, attempts: 3);
        } catch (Throwable $exception) {
            // If the file write succeeded but the database transaction did not,
            // remove the orphan. Do not remove a committed certificate if a
            // post-commit queue dispatch was the operation that failed.
            $committed = $certificateId !== null
                && Certificate::query()->whereKey($certificateId)->whereNotNull('issued_at')->exists();

            if ($attemptedPath !== null && ! $committed) {
                try {
                    $disk = Storage::disk($diskName);
                    $deleted = $disk->delete($attemptedPath);

                    if (! $deleted && $disk->exists($attemptedPath)) {
                        $fingerprint = hash_hmac('sha256', $diskName."\0".$attemptedPath, (string) config('app.key'));
                        Log::critical('A rolled-back certificate file could not be removed.', [
                            'disk' => $diskName,
                            'path_fingerprint' => $fingerprint,
                        ]);
                        AuditLog::query()->create([
                            'action' => 'certificate.orphan_cleanup_failed',
                            'metadata' => ['disk' => $diskName, 'path_fingerprint' => $fingerprint],
                        ]);
                    }
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }

            throw $exception;
        }
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

    /** Render the certificate PDF and return its raw bytes. */
    public function render(Certificate $certificate): string
    {
        $certificate->loadMissing(['webinar', 'template']);
        $template = $certificate->template;

        // An uploaded background replaces the generated design entirely: just
        // that image with the recipient's name placed on top of it.
        if ($template?->background_path) {
            return Pdf::loadView('certificates.pdf-custom', [
                'certificate' => $certificate,
                'backgroundDataUri' => $this->backgroundDataUri($template),
            ])->setPaper('a4', 'landscape')->output();
        }

        $verificationUrl = route('certificates.verify', $certificate->verification_code);

        return Pdf::loadView('certificates.pdf', [
            'certificate' => $certificate,
            'verificationUrl' => $verificationUrl,
            'qrCode' => $this->qrCodes->dataUri($verificationUrl),
        ])->setPaper('a4', 'landscape')->output();
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
