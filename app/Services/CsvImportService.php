<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use Carbon\CarbonInterface;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

final class CsvImportDryRunComplete extends RuntimeException
{
    /** @param array{total:int,valid:int,invalid:int,duplicate:int,unmatched:int} $counts */
    public function __construct(public readonly array $counts)
    {
        parent::__construct('CSV import dry run complete.');
    }
}

/**
 * Imports an exported Google Forms response file into a webinar's forms.
 *
 * Email address is the only identifier: rows are never matched by name. Rows
 * that cannot attach cleanly are held as ImportIssue records for a human
 * decision instead of being guessed at or discarded.
 */
class CsvImportService
{
    /** Upper bound keeping the single committing transaction short. */
    public const MAX_ROWS = 5000;

    /** Rows shown only during the confirmation step; they are never persisted. */
    public const PREVIEW_ROWS = 5;

    /**
     * Logical columns an upload can provide, keyed to the CSV header that
     * carries them. Which ones are required depends on the target form type.
     *
     * @return array<string, string>
     */
    public static function logicalFields(string $formType): array
    {
        return [
            'email' => 'Email address',
            'full_name' => 'Full name',
            'first_name' => 'First name',
            'middle_name' => 'Middle name (optional)',
            'last_name' => 'Last name',
            'suffix' => 'Suffix (optional)',
            'organization' => 'Organization',
            'score' => 'Score',
            'maximum_score' => 'Maximum score',
            'submitted_at' => 'Submitted at',
        ];
    }

    /**
     * Guess which CSV header carries each field, ordered so specific columns
     * claim their headers before looser ones compete: a quiz "Score" must win,
     * and "Name of School" must lose to the organization field rather than
     * being mistaken for a person's name.
     *
     * @return array<string, int> logical field => header index
     */
    public static function suggestMap(array $headers, string $formType): array
    {
        $patterns = [
            'email' => '/e-?mail/i',
            'maximum_score' => '/^(?:maximum|max|total)[\s_-]*(?:score|marks|points)$|^out of$/i',
            'score' => '/^(?:(?:quiz|test|total|your|final)[\s_-]+)?(?:score|marks|points)$/i',
            'submitted_at' => '/^(?:timestamp|time stamp|submitted at|submission time|submission date|date submitted)$/i',
            'organization' => '/organi[sz]ation|company|institution|affiliation|\bschool\b|\boffice\b|\bdepartment\b|\bhospital\b/i',
            'first_name' => '/^(?:first|given)[\s_-]*name$/i',
            'middle_name' => '/^middle[\s_-]*(?:name|initial)$/i',
            'last_name' => '/^(?:last|sur|family)[\s_-]*name$/i',
            'suffix' => '/^(?:name[\s_-]*)?suffix$/i',
            // Google Forms exports use the visible question title as the CSV
            // header. Accept a clear full-name label followed by an example or
            // formatting instruction, e.g. "Full Name: (Surname, Firstname,
            // MI)", but do not broaden this to generic profile/name fields.
            'full_name' => '/^(?:full[\s_-]*|complete[\s_-]*|your[\s_-]*)?name(?:\s*[:\-–—]\s*.+|\s*\(.+\))?$|(?:what is your|participant|attendee|delegate) (?:full |complete )?name\??$/i',
        ];
        $map = [];
        foreach ($patterns as $field => $pattern) {
            $matches = array_keys(array_filter($headers, fn ($header) => preg_match($pattern, trim($header)) === 1));
            // More than one plausible column is a question for the organizer.
            if (count($matches) === 1 && ! in_array($matches[0], $map, true)) {
                $map[$field] = $matches[0];
            }
        }
        if (isset($map['full_name'])) {
            unset($map['first_name'], $map['middle_name'], $map['last_name'], $map['suffix']);
        }

        return $map;
    }

    /** Individual completion is the default; literal unattributed storage is evaluation-only. */
    public static function responseMode(Import $import, mixed $value): string
    {
        $mode = $value ?? 'individual';
        if (! is_string($mode) || ! in_array($mode, ['individual', 'unattributed'], true)) {
            throw new RuntimeException('Choose individual responses or unattributed evaluations as the response mode.');
        }
        if ($mode === 'unattributed' && $import->form->type !== 'evaluation') {
            throw new RuntimeException('Only an evaluation can be saved without participant attribution.');
        }

        return $mode;
    }

    /** Validate a proposed mapping against the stored headers of a pending import. */
    public static function validateMap(Import $import, array $chosen, string $responseMode = 'individual'): array
    {
        $responseMode = self::responseMode($import, $responseMode);
        $headers = $import->column_map['headers'] ?? [];
        $normalized = [];

        foreach (array_keys(self::logicalFields($import->form->type)) as $field) {
            $index = $chosen[$field] ?? null;

            if ($index === null || $index === '') {
                continue;
            }

            if (! ctype_digit((string) $index) || ! array_key_exists((int) $index, $headers)) {
                throw new RuntimeException('The chosen column for '.$field.' is not part of the uploaded file.');
            }

            $normalized[$field] = (int) $index;
        }

        if ($responseMode === 'individual' && ! isset($normalized['email'])) {
            throw new RuntimeException('Map the email address column before importing.');
        }

        $hasCombinedName = isset($normalized['full_name'])
            || (isset($normalized['first_name']) && isset($normalized['last_name']));
        if ($import->form->type === 'registration' && ! $hasCombinedName) {
            throw new RuntimeException('A registration import needs a name column so participants can be created.');
        }

        if (count(array_unique($normalized)) !== count($normalized)) {
            throw new RuntimeException('A source column cannot be used for more than one field.');
        }
        if ($responseMode === 'unattributed') {
            return $normalized;
        }
        $parts = array_intersect_key($normalized, array_flip(['first_name', 'middle_name', 'last_name', 'suffix']));
        if (isset($normalized['full_name']) && $parts !== []) {
            throw new RuntimeException('Choose either the full name column or separate name parts, not both.');
        }
        if ($parts !== [] && (! isset($normalized['first_name']) || ! isset($normalized['last_name']))) {
            throw new RuntimeException('Separate names require both first name and last name columns.');
        }

        return $normalized;
    }

