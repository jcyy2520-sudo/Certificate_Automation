<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Import;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Pending choices and review counts; respondent samples never enter this metadata. */
class ImportReviewService
{
    public function choices(Import $import, array $input): array
    {
        $mode = CsvImportService::responseMode($import, $input['response_mode'] ?? 'individual');
        $map = CsvImportService::validateMap($import, $input['map'] ?? [], $mode);
        $format = CsvImportService::validateTimestampFormat($input['timestamp_format'] ?? null);
        $options = CsvImportService::validateOptions($import, $input + ['response_mode' => $mode], $map);

        return ['map' => $map, 'timestamp_format' => $format, 'options' => $options];
    }

    public function save(Import $import, array $choices, ?array $review = null): void
    {
        DB::transaction(function () use ($import, $choices, $review): void {
            $locked = $this->lockPending($import);
            $validated = $this->choices($locked, $choices['options'] + ['map' => $choices['map'], 'timestamp_format' => $choices['timestamp_format']]);
            $metadata = $locked->column_map ?? [];
            $hash = CsvImportService::reviewHash($locked, $validated['map'], $validated['timestamp_format'], $validated['options']);
            if (! hash_equals($metadata['review_hash'] ?? '', $hash)) {
                unset($metadata['review_hash'], $metadata['review_results']);
            }
            if ($review !== null) {
                $metadata['review_hash'] = $hash;
                $metadata['review_results'] = array_intersect_key($review, array_flip(['total', 'valid', 'invalid', 'duplicate', 'unmatched']));
            }
            $locked->update(['column_map' => array_merge($metadata, $validated, ['last_error' => null, 'last_error_at' => null])]);
        }, attempts: 3);
    }

    public function changeMode(Import $import, mixed $mode): void
    {
        DB::transaction(function () use ($import, $mode): void {
            $locked = $this->lockPending($import);
            $mode = CsvImportService::responseMode($locked, $mode);
            $metadata = $locked->column_map ?? [];
            unset($metadata['review_hash'], $metadata['review_results']);
            $metadata['options'] = array_merge($metadata['options'] ?? [], ['response_mode' => $mode, 'source_confirmed' => false]);
            $locked->update(['column_map' => $metadata]);
        }, attempts: 3);
    }

    public function recordFailure(Import $import, string $message): void
    {
        DB::transaction(function () use ($import, $message): void {
            $webinar = Webinar::query()->whereKey($import->webinar_id)->whereNull('deletion_started_at')->sharedLock()->first();
            if (! $webinar || $webinar->retention_due_at?->isPast()) {
                return;
            }
            Form::query()->whereKey($import->form_id)->where('webinar_id', $webinar->id)->sharedLock()->firstOrFail();
            $locked = Import::query()->whereKey($import->id)->where('status', Import::STATUS_PENDING)->whereNull('privacy_erased_at')->lockForUpdate()->first();
            if ($locked) {
                $locked->update(['column_map' => array_merge($locked->column_map ?? [], [
                    'last_error' => mb_substr($message, 0, 500), 'last_error_at' => now()->toIso8601String(),
                ])]);
            }
        }, attempts: 3);
    }

    private function lockPending(Import $import): Import
    {
        $webinar = Webinar::query()->whereKey($import->webinar_id)->whereNull('deletion_started_at')->sharedLock()->firstOrFail();
        $form = Form::query()->whereKey($import->form_id)->where('webinar_id', $webinar->id)->sharedLock()->firstOrFail();
        if ($webinar->retention_due_at?->isPast()) {
            throw new RuntimeException('This webinar is past its privacy retention deadline.');
        }
        $locked = Import::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
        if ($locked->status !== Import::STATUS_PENDING || $locked->privacy_erased_at !== null) {
            throw new RuntimeException('This upload is no longer pending. Refresh the import history.');
        }

        return $locked->setRelation('webinar', $webinar)->setRelation('form', $form);
    }
}
