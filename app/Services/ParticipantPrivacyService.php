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
            Webinar::query()->whereKey($participant->webinar_id)->lockForUpdate()->first();
            $participant = Participant::withTrashed()->lockForUpdate()->find($participant->id);

            if (! $participant) {
                return;
            }

            // New review evidence follows the existing erasure boundary. Keep
            // inventory until private storage confirms deletion, so retry is safe.
            foreach ($participant->eligibilityReviews()->lockForUpdate()->get() as $review) {
                app(EligibilityReviewFiles::class)->delete($review);
                $review->update(['explanation' => null, 'decision_reason' => null, 'file_path' => null, 'storage_disk' => null, 'extension' => null, 'file_size' => null, 'evidence_status' => null]);
            }
            $participant->eligibilityDecisions()->update(['reason' => null]);

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
            $this->eraseLegacySelectionMetadata($participant->webinar_id);

            SubmissionAnswer::query()->whereIn('submission_id', $submissionIds)->delete();
            $participant->submissions()->update([
                'score' => null,
                'maximum_score' => null,
                'answers_erased_at' => $erasedAt,
                'metadata' => null,
                'imported_data' => null,
            ]);
            $participant->accessTokens()->delete();
            $participant->eligibilityOverrides()->delete();
            $participant->certificateApproval()->delete();

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
        // Pending files are not represented by participant records. Remove
        // them before recording the privacy erasure: if private storage is
        // unavailable, preserving the database record lets a later retention
        // run retry rather than falsely claiming that the source was erased.
        $pendingIds = Import::query()
            ->where('webinar_id', $webinar->id)
            ->pluck('id');
        foreach ($pendingIds as $importId) {
            // Include older committed/cancelled or previously stamped records:
            // earlier versions could leave their plaintext file behind.
            ImportSourceFile::delete((int) $importId);
        }

        return DB::transaction(function () use ($webinar): array {
            Webinar::query()->whereKey($webinar->id)->lockForUpdate()->first();
            $this->eraseLegacySelectionMetadata($webinar->id);
            $imports = Import::query()
                ->where('webinar_id', $webinar->id)
                ->whereNull('privacy_erased_at')
                ->lockForUpdate()
                ->get();

            // Issues are deleted whether or not their import was already
            // stamped, so a partially completed earlier run cannot strand them.
            $issues = ImportIssue::query()->where('webinar_id', $webinar->id)->delete();

            if ($imports->isEmpty()) {
                return ['imports' => 0, 'issues' => $issues];
            }

            // Retain a non-PII audit summary per import so "which file, which
            // mapping, which outcome" stays answerable after erasure: file
            // hash and counts live in their own columns; the mapping survives
            // as field-to-column indices plus a header-layout signature, never
            // raw response values. Free-text completion attestations are not
            // retained: they do not establish an individual's completion and
            // can inadvertently contain participant information.
            foreach ($imports as $import) {
                $columnMap = $import->column_map ?? [];
                $headers = $columnMap['headers'] ?? null;
                $options = $columnMap['options'] ?? [];
                $import->forceFill([
                    'column_map' => [
                        'erased' => true,
                        'header_count' => is_array($headers) ? count($headers) : null,
                        'header_signature' => is_array($headers) ? CsvImportService::headerSignature($headers) : null,
                        'map' => $columnMap['map'] ?? null,
                        'timestamp_format' => $columnMap['timestamp_format'] ?? null,
                        'required_column_count' => is_array($options['required_columns'] ?? null) ? count($options['required_columns']) : null,
                        'score_mode' => $options['score_mode'] ?? null,
                        'source_description' => $options['source_description'] ?? null,
                    ],
                    'privacy_erased_at' => now(),
                    'status' => $import->status === Import::STATUS_PENDING ? Import::STATUS_CANCELLED : $import->status,
                ])->save();
            }

            return ['imports' => $imports->count(), 'issues' => $issues];
        }, attempts: 3);
    }

    /** Legacy selection logs attached identities to the webinar, not participant. */
    private function eraseLegacySelectionMetadata(int $webinarId): void
    {
        AuditLog::query()->where('auditable_type', (new Webinar)->getMorphClass())
            ->where('auditable_id', $webinarId)->where('action', 'certificate.issued_selection')
            ->whereNotNull('metadata')->chunkById(200, function ($logs): void {
                foreach ($logs as $log) {
                    $metadata = $log->metadata ?? [];
                    if (array_key_exists('skip_reasons', $metadata)) {
                        unset($metadata['skip_reasons']);
                        // Narrow privacy redaction is a system operation. Keep
                        // actor attribution and the event itself immutable.
                        AuditLog::query()->whereKey($log->id)->update(['metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);
                    }
                }
            });
    }
}