    /** A slash date is accepted only when the administrator has declared its order. */
    public static function validateTimestampFormat(?string $format): string
    {
        $format ??= 'auto';
        if (! in_array($format, ['auto', 'month_day_year', 'day_month_year', 'iso_8601'], true)) {
            throw new RuntimeException('Choose a supported timestamp format.');
        }

        return $format;
    }

    /** Normalize all organizer choices before either a dry check or a commit. */
    public static function validateOptions(Import $import, array $input, array $map = []): array
    {
        $responseMode = self::responseMode($import, $input['response_mode'] ?? null);
        $headers = $import->column_map['headers'] ?? [];
        $required = $input['required_columns'] ?? [];
        if (! is_array($required)) {
            throw new RuntimeException('Choose the required answer columns from this file.');
        }
        foreach ($required as $index) {
            if (! ctype_digit((string) $index) || ! array_key_exists((int) $index, $headers)) {
                throw new RuntimeException('A required answer column is not part of this file.');
            }
        }
        $required = array_values(array_unique(array_map('intval', $required)));
        sort($required);
        // The selected map is passed in from both the dry run and final commit.
        // Do not trust an earlier dry-run map when the administrator has just
        // changed columns on this request.
        $scoreEvidence = in_array($import->form->type, ['pretest', 'posttest'], true)
            && array_key_exists('score', $map !== [] ? $map : ($import->column_map['map'] ?? []));
        if ($responseMode === 'individual' && $import->form->type !== 'registration' && $required === [] && ! $scoreEvidence) {
            throw new RuntimeException('Select at least one required answer column for this assessment or evaluation export. Summary-only imports cannot establish certificate completion.');
        }
        // Completion must be supported by answers from this file, or by a
        // mapped Google Quiz score for a pre/post test. An organizer statement
        // cannot prove that an individual submitted a form and is never used
        // as a completion signal.
        // Wrong-form imports are the highest-identity risk: the system cannot
        // prove a CSV belongs to the selected form. Require the operator to
        // identify the Google Form source and explicitly confirm the destination.
        $sourceDescription = trim((string) ($input['source_description'] ?? ''));
        if (mb_strlen($sourceDescription) < 5 || mb_strlen($sourceDescription) > 200) {
            throw new RuntimeException('Identify the Google Form source (title/export date or version, 5–200 characters) so the destination can be audited.');
        }
        $sourceConfirmed = in_array($input['source_confirmed'] ?? false, [true, 1, '1'], true);
        if (! $sourceConfirmed) {
            throw new RuntimeException('Confirm this file is the claimed Google Form export for the selected destination form before importing.');
        }
        $mode = $input['score_mode'] ?? 'points';
        if (! in_array($mode, ['points', 'percentage'], true)) {
            throw new RuntimeException('Choose points or percentage as the score format.');
        }
        $maximumText = trim((string) ($input['default_maximum_score'] ?? ''));
        $maximum = $maximumText === '' ? null : CsvImportRowParser::decimal($maximumText);
        if ($maximumText !== '' && ($maximum === null || $maximum <= 0)) {
            throw new RuntimeException('The declared maximum must be positive with at most two decimal places.');
        }
        $timezone = $input['timestamp_timezone'] ?? $import->webinar->timezone;
        if (! is_string($timezone) || ! in_array($timezone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)) {
            throw new RuntimeException('Choose a valid timezone for timestamps in this export.');
        }

        $answerMap = $input['answer_map'] ?? [];
        if (! is_array($answerMap)) {
            throw new RuntimeException('Choose question columns from this file.');
        }
        $columns = app(FormResponseService::class)->nativeColumns($import->form);
        $answerMap = array_filter($answerMap, fn ($index) => $index !== null && $index !== '');
        foreach ($answerMap as $key => $index) {
            if (! isset($columns[$key]) || ! ctype_digit((string) $index) || ! array_key_exists((int) $index, $headers)) {
                throw new RuntimeException('A mapped question or column does not belong to this form/export.');
            }
            $answerMap[$key] = (int) $index;
        }
        if (count(array_unique($answerMap)) !== count($answerMap) || array_intersect(array_values($answerMap), array_values($map)) !== []) {
            throw new RuntimeException('Map each answer column once, separately from participant, score and timestamp columns.');
        }
        ksort($answerMap);

        return [
            'response_mode' => $responseMode,
            'required_columns' => $required,
            'answer_map' => $answerMap,
            'source_description' => $sourceDescription,
            'source_confirmed' => $sourceConfirmed,
            'score_mode' => $mode,
            'default_maximum_score' => $maximum,
            'timestamp_timezone' => $timezone,
            'allow_import_time' => in_array($input['allow_import_time'] ?? false, [true, 1, '1'], true),
        ];
    }

    public static function reviewHash(Import $import, array $map, string $format, array $options): string
    {
        ksort($map);

        return hash('sha256', json_encode([$import->id, $import->form_id, $import->file_hash, $map, $format, $options], JSON_THROW_ON_ERROR));
    }

    /**
     * Stable identity for an export's column layout. Normalized (case,
     * whitespace) so trivial re-exports match while a changed questionnaire,
     * a different form, or a reordered export is flagged for review.
     */
    public static function headerSignature(array $headers): string
    {
        $normalized = array_map(
            fn ($header) => Str::lower((string) preg_replace('/\s+/u', ' ', trim((string) $header))),
            $headers
        );

        return hash('sha256', json_encode(array_values($normalized), JSON_THROW_ON_ERROR));
    }

