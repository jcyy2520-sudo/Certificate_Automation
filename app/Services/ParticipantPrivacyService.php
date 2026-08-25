<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\EligibilityOverride;
use App\Models\EmailDelivery;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Participant;
use App\Models\SubmissionAnswer;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;

class ParticipantPrivacyService
{
    public function __construct(private CertificateFileService $certificateFiles) {}

    /** Erase all participant-linked personal data, optionally removing the tombstone too. */
    public function erase(Participant $participant, bool $forceDelete = false): void
    {
        DB::transaction(function () use ($participant, $forceDelete): void {
            $participant = Participant::withTrashed()->lockForUpdate()->find($participant->id);

            if (! $participant) {
                return;
            }

            // Certificate issuance and magic-link delivery take the same
            // participant lock. Snapshotting only after it is acquired ensures
            // a concurrent worker cannot create a PII-bearing PDF or email just
            // beyond the erasure boundary.
            $certificateFiles = Certificate::query()
                ->where('participant_id', $participant->id)
                ->select(['id', 'public_id', 'storage_disk', 'file_path'])
                ->lockForUpdate()
                ->get();

            // Delete rendered PDFs before marking their inventory erased. If
            // storage is unavailable, the transaction rolls back and can be
            // retried without losing track of a private file.
            $this->certificateFiles->delete($certificateFiles);

            $submissionIds = $participant->submissions()->pluck('id');
            $overrideIds = $participant->eligibilityOverrides()->pluck('id');
            $certificateIds = $certificateFiles->pluck('id');
            $erasedAt = now();

            SubmissionAnswer::query()->whereIn('submission_id', $submissionIds)->delete();
            $participant->submissions()->update([
                'score' => null,
                'maximum_score' => null,
                'answers_erased_at' => $erasedAt,
                'metadata' => null,
            ]);
            $participant->accessTokens()->delete();
            $participant->eligibilityOverrides()->delete();

            Certificate::query()->whereIn('id', $certificateIds)->update([
                'participant_id' => null,
                'issuance_key' => null,
                'recipient_name' => null,
                'file_path' => null,
                'revocation_reason' => null,
                'privacy_erased_at' => $erasedAt,
            ]);

            EmailDelivery::query()
                ->where(function ($query) use ($participant, $certificateIds): void {
                    $query->where('participant_id', $participant->id);
                    if ($certificateIds->isNotEmpty()) {
                        $query->orWhereIn('certificate_id', $certificateIds);
                    }
                })
                ->update([
                    'participant_id' => null,
                    'recipient_email' => null,
                    'subject' => null,
                    'payload' => null,
                    'provider_message_id' => null,
                    'last_error' => null,
                ]);

            // Old audit metadata may predate the central redaction rules. Sever
            // participant/override references and remove metadata during erasure.
            AuditLog::query()
                ->where('auditable_type', $participant->getMorphClass())
                ->where('auditable_id', $participant->id)
                ->update(['auditable_type' => null, 'auditable_id' => null, 'metadata' => null]);

            if ($overrideIds->isNotEmpty()) {
                AuditLog::query()
                    ->where('auditable_type', (new EligibilityOverride)->getMorphClass())
                    ->whereIn('auditable_id', $overrideIds)
                    ->update(['auditable_type' => null, 'auditable_id' => null, 'metadata' => null]);
            }

            if ($certificateIds->isNotEmpty()) {
                AuditLog::query()
                    ->where('auditable_type', (new Certificate)->getMorphClass())
                    ->whereIn('auditable_id', $certificateIds)
                    ->update(['metadata' => null]);
            }

            $participant->forceFill([
                'full_name' => null,
                'email' => null,
                'organization' => null,
                'email_verified_at' => null,
                'verified_at' => null,
                'checked_in_at' => null,
                'last_access_at' => null,
                'privacy_erased_at' => $erasedAt,
            ])->save();

            if ($forceDelete) {
                $participant->forceDelete();
            }
        }, attempts: 3);
    }

    /**
     * Erase the personal data an import left behind for one webinar.
     *
     * The per-participant sweep cannot reach this. An unmatched row belongs to
     * someone who completed a test but never registered, so it has no
     * participant to erase through, and it would otherwise outlive the
     * retention deadline indefinitely.
     *
     * Issue rows carry the address and the original spreadsheet row, so they are
     * deleted outright. The import rows keep their counts and timestamps as
     * evidence the run happened, matching how a delivered EmailDelivery is
     * retained with its message content removed.
     *
     * @return array{imports: int, issues: int}
     */
    public function eraseWebinarImports(Webinar $webinar): array
    {
        return DB::transaction(function () use ($webinar): array {
            $importIds = Import::query()
                ->where('webinar_id', $webinar->id)
                ->whereNull('privacy_erased_at')
                ->lockForUpdate()
                ->pluck('id');

            // Issues are deleted whether or not their import was already
            // stamped, so a partially completed earlier run cannot strand them.
            $issues = ImportIssue::query()->where('webinar_id', $webinar->id)->delete();

            if ($importIds->isEmpty()) {
                return ['imports' => 0, 'issues' => $issues];
            }

            $imports = Import::query()->whereIn('id', $importIds)->update([
                'column_map' => null,
                'privacy_erased_at' => now(),
            ]);

            return ['imports' => $imports, 'issues' => $issues];
        }, attempts: 3);
    }
}