    /**
     * Persist a pending import plus its temporary file, ready for the mapping
     * confirmation step. The upload lives outside any tracked disk precisely
     * because it is transient personal data.
     */
    public function storePending(Webinar $webinar, Form $form, UploadedFile $file): Import
    {
        $contents = app(ResponseFileReader::class)->contents($file);
        $headers = $this->headersFromContents($contents);

        if ($headers === []) {
            throw new RuntimeException('The file does not contain a readable CSV header row.');
        }

        $rows = $this->rowsFromContents($contents);
        $analysis = app(ImportedResponseSchema::class)->analyze($headers, $rows);
        $map = self::suggestMap($headers, $form->type);
        $suggestedAnswers = app(ImportedResponseSchema::class)->suggestAnswers($form, $headers, $map);
        // Commit the inventory before performing filesystem I/O. A later
        // storage or database failure must leave a discoverable source path.
        $import = DB::transaction(function () use ($webinar, $form, $file, $headers, $map, $suggestedAnswers, $analysis, $contents): Import {
            $lockedWebinar = Webinar::query()->whereKey($webinar->id)->whereNull('deletion_started_at')->lockForUpdate()->firstOrFail();
            Form::query()->whereKey($form->id)->where('webinar_id', $lockedWebinar->id)->lockForUpdate()->firstOrFail();
            if ($lockedWebinar->retention_due_at?->isPast()) {
                throw new RuntimeException('This webinar is past its privacy retention deadline.');
            }

            /** @var Import $import */
            return Import::query()->create([
                'webinar_id' => $webinar->id,
                'form_id' => $form->id,
                'status' => Import::STATUS_PENDING,
                'original_filename' => $file->getClientOriginalName(),
                'file_hash' => hash_file('sha256', $file->getRealPath()),
                'file_size' => $file->getSize(),
                'column_map' => [
                    'headers' => $headers,
                    'map' => $map,
                    'suggested_answers' => $suggestedAnswers,
                    'analysis' => $analysis,
                    'format' => strtolower($file->getClientOriginalExtension()),
                    'normalized_hash' => hash('sha256', $contents),
                ],
                'imported_by' => auth()->id(),
            ]);
        }, attempts: 3);

        $failure = null;
        DB::transaction(function () use ($import, $contents, &$failure): void {
            $failure = null;
            // Serialize writing against webinar deletion. If deletion won the
            // gap after inventory creation, stop before writing any file.
            $webinar = Webinar::query()->whereKey($import->webinar_id)->whereNull('deletion_started_at')->lockForUpdate()->firstOrFail();
            $locked = Import::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== Import::STATUS_PENDING || $webinar->retention_due_at?->isPast()) {
                throw new RuntimeException('This upload can no longer accept a source file.');
            }
            try {
                if (! Storage::disk('local')->put($this->tmpPath($import), $contents)) {
                    throw new RuntimeException('The private upload could not be stored. Try again.');
                }
            } catch (\Throwable $exception) {
                $failure = $exception;
                try {
                    ImportSourceFile::delete($import->id);
                    $import->delete();
                } catch (RuntimeException $cleanupFailure) {
                    $import->update(['status' => Import::STATUS_FAILED, 'column_map' => array_merge($import->column_map, ['cleanup_pending' => true])]);
                    $failure = $cleanupFailure;
                }
            }

        }, attempts: 3);
        if ($failure) {
            throw $failure;
        }

        return $import;
    }

    /**
     * Read a small, ephemeral sample directly from the pending upload. This is
     * intentionally not stored on the Import model because response values are PII.
     *
     * @return array<int, array{row_number:int,cells:array<int,string>,column_count:int}>
     */
    public function previewRows(Import $import, int $page = 1): array
    {
        $page = max(1, min($page, (int) ceil(self::MAX_ROWS / self::PREVIEW_ROWS)));
        $rows = array_slice($this->readRows(Storage::disk('local')->path($this->tmpPath($import)), self::PREVIEW_ROWS * $page),
            ($page - 1) * self::PREVIEW_ROWS, self::PREVIEW_ROWS, true);

        return collect($rows)
            ->map(fn (array $cells, int $rowNumber) => [
                'row_number' => $rowNumber,
                'cells' => $cells,
                'column_count' => count($cells),
            ])
            ->values()
            ->all();
    }

    public function previewRowCount(Import $import): int
    {
        // Current imports already retain an aggregate count, never source values.
        return $import->column_map['analysis']['rows']
            ?? count($this->readRows(Storage::disk('local')->path($this->tmpPath($import))));
    }

    /**
     * Apply the confirmed mapping: every clean row becomes a submission on the
     * participant master list, every problem row becomes a reviewable issue.
     * Runs in one transaction so an import either lands whole or not at all.
     *
     * @param  array<string, int>  $map  logical field => header index
     * @return array{total:int, valid:int, invalid:int, duplicate:int, unmatched:int}
     */
    public function process(Import $import, array $map, string $timestampFormat = 'auto', bool $dryRun = false, array $options = []): array
    {
        $responseMode = self::responseMode($import, $options['response_mode'] ?? null);
        $map = self::validateMap($import, $map, $responseMode);
        $timestampFormat = self::validateTimestampFormat($timestampFormat);
        $options = self::validateOptions($import, $options, $map);
        if (array_intersect($options['required_columns'], array_values($map)) !== []) {
            throw new RuntimeException('Required answers must be question columns, separate from identity, score, and timestamp columns.');
        }
        $path = Storage::disk('local')->path($this->tmpPath($import));
        if (! is_file($path) || ! hash_equals($import->column_map['normalized_hash'] ?? $import->file_hash, hash_file('sha256', $path))) {
            throw new RuntimeException('The pending file is missing or changed. Upload the original file again.');
        }
        $rows = $this->readRows($path);
        if (count($rows) > self::MAX_ROWS) {
            throw new RuntimeException('The file holds more than '.self::MAX_ROWS.' response rows. Split it into smaller files.');
        }

        try {
            $counts = DB::transaction(function () use ($dryRun, $import, $map, $rows, $timestampFormat, $options, $responseMode): array {
                $lockedWebinar = Webinar::query()->whereKey($import->webinar_id)->whereNull('deletion_started_at')->sharedLock()->firstOrFail();
                $lockedForm = Form::query()->whereKey($import->form_id)->where('webinar_id', $lockedWebinar->id)->sharedLock()->firstOrFail();
                $lockedForm->setRelation('webinar', $lockedWebinar);
                $lockedForm->load(['fields', 'questions.choices']);
                // Serialize retries of the same upload before creating any participants.
                $import = Import::query()->whereKey($import->id)->lockForUpdate()->firstOrFail();
                if ($import->status !== Import::STATUS_PENDING) {
                    throw new RuntimeException('This upload is no longer pending. Its responses were not imported again.');
                }
                $import->setRelation('form', $lockedForm);
                // Revalidate against the locked, current form after a concurrent edit.
                self::validateOptions($import, $options, $map);
                $import->column_map = array_merge($import->column_map ?? [], ['map' => $map]);
                $nativeColumns = app(FormResponseService::class)->nativeColumns($lockedForm);
                $responseSchema = $responseMode === 'unattributed'
                    ? []
                    : app(ImportedResponseSchema::class)->columns($import, $map, $options);
                if ($responseMode === 'unattributed') {
                    $metadata = $import->column_map;
                    $metadata['analysis']['columns'] = array_map(fn ($column) => array_diff_key($column, ['label' => true]), $metadata['analysis']['columns'] ?? []);
                    $import->column_map = $metadata;
                }
                $import->column_map = array_merge($import->column_map, [
                    'response_schema' => $responseSchema,
                ]);
                if ($lockedWebinar->retention_due_at !== null && $lockedWebinar->retention_due_at->isPast()) {
                    throw new RuntimeException('This webinar is past its privacy retention deadline.');
                }
                $counts = ['total' => count($rows), 'valid' => 0, 'invalid' => 0, 'duplicate' => 0, 'unmatched' => 0];
                $headers = $import->column_map['headers'] ?? [];
                $registration = $lockedForm->type === 'registration';
                $scoreRequired = $lockedWebinar->eligibilityRules()->where('requirement', $lockedForm->type)->where('is_required', true)->whereNotNull('minimum_score')->exists();
                $parser = new CsvImportRowParser;
                $candidates = [];
                foreach ($rows as $rowNumber => $row) {
                    if ($responseMode === 'unattributed') {
                        if (count($row) !== count($headers)) {
                            $raw = $this->rawDataFor($row, $headers, $map, $options['required_columns'] ?? []);
                            $this->issue($import, $rowNumber, ImportIssue::TYPE_INVALID_ROW_STRUCTURE, 'The number of cells does not match the header. Re-export the original file.', $raw, null);
                            $counts['invalid']++;

                            continue;
                        }
                        app(UnattributedResponseService::class)->store($import, $rowNumber, $headers, $row);
                        $counts['valid']++;

                        continue;
                    }
                    $raw = $this->rawDataFor($row, $headers, $map, $options['required_columns'] ?? []);
                    try {
                        $parsed = $parser->parse($row, $headers, $map, $options, $timestampFormat, $registration, $scoreRequired);
                        $this->validateMappedAnswers($nativeColumns, $row, $options);
                    } catch (CsvImportValidationException $exception) {
                        $email = isset($map['email'], $row[$map['email']]) ? Str::lower(trim((string) $row[$map['email']])) : null;
                        $this->issue($import, $rowNumber, $exception->issueType, $exception->getMessage(), $raw, $email);
                        $counts['invalid']++;

                        continue;
                    }
                    $candidates[$parsed['email']][] = ['row' => $rowNumber, 'raw' => $raw, 'parsed' => $parsed];
                }

                foreach ($candidates as $email => $entries) {
                    $logicalHeaders = array_flip(array_map(fn ($index) => $headers[$index], array_values($map)));
                    $fingerprints = array_map(fn ($entry) => json_encode([$entry['parsed'], array_diff_key($entry['raw'], $logicalHeaders)], JSON_THROW_ON_ERROR), $entries);
                    if (count(array_unique($fingerprints)) > 1) {
                        foreach ($entries as $entry) {
                            $this->issue($import, $entry['row'], ImportIssue::TYPE_DUPLICATE_IN_FILE, 'Conflicting valid responses share this email. None was selected. Keep the organizer-approved response in a corrected export.', $entry['raw'], $email);
                            $counts['duplicate']++;
                        }

                        continue;
                    }
                    $entry = array_shift($entries);
                    foreach ($entries as $duplicate) {
                        $this->issue($import, $duplicate['row'], ImportIssue::TYPE_DUPLICATE_IN_FILE, 'Identical response repeated; row '.$entry['row'].' was selected.', $duplicate['raw'], $email);
                        $counts['duplicate']++;
                    }
                    $rowNumber = $entry['row'];
                    $raw = $entry['raw'];
                    $data = $entry['parsed'];
                    $participant = Participant::withTrashed()->where('webinar_id', $lockedWebinar->id)->where('email_normalized', $email)->lockForUpdate()->first();
                    if ($participant && ($participant->privacy_erased_at !== null || $participant->trashed())) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_DUPLICATE_EXISTING, 'An erased or removed record holds this email address; it was left untouched.', $raw, $email);
                        $counts['duplicate']++;

                        continue;
                    }
                    if ($participant && filled($participant->full_name) && filled($data['name']) && CsvImportRowParser::nameKey($participant->full_name) !== CsvImportRowParser::nameKey($data['name'])) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_NAME_CONFLICT, 'This email has a different registered name. Verify the identity and correct the participant or source before uploading again.', $raw, $email);
                        $counts['invalid']++;

                        continue;
                    }
                    if (! $participant && filled($data['name']) && $lockedWebinar->participants()->whereRaw('LOWER(full_name) = ?', [CsvImportRowParser::nameKey($data['name'])])->exists()) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_IDENTITY_CONFLICT, 'The same name already exists under another email. Verify whether these are different people; no identities were merged.', $raw, $email);
                        $counts['invalid']++;

                        continue;
                    }
                    if (! $participant && ! $registration) {
                        $resolved = $this->withResolvedPayload($raw, $data['score'], $data['maximum_score'], $data['submitted_at'], $data['name']);
                        $resolved['_resolved']['organization'] = $data['organization'];
                        $resolved['_resolved']['options'] = $options;
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_UNMATCHED_EMAIL, 'No matching participant. Add the confirmed participant or import registration first, then attach this response. Certificate requirements still apply.', $resolved, $email);
                        $counts['unmatched']++;

                        continue;
                    }
                    if ($participant && Submission::query()->where('form_id', $lockedForm->id)->where('participant_id', $participant->id)->exists()) {
                        $resolved = $this->withResolvedPayload($raw, $data['score'], $data['maximum_score'], $data['submitted_at'], $data['name']);
                        $resolved['_resolved']['options'] = $options;
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_DUPLICATE_EXISTING, 'A response is already recorded for this form. It was not overwritten. Review the existing response before any correction.', $resolved, $email);
                        $counts['duplicate']++;

                        continue;
                    }
                    if (! $participant) {
                        $participant = Participant::query()->create(['webinar_id' => $lockedWebinar->id, 'email' => $email, 'full_name' => $data['name'], 'organization' => $data['organization'], 'verified_at' => now()]);
                    } else {
                        if ($registration && $participant->verified_at === null) {
                            $participant->verified_at = now();
                        }
                        if (blank($participant->full_name) && filled($data['name'])) {
                            $participant->full_name = $data['name'];
                        }
                        if (blank($participant->organization) && filled($data['organization'])) {
                            $participant->organization = $data['organization'];
                        }
                        $participant->save();
                    }
                    $this->attachSubmission($lockedForm, $participant, $data['score'], $data['maximum_score'], $data['submitted_at'], $import, $rowNumber, $options, $raw, $nativeColumns, $responseSchema);
                    $counts['valid']++;
                }
                $import->forceFill([
                    'status' => Import::STATUS_COMMITTED,
                    'column_map' => array_merge($import->column_map ?? [], ['map' => $map, 'timestamp_format' => $timestampFormat, 'options' => $options, 'cleanup_pending' => true]),
                    'total_rows' => $counts['total'], 'valid_rows' => $counts['valid'], 'invalid_rows' => $counts['invalid'],
                    'duplicate_rows' => $counts['duplicate'], 'unmatched_rows' => $counts['unmatched'], 'committed_at' => now(),
                ])->save();
                if ($dryRun) {
                    $counts['details'] = $import->issues()->orderBy('row_number')->limit(100)->get(['row_number', 'issue_type', 'issue_message'])->toArray();
                    $counts['sample'] = Submission::query()->where('form_id', $lockedForm->id)->where('metadata->import_id', $import->id)->with('participant')->limit(self::PREVIEW_ROWS)->get()->map(fn ($submission) => [
                        'row' => $submission->metadata['import_row_number'], 'name' => $submission->participant->full_name, 'email' => $submission->participant->email,
                        'score' => $submission->score, 'maximum_score' => $submission->maximum_score, 'submitted_at' => $submission->submitted_at?->toIso8601String(),
                    ])->all();
                    throw new CsvImportDryRunComplete($counts);
                }

                return $counts;
            }, attempts: 3);
        } catch (CsvImportDryRunComplete $complete) {
            $counts = $complete->counts;
        }
        if (! $dryRun) {
            try {
                ImportSourceFile::delete($import->id);
                $import->refresh()->update(['column_map' => array_merge($import->column_map ?? [], ['cleanup_pending' => false])]);
            } catch (RuntimeException) {
                // The response transaction is already committed. Do not report
                // it as failed or invite a duplicate import. The scheduled
                // cleanup retries the durable marker independently.
                $counts['cleanup_pending'] = true;
            }
        }

        return $counts;
    }

    /**
     * Act on a held-for-review row: create the participant it describes and
     * attach the response that was waiting for a decision.
     */
    public function promote(ImportIssue $issue, User $administrator, ?string $confirmedName = null): Participant
    {
        if ($issue->resolved_at !== null || $issue->issue_type !== ImportIssue::TYPE_UNMATCHED_EMAIL) {
            throw new RuntimeException('Only an unresolved unmatched row can be promoted.');
        }

        return DB::transaction(function () use ($issue, $administrator, $confirmedName): Participant {
            $import = $issue->import()->with('form')->firstOrFail();
            $lockedWebinar = Webinar::query()
                ->whereKey($issue->webinar_id)
                ->whereNull('deletion_started_at')
                ->sharedLock()
                ->firstOrFail();
            $lockedForm = Form::query()
                ->whereKey($import->form_id)
                ->where('webinar_id', $lockedWebinar->id)
                ->sharedLock()
                ->firstOrFail();

            $email = (string) $issue->normalized_email;
            $resolved = $issue->raw_data['_resolved'] ?? [];
            $name = trim((string) ($confirmedName ?? $resolved['name'] ?? ''));
            if ($name === '' || mb_strlen($name) > 180 || preg_match('/[\p{L}]/u', $name) !== 1 || str_contains($name, '@')) {
                throw new RuntimeException('Confirm a complete certificate name before adding this participant.');
            }
            if ($lockedWebinar->retention_due_at?->isPast()) {
                throw new RuntimeException('This webinar is past its privacy retention deadline.');
            }

            $participant = Participant::withTrashed()
                ->where('webinar_id', $lockedWebinar->id)
                ->where('email_normalized', $email)
                ->lockForUpdate()
                ->first();

            if ($participant === null) {
                if ($lockedWebinar->participants()->whereRaw('LOWER(full_name) = ?', [CsvImportRowParser::nameKey($name)])->exists()) {
                    throw new RuntimeException('This name already exists under another email. Verify the identities in Participants before adding another person.');
                }
                $participant = Participant::query()->create([
                    'webinar_id' => $lockedWebinar->id,
                    'email' => $email,
                    'full_name' => $name,
                    'organization' => $resolved['organization'] ?? null,
                ]);

            }
            if ($participant->privacy_erased_at !== null || $participant->trashed()) {
                throw new RuntimeException('A removed participant cannot be restored by an import.');
            }
            if (filled($participant->full_name) && CsvImportRowParser::nameKey($participant->full_name) !== CsvImportRowParser::nameKey($name)) {
                throw new RuntimeException('The confirmed name conflicts with the existing participant. Correct the identity before attaching the response.');
            }
            $issue = ImportIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();
            if ($issue->resolved_at !== null) {
                throw new RuntimeException('This row has already been resolved.');
            }

            $already = Submission::query()
                ->where('form_id', $lockedForm->id)
                ->where('participant_id', $participant->id)
                ->exists();

            if (! $already) {
                $this->attachSubmission(
                    $lockedForm,
                    $participant,
                    isset($resolved['score']) ? (float) $resolved['score'] : null,
                    isset($resolved['maximum_score']) ? (float) $resolved['maximum_score'] : null,
                    isset($resolved['submitted_at']) ? Carbon::parse((string) $resolved['submitted_at']) : null,
                    $import,
                    $issue->row_number,
                    $resolved['options'] ?? [],
                    $issue->raw_data ?? [],
                );
            }

            $issue->forceFill([
                'resolved_at' => now(),
                'resolved_by' => $administrator->id,
                'resolution' => $already
                    ? 'Participant already existed; the response was already recorded.'
                    : 'Participant '.$name.' was added and the response attached. Normal certificate requirements apply.',
            ])->save();

            return $participant;
        }, attempts: 3);
    }

    /** Explicit, audited correction; preserve the original response and block stale certificates. */
    public function replaceResponse(ImportIssue $issue, User $administrator, string $reason): Submission
    {
        return DB::transaction(function () use ($issue, $administrator, $reason): Submission {
            $webinar = Webinar::query()->whereKey($issue->webinar_id)->whereNull('deletion_started_at')->sharedLock()->firstOrFail();
            if ($webinar->retention_due_at?->isPast()) {
                throw new RuntimeException('This webinar is past its privacy retention deadline.');
            }
            $import = $issue->import;
            $form = Form::query()->whereKey($import->form_id)->where('webinar_id', $webinar->id)->sharedLock()->firstOrFail();
            $participant = Participant::query()->where('webinar_id', $webinar->id)->where('email_normalized', $issue->normalized_email)->whereNull('privacy_erased_at')->lockForUpdate()->firstOrFail();
            $issue = ImportIssue::query()->whereKey($issue->id)->lockForUpdate()->firstOrFail();
            $data = $issue->raw_data['_resolved'] ?? null;
            if ($issue->resolved_at !== null || $issue->issue_type !== ImportIssue::TYPE_DUPLICATE_EXISTING || ! is_array($data) || ! isset($data['options'])) {
                throw new RuntimeException('Only a validated, unresolved imported response can replace an earlier import.');
            }
            if (filled($data['name']) && CsvImportRowParser::nameKey($participant->full_name ?? '') !== CsvImportRowParser::nameKey($data['name'])) {
                throw new RuntimeException('The participant name changed. Verify the identity and upload the correction again.');
            }
            if ($participant->certificates()->whereNull('revoked_at')->whereIn('status', ['processing', 'issued'])->exists()) {
                throw new RuntimeException('Revoke the active certificate before correcting a response, then reissue after checking eligibility.');
            }
            $previous = Submission::query()->where('form_id', $form->id)->where('participant_id', $participant->id)->where('status', 'submitted')->lockForUpdate()->get();
            if ($previous->isEmpty() || $previous->contains(fn ($submission) => ($submission->metadata['source'] ?? null) !== 'csv_import')) {
                throw new RuntimeException('This action only replaces CSV imports. Live form responses cannot be replaced here.');
            }
            foreach ($previous as $submission) {
                $submission->update(['status' => 'superseded', 'metadata' => array_merge($submission->metadata ?? [], ['superseded_by_import' => $import->id])]);
            }
            $submission = $this->attachSubmission($form, $participant, $data['score'], $data['maximum_score'], isset($data['submitted_at']) ? Carbon::parse($data['submitted_at']) : null, $import, $issue->row_number, $data['options'], $issue->raw_data ?? []);
            $issue->update(['resolved_at' => now(), 'resolved_by' => $administrator->id, 'resolution' => 'Replaced earlier imported response; original preserved. Reason: '.trim($reason)]);

            return $submission;
        }, attempts: 3);
    }

    /** Record an administrator's decision to leave a held row out. */
    public function dismiss(ImportIssue $issue, User $administrator): void
    {
        if ($issue->resolved_at !== null) {
            return;
        }

        $issue->forceFill([
            'resolved_at' => now(),
            'resolved_by' => $administrator->id,
            'resolution' => 'Dismissed without action.',
        ])->save();
    }

    /** Cancel a pending upload and remove its temporary file. */
    public function cancel(Import $import): void
    {
        DB::transaction(function () use ($import): void {
            Webinar::query()->whereKey($import->webinar_id)->lockForUpdate()->first();
            $locked = Import::query()->whereKey($import->id)->lockForUpdate()->first();
            if ($locked?->status !== Import::STATUS_PENDING) {
                return;
            }
            ImportSourceFile::delete($locked->id);
            $locked->forceFill(['status' => Import::STATUS_CANCELLED])->save();
        });
    }

    /** Pending uploads abandoned for a day are cancelled and their files dropped. */
    public function pruneStalePending(): int
    {
        $count = 0;
        $failed = 0;
        $stale = Import::query()->where(function ($query): void {
            $query->where('column_map->cleanup_pending', true)
                ->orWhere('created_at', '<', now()->subDay());
        })->cursor();
        foreach ($stale as $import) {
            try {
                if ($import->status === Import::STATUS_PENDING) {
                    // A fresh confirmed import may set its cleanup marker only
                    // inside a transaction; pending uploads still get a full day.
                    if ($import->created_at->lt(now()->subDay())) {
                        $this->cancel($import);
                        $count++;
                    }

                    continue;
                }
                ImportSourceFile::delete($import->id);
                if ($import->column_map['cleanup_pending'] ?? false) {
                    $import->update(['column_map' => array_merge($import->column_map, ['cleanup_pending' => false])]);
                }
            } catch (RuntimeException) {
                $failed++;
                Log::warning('Private import cleanup requires retry.', ['import_id' => $import->id, 'webinar_id' => $import->webinar_id]);
            }
        }
        if ($failed > 0) {
            throw new RuntimeException($count.' stale pending import(s) cancelled; '.$failed.' private source cleanup(s) failed and remain tracked for retry.');
        }

        return $count;
    }

    /** Attach one imported response to the participant master list. */
    private function attachSubmission(
        Form $form,
        Participant $participant,
        ?float $score,
        ?float $maximumScore,
        ?CarbonInterface $submittedAt,
        Import $import,
        ?int $rowNumber = null,
        array $options = [],
        array $raw = [],
        ?array $nativeColumns = null,
        ?array $responseSchema = null,
    ): Submission {
        $attempt = (int) Submission::query()
            ->where('form_id', $form->id)
            ->where('participant_id', $participant->id)
            ->max('attempt_number');

        $headers = $import->column_map['headers'] ?? [];
        $original = [];
        foreach ($headers as $label) {
            $original[] = ['label' => $label, 'value' => (string) ($raw[$label] ?? '')];
        }
        $sourceIdentity = [];
        foreach (['full_name' => 'name', 'email' => 'email', 'submitted_at' => 'submitted_at', 'score' => 'score'] as $field => $key) {
            $index = $import->column_map['map'][$field] ?? null;
            if ($index !== null && isset($headers[$index])) {
                $sourceIdentity[$key] = (string) ($raw[$headers[$index]] ?? '');
            }
        }
        $mapped = [];
        $columns = $nativeColumns ?? app(FormResponseService::class)->nativeColumns($form);
        $storedSchema = $import->column_map['response_schema'] ?? null;
        foreach ($options['answer_map'] ?? [] as $key => $index) {
            // A held row may be resolved after an unanswered question was
            // retired or changed. Never attach its old meaning to a new type.
            $stored = $storedSchema[$key] ?? null;
            if (! isset($columns[$key]) || ($stored !== null && ($stored['type'] !== $columns[$key]['type'] || $stored['options'] !== $columns[$key]['options']))) {
                throw new RuntimeException('A mapped question changed or was removed after this import. Upload the reviewed response again and confirm its current mapping.');
            }
            $value = (string) ($raw[$headers[$index]] ?? '');
            $mapped[$key] = $this->mappedAnswerValues($columns[$key], $value);
        }

        $submission = Submission::query()->create([
            'form_id' => $form->id,
            'participant_id' => $participant->id,
            'attempt_number' => $attempt + 1,
            'status' => 'submitted',
            'submitted_at' => $submittedAt ?? now(),
            'score' => $score,
            'maximum_score' => $maximumScore,
            'imported_data' => [
                'original' => $original, 'identity' => $sourceIdentity, 'mapped' => $mapped,
                'schema' => $responseSchema ?? $storedSchema ?? app(ImportedResponseSchema::class)->columns($import->setRelation('form', $form), $import->column_map['map'] ?? [], $options),
            ],
            'metadata' => array_filter([
                'source' => 'csv_import',
                'import_id' => $import->id,
                'import_row_number' => $rowNumber,
                'timestamp_source' => $submittedAt === null ? 'import_time' : 'source',
                'completion_check' => ($options['required_columns'] ?? []) !== [] ? 'required_answers' : 'validated_quiz_score',
                'source_description' => filled($options['source_description'] ?? null) ? mb_substr((string) $options['source_description'], 0, 200) : null,
            ], fn (mixed $value): bool => $value !== null),
        ]);
        foreach ($mapped as $key => $values) {
            [$kind, $id] = explode('_', $key, 2);
            $value = ($columns[$key]['type'] ?? '') === 'checkbox' ? $values : ($values[0] ?? null);
            if ($kind === 'question' && ($columns[$key]['type'] ?? 'text') !== 'text') {
                $choice = array_search($value, $columns[$key]['options'], true);
                $value = $choice === false ? null : $choice;
            }
            $submission->answers()->create([
                'form_field_id' => $kind === 'field' ? (int) $id : null,
                'question_id' => $kind === 'question' ? (int) $id : null,
                'value' => [$value], 'is_correct' => null, 'awarded_points' => null,
            ]);
        }

        return $submission;
    }

    private function validateMappedAnswers(array $columns, array $row, array $options): void
    {
        foreach ($options['answer_map'] ?? [] as $key => $index) {
            $this->mappedAnswerValues($columns[$key], (string) ($row[$index] ?? ''));
        }
    }

    /** Validation, saved answers and analytics must use the same interpretation. */
    private function mappedAnswerValues(array $column, string $original): array
    {
        $text = trim($original);
        if ($column['required'] && $text === '') {
            throw new CsvImportValidationException(ImportIssue::TYPE_INCOMPLETE_RESPONSE, 'Required mapped answer is blank: '.$column['label']);
        }
        if (! in_array($column['type'], ['radio', 'select', 'checkbox', 'multiple_choice', 'true_false', 'rating'], true)) {
            return [$original];
        }
        if ($text === '') {
            return $column['type'] === 'checkbox' ? [] : [''];
        }
        $options = array_values(array_unique(array_map('strval', array_values($column['options']))));
        $values = $column['type'] === 'checkbox' ? $this->checkboxValues($text, $options, $column['label']) : [$text];
        if ($values === [] || array_diff($values, $options) !== []) {
            throw new CsvImportValidationException(ImportIssue::TYPE_INCOMPLETE_RESPONSE, 'Unrecognized choice for '.$column['label'].'. Match the form choices exactly or leave this column unmapped for original-only review.');
        }

        return $values;
    }

    /** Google Forms joins checkbox labels with commas, which can also occur inside a label. */
    private function checkboxValues(string $text, array $options, string $label): array
    {
        if (! str_contains(implode('', $options), ',')) {
            return array_map('trim', explode(',', $text));
        }
        // Count at most two interpretations per offset. This stays bounded by
        // cell length and declared choices rather than exploring every partition.
        $length = strlen($text);
        $paths = [0 => ['count' => 1]];
        for ($offset = 0; $offset < $length; $offset++) {
            if (! isset($paths[$offset])) {
                continue;
            }
            foreach ($options as $option) {
                $end = $offset + strlen($option);
                if ($option === '' || $end > $length || substr_compare($text, $option, $offset, strlen($option)) !== 0
                    || ($end < $length && $text[$end] !== ',')) {
                    continue;
                }
                $next = $end === $length ? $length : $end + 1;
                while ($next < $length && ctype_space($text[$next])) {
                    $next++;
                }
                if ($end < $length && $next === $length) {
                    continue;
                }
                $paths[$next] = ['count' => min(2, ($paths[$next]['count'] ?? 0) + $paths[$offset]['count']),
                    'previous' => $offset, 'value' => $option];
            }
        }
        if (($paths[$length]['count'] ?? 0) > 1) {
            throw new CsvImportValidationException(ImportIssue::TYPE_INCOMPLETE_RESPONSE, 'Ambiguous checkbox selections for '.$label.'. Commas can be part of a choice or separate choices. Leave this column unmapped to preserve the original text for review.');
        }
        if (! isset($paths[$length])) {
            return [];
        }
        $values = [];
        for ($offset = $length; $offset > 0; $offset = $paths[$offset]['previous']) {
            $values[] = $paths[$offset]['value'];
        }

        return array_reverse($values);
    }

    private function issue(Import $import, int $rowNumber, string $type, string $message, array $raw, ?string $email): void
    {
        ImportIssue::query()->create([
            'import_id' => $import->id,
            'webinar_id' => $import->webinar_id,
            'row_number' => $rowNumber,
            'issue_type' => $type,
            'issue_message' => mb_substr($message, 0, 500),
            'normalized_email' => $email,
            'raw_data' => $raw,
        ]);
    }

    /** @return array<int, array<int, string>> row line-number => cells */
    private function readRows(string $path, ?int $limit = null): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException('The uploaded file could not be reopened.');
        }

        $rows = [];
        $line = 1;
        $cellCount = 0;

        // The first physical line is the header row; it is never imported.
        if (fgetcsv($handle, 0, ',', '"', '') === false) {
            fclose($handle);

            return [];
        }

        try {
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;

                $rows[$line] = $cells;
                $this->assertRowLimits($cells, count($rows));
                if (($cellCount += count($cells)) > ResponseFileReader::MAX_CELLS) {
                    throw new RuntimeException('Response files support up to 300,000 cells. Split the file.');
                }
                if ($limit !== null && count($rows) >= $limit) {
                    break;
                }
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private function rowsFromContents(string $contents): array
    {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, $contents);
        rewind($handle);
        fgetcsv($handle, 0, ',', '"', '');
        $rows = [];
        $cellCount = 0;
        try {
            while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $this->assertRowLimits($cells, count($rows) + 1);
                if (($cellCount += count($cells)) > ResponseFileReader::MAX_CELLS) {
                    throw new RuntimeException('Response files support up to 300,000 cells. Split the file.');
                }
                $rows[] = $cells;
            }
        } finally {
            fclose($handle);
        }

        return $rows;
    }

    private function assertRowLimits(array $cells, int $rows): void
    {
        if ($rows > self::MAX_ROWS || count($cells) > ResponseFileReader::MAX_COLUMNS) {
            throw new RuntimeException('Response files support up to 5,000 rows and 200 columns. Split the file.');
        }
        foreach ($cells as $cell) {
            if (strlen((string) $cell) > ResponseFileReader::MAX_TEXT) {
                throw new RuntimeException('A response cell exceeds 32 KB. Split or shorten the source value.');
            }
        }
    }

    /** @return array<int, string> header index => original label */
    private function headersFromContents(string $contents): array
    {
        if (str_contains($contents, "\0") || ! mb_check_encoding($contents, 'UTF-8')) {
            throw new RuntimeException('The response file must be a UTF-8 CSV exported from Google Forms. Re-export it as Comma-separated values (.csv); do not upload an Excel or text-converted file.');
        }

        // Sheets and Excel prepend a byte-order mark that would otherwise ride
        // along on the first header and break anchored matching.
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $handle = fopen('php://temp', 'r+b');

        if ($handle === false) {
            return [];
        }

        fwrite($handle, $contents);
        rewind($handle);

        $headerRow = fgetcsv($handle, 0, ',', '"', '');
        $alternativeWidths = [];
        if (is_array($headerRow) && count($headerRow) === 1) {
            foreach ([';', "\t"] as $delimiter) {
                rewind($handle);
                $alternative = fgetcsv($handle, 0, $delimiter, '"', '');
                $alternativeWidths[$delimiter] = is_array($alternative) ? count($alternative) : 0;
            }
        }
        fclose($handle);

        if ($headerRow === false || $headerRow === [null]) {
            return [];
        }

        // Check separators outside source quoting: a valid single-column
        // heading can itself contain literal semicolons, tabs or line breaks.
        if (count($headerRow) === 1) {
            $only = (string) $headerRow[0];
            if (($alternativeWidths[';'] ?? 0) > 1 && ! str_contains($only, ',')) {
                throw new RuntimeException('This file uses semicolons (;) instead of commas. Re-export it as Comma-separated values (.csv) with a comma delimiter, or convert the semicolons to commas before uploading.');
            }
            if (($alternativeWidths["\t"] ?? 0) > 1) {
                throw new RuntimeException('This file uses tabs instead of commas. Re-export it as Comma-separated values (.csv) with a comma delimiter before uploading.');
            }
            if (str_starts_with(ltrim($contents), 'PK')) {
                throw new RuntimeException('This looks like an Excel (.xlsx) file renamed to .csv. Export it from Google Forms using File → Download → Comma-separated values (.csv).');
            }
        }

        $headers = collect($headerRow)
            ->map(fn ($label) => (string) $label)
            ->all();

        if (count($headers) > ResponseFileReader::MAX_COLUMNS) {
            throw new RuntimeException('Response files support up to 200 columns. Split the file.');
        }

        $seen = [];
        foreach ($headers as $index => $header) {
            if (mb_strlen($header) > 2000 || trim($header) === '_resolved') {
                throw new RuntimeException('Headers must be at most 2,000 characters and cannot use the reserved label _resolved. Rename the source column.');
            }
            if (ImportedResponseSchema::headerKey($header) === '') {
                throw new RuntimeException('Column '.($index + 1).' has no header. The file was not imported because its columns cannot be identified reliably.');
            }

            $key = ImportedResponseSchema::headerKey($header);
            if (isset($seen[$key])) {
                throw new RuntimeException('The file repeats the header “'.$header.'”. Rename the duplicate column before importing so no value is mapped ambiguously.');
            }
            $seen[$key] = true;
        }

        return $headers;
    }

    private function tmpPath(Import $import): string
    {
        return 'imports-pending/import-'.$import->id.'.csv';
    }

    /**
     * @return array<int|string, mixed> the original cells keyed by their header label.
     *                                  Retains every source cell, including overflow cells in malformed rows.
     *                                  Overflow labels cannot replace an actual source header or another cell.
     */
    private function rawDataFor(array $row, array $headers, array $map = [], array $required = []): array
    {
        $keep = [];
        for ($i = 0; $i < count($row); $i++) {
            $keep[$i] = true;
        }
        foreach (array_merge(array_values($map), array_map('intval', (array) $required)) as $index) {
            if (is_int($index) && $index >= 0 && $index < count($row)) {
                $keep[$index] = true;
            }
        }
        ksort($keep);

        $raw = [];
        foreach (array_keys($keep) as $index) {
            $label = (string) ($headers[$index] ?? 'column '.((int) $index + 1));
            $base = $label === '' ? 'column '.((int) $index + 1) : $label;
            $label = $base;
            $suffix = 1;
            while (array_key_exists($label, $raw) || (! array_key_exists($index, $headers) && in_array($label, $headers, true))) {
                $label = $base.' (extra '.$suffix++.')';
            }
            $raw[$label] = (string) $row[$index];
        }

        return $raw;
    }

    private function withResolvedPayload(array $raw, ?float $score, ?float $maximumScore, ?CarbonInterface $submittedAt, ?string $name): array
    {
        $raw['_resolved'] = [
            'name' => $name,
            'score' => $score,
            'maximum_score' => $maximumScore,
            'submitted_at' => $submittedAt?->toIso8601String(),
        ];

        return $raw;
    }
}
