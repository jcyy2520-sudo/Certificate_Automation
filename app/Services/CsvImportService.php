<?php

namespace App\Services;

use App\Models\EligibilityOverride;
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
            'last_name' => 'Last name',
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
        $candidates = [
            'email' => ['/e-?mail/i'],
            'maximum_score' => ['/max(imum)?[\s_-]?score|out\s*of(\s*\d+)?$|total[\s_-]?marks?/i'],
            'score' => ['/\bscore\b|\bmarks?\b|\bpoints\b|\bgrade\b/i'],
            'submitted_at' => ['/time[\s_-]?stamp|^timestamp|date[\s_-]?(?:of|received)|^\s*date\b|submitted/i'],
            'organization' => ['/organi[sz]ation|company|institution|affiliation|\bschool\b|\boffice\b|\bdepartment\b|\bhospital\b/i'],
            'first_name' => ['/first[\s_-]?name|given[\s_-]?name/i'],
            'last_name' => ['/last[\s_-]?name|sur[\s_-]?name|family[\s_-]?name/i'],
            'full_name' => [
                '/^(?:full[\s_-]*)?name$/i',
                '/^(?:your|the|complete|participant|attendee|delegate)[\s_-]*name(?![\s_-]*(?:of|for))/i',
                '/^(?:last[\s_-]*name[,][\s_-]*)?first[\s_-]*name/i',
            ],
        ];

        $used = [];
        $map = [];

        foreach ($candidates as $field => $patterns) {
            foreach ($patterns as $pattern) {
                foreach ($headers as $index => $header) {
                    if (in_array($index, $used, true)) {
                        continue;
                    }

                    if (preg_match($pattern, trim($header)) === 1) {
                        $map[$field] = $index;
                        $used[] = $index;

                        break 2;
                    }
                }
            }
        }

        // A person's name column rarely announces itself cleanly ("What is your
        // name?"). Fall back to any unclaimed header containing "name" that is
        // clearly about something else (an organization, event, course…).
        if (! isset($map['full_name'])) {
            foreach ($headers as $index => $header) {
                if (in_array($index, $used, true)) {
                    continue;
                }

                if (preg_match('/name/i', $header) === 1
                    && preg_match('/organi[sz]ation|company|institution|\bschool\b|\boffice\b|department|hospital|event|course|project|webinar|supervisor|emergency/i', $header) === 0) {
                    $map['full_name'] = $index;
                    $used[] = $index;

                    break;
                }
            }
        }

        return $map;
    }

    /** Validate a proposed mapping against the stored headers of a pending import. */
    public static function validateMap(Import $import, array $chosen): array
    {
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

        if (! isset($normalized['email'])) {
            throw new RuntimeException('Map the email address column before importing.');
        }

        $hasCombinedName = isset($normalized['full_name'])
            || (isset($normalized['first_name']) && isset($normalized['last_name']));
        if ($import->form->type === 'registration' && ! $hasCombinedName) {
            throw new RuntimeException('A registration import needs a name column so participants can be created.');
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

    /**
     * Persist a pending import plus its temporary file, ready for the mapping
     * confirmation step. The upload lives outside any tracked disk precisely
     * because it is transient personal data.
     */
    public function storePending(Webinar $webinar, Form $form, UploadedFile $file): Import
    {
        $contents = (string) file_get_contents($file->getRealPath());
        $headers = $this->headersFromContents($contents);

        if ($headers === []) {
            throw new RuntimeException('The file does not contain a readable CSV header row.');
        }

        /** @var Import $import */
        $import = Import::query()->create([
            'webinar_id' => $webinar->id,
            'form_id' => $form->id,
            'status' => Import::STATUS_PENDING,
            'original_filename' => $file->getClientOriginalName(),
            'file_hash' => Import::hashContents($contents),
            'file_size' => strlen($contents),
            'column_map' => [
                'headers' => $headers,
                'map' => self::suggestMap($headers, $form->type),
            ],
            'imported_by' => auth()->id(),
        ]);

        Storage::disk('local')->put($this->tmpPath($import), $contents);

        return $import;
    }

    /**
     * Read a small, ephemeral sample directly from the pending upload. This is
     * intentionally not stored on the Import model because response values are PII.
     *
     * @return array<int, array{row_number:int,cells:array<int,string>,column_count:int}>
     */
    public function previewRows(Import $import): array
    {
        $rows = $this->readRows(Storage::disk('local')->path($this->tmpPath($import)), self::PREVIEW_ROWS);

        return collect($rows)
            ->map(fn (array $cells, int $rowNumber) => [
                'row_number' => $rowNumber,
                'cells' => $cells,
                'column_count' => count($cells),
            ])
            ->values()
            ->all();
    }

    /**
     * Apply the confirmed mapping: every clean row becomes a submission on the
     * participant master list, every problem row becomes a reviewable issue.
     * Runs in one transaction so an import either lands whole or not at all.
     *
     * @param  array<string, int>  $map  logical field => header index
     * @return array{total:int, valid:int, invalid:int, duplicate:int, unmatched:int}
     */
    public function process(Import $import, array $map, string $timestampFormat = 'auto', bool $dryRun = false): array
    {
        $path = Storage::disk('local')->path($this->tmpPath($import));
        $rows = $this->readRows($path);
        $counted = count($rows);

        if ($counted > self::MAX_ROWS) {
            throw new RuntimeException('The file holds more than '.self::MAX_ROWS.' response rows. Split it into smaller files.');
        }

        try {
            $counts = DB::transaction(function () use ($dryRun, $import, $map, $rows, $timestampFormat): array {
                $lockedWebinar = Webinar::query()
                    ->whereKey($import->webinar_id)
                    ->whereNull('deletion_started_at')
                    ->sharedLock()
                    ->firstOrFail();
                $lockedForm = Form::query()
                    ->whereKey($import->form_id)
                    ->where('webinar_id', $lockedWebinar->id)
                    ->sharedLock()
                    ->firstOrFail();
                $lockedForm->setRelation('webinar', $lockedWebinar);

                if ($lockedWebinar->retention_due_at !== null && $lockedWebinar->retention_due_at->isPast()) {
                    throw new RuntimeException('This webinar is past its privacy retention deadline; participant data can no longer be imported.');
                }

                $counts = ['total' => 0, 'valid' => 0, 'invalid' => 0, 'duplicate' => 0, 'unmatched' => 0];
                $seenEmails = [];
                $isRegistration = $lockedForm->type === 'registration';
                $headers = $import->column_map['headers'] ?? [];

                foreach ($rows as $rowNumber => $row) {
                    $counts['total']++;

                    $raw = $this->rawDataFor($row, $headers);
                    if (count($row) !== count($headers)) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_INVALID_ROW_STRUCTURE, 'This row has '.count($row).' column(s), but the header has '.count($headers).'. It was not interpreted.', $raw, null);
                        $counts['invalid']++;

                        continue;
                    }

                    if ($this->rowIsBlank($row, $map)) {
                        continue;
                    }

                    $email = $this->value($row, $map, 'email');

                    if (blank($email)) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_MISSING_EMAIL, 'The row has no email address.', $raw, null);
                        $counts['invalid']++;

                        continue;
                    }

                    $email = Str::lower(trim($email));

                    if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_INVALID_EMAIL, '"'.$email.'" is not a usable email address.', $raw, $email);
                        $counts['invalid']++;

                        continue;
                    }

                    if (isset($seenEmails[$email])) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_DUPLICATE_IN_FILE, 'This email address already appeared on row '.$seenEmails[$email].' of this file.', $raw, $email);
                        $counts['duplicate']++;

                        continue;
                    }
                    $seenEmails[$email] = $rowNumber;

                    $score = null;
                    $maximumScore = null;
                    $rawScore = trim((string) ($this->value($row, $map, 'score') ?? ''));
                    if ($rawScore !== '') {
                        // Google Forms quiz exports frequently carry the score as a
                        // fraction ("8/10"); preserve both values exactly.
                        if (preg_match('/^(-?\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/', $rawScore, $fraction) === 1) {
                            $score = $this->decimal($fraction[1]);
                            $maximumScore = $this->decimal($fraction[2]);
                        } elseif (($score = $this->decimal($rawScore)) !== null) {
                            // decimal() refuses values that storage would round or reinterpret.
                        } else {
                            $this->issue($import, $rowNumber, ImportIssue::TYPE_INVALID_SCORE, 'The score "'.$rawScore.'" is not a supported decimal value.', $raw, $email);
                            $counts['invalid']++;

                            continue;
                        }
                    }
                    $rawMaximumScore = trim((string) ($this->value($row, $map, 'maximum_score') ?? ''));
                    if ($rawMaximumScore !== '') {
                        $mappedMaximum = $this->decimal($rawMaximumScore);
                        if ($mappedMaximum === null) {
                            $this->issue($import, $rowNumber, ImportIssue::TYPE_INVALID_SCORE, 'The maximum score "'.$rawMaximumScore.'" is not a supported decimal value.', $raw, $email);
                            $counts['invalid']++;

                            continue;
                        }
                        if ($maximumScore !== null && abs($maximumScore - $mappedMaximum) > 0.00001) {
                            $this->issue($import, $rowNumber, ImportIssue::TYPE_INVALID_SCORE, 'The fraction score and maximum-score column disagree. The row was not guessed.', $raw, $email);
                            $counts['invalid']++;

                            continue;
                        }
                        $maximumScore = $mappedMaximum;
                    }

                    if (($score !== null && $score < 0)
                        || ($maximumScore !== null && $maximumScore <= 0)
                        || ($score !== null && $maximumScore !== null && $score > $maximumScore)) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_INVALID_SCORE, 'The score must be zero or greater and cannot exceed a positive maximum score.', $raw, $email);
                        $counts['invalid']++;

                        continue;
                    }

                    try {
                        $submittedAt = $this->parseDate($this->value($row, $map, 'submitted_at'), $lockedWebinar->timezone, $timestampFormat);
                    } catch (RuntimeException $exception) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_INVALID_TIMESTAMP, $exception->getMessage(), $raw, $email);
                        $counts['invalid']++;

                        continue;
                    }
                    $fullName = $this->resolveName($row, $map);
                    $organization = filled($this->value($row, $map, 'organization'))
                        ? trim((string) $this->value($row, $map, 'organization'))
                        : null;

                    if ($isRegistration && blank($fullName)) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_MISSING_NAME, 'A registration row needs a participant name. It was not imported.', $raw, $email);
                        $counts['invalid']++;

                        continue;
                    }

                    if ((filled($fullName) && mb_strlen($fullName) > 180)
                        || (filled($organization) && mb_strlen($organization) > 180)) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_VALUE_TOO_LONG, 'A name or organization is longer than the supported 180 characters. It was not truncated or imported.', $raw, $email);
                        $counts['invalid']++;

                        continue;
                    }

                    $participant = Participant::withTrashed()
                        ->where('webinar_id', $lockedWebinar->id)
                        ->where('email_normalized', $email)
                        ->lockForUpdate()
                        ->first();

                    if ($participant === null) {
                        if ($isRegistration) {
                            $participant = Participant::query()->create([
                                'webinar_id' => $lockedWebinar->id,
                                'email' => $email,
                                'full_name' => $fullName,
                                'organization' => $organization,
                                'verified_at' => now(),
                            ]);
                            $this->attachSubmission($lockedForm, $participant, $score, $maximumScore, $submittedAt, $import, $rowNumber);
                            $counts['valid']++;

                            continue;
                        }

                        $this->issue(
                            $import,
                            $rowNumber,
                            ImportIssue::TYPE_UNMATCHED_EMAIL,
                            'Completed this form without a matching registration. Held for your review.',
                            $this->withResolvedPayload($raw, $score, $maximumScore, $submittedAt, $fullName),
                            $email,
                        );
                        $counts['unmatched']++;

                        continue;
                    }

                    if ($participant->privacy_erased_at !== null || $participant->trashed()) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_DUPLICATE_EXISTING, 'An erased or removed record holds this email address; it was left untouched.', $raw, $email);
                        $counts['duplicate']++;

                        continue;
                    }

                    $already = Submission::query()
                        ->where('form_id', $lockedForm->id)
                        ->where('participant_id', $participant->id)
                        ->exists();

                    if ($already) {
                        $this->issue($import, $rowNumber, ImportIssue::TYPE_DUPLICATE_EXISTING, 'This participant already has a recorded response for '.$lockedForm->title.'.', $raw, $email);
                        $counts['duplicate']++;

                        continue;
                    }

                    if ($isRegistration && $participant->verified_at === null) {
                        $participant->verified_at = now();
                    }
                    if (blank($participant->full_name) && filled($fullName)) {
                        $participant->full_name = $fullName;
                    }
                    if (blank($participant->organization) && filled($organization)) {
                        $participant->organization = $organization;
                    }
                    $participant->save();

                    $this->attachSubmission($lockedForm, $participant, $score, $maximumScore, $submittedAt, $import, $rowNumber);
                    $counts['valid']++;
                }

                $import->forceFill([
                    'status' => Import::STATUS_COMMITTED,
                    'column_map' => array_merge($import->column_map ?? [], ['map' => $map, 'timestamp_format' => $timestampFormat]),
                    'total_rows' => $counts['total'],
                    'valid_rows' => $counts['valid'],
                    'invalid_rows' => $counts['invalid'],
                    'duplicate_rows' => $counts['duplicate'],
                    'unmatched_rows' => $counts['unmatched'],
                    'committed_at' => now(),
                ])->save();

                if ($dryRun) {
                    // This deliberately rolls back every participant, submission,
                    // issue, and import mutation after exercising the same rules.
                    throw new CsvImportDryRunComplete($counts);
                }

                return $counts;
            }, attempts: 3);
        } catch (CsvImportDryRunComplete $complete) {
            $counts = $complete->counts;
        }

        // The transient copy of the upload never outlives a finished run.
        if (! $dryRun) {
            Storage::disk('local')->delete($this->tmpPath($import));
        }

        return $counts;
    }

    /**
     * Act on a held-for-review row: create the participant it describes and
     * attach the response that was waiting for a decision.
     */
    public function promote(ImportIssue $issue, User $administrator): Participant
    {
        if ($issue->resolved_at !== null || $issue->issue_type !== ImportIssue::TYPE_UNMATCHED_EMAIL) {
            throw new RuntimeException('Only an unresolved unmatched row can be promoted.');
        }

        return DB::transaction(function () use ($issue, $administrator): Participant {
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
            $name = mb_substr(trim((string) ($resolved['name'] ?? '')), 0, 180) ?: null;

            $participant = Participant::withTrashed()
                ->where('webinar_id', $lockedWebinar->id)
                ->where('email_normalized', $email)
                ->lockForUpdate()
                ->first();

            if ($participant === null) {
                $participant = Participant::query()->create([
                    'webinar_id' => $lockedWebinar->id,
                    'email' => $email,
                    'full_name' => $name,
                ]);

                // Promotion is an explicit administrator decision, exactly like
                // adding an off-platform attendee by hand: this person counts as
                // eligible regardless of which requirements they never met.
                EligibilityOverride::query()->create([
                    'participant_id' => $participant->id,
                    'decision' => 'eligible',
                    'reason' => 'Promoted from CSV import review (issue #'.$issue->id.').',
                    'overridden_by' => $administrator->id,
                ]);
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
                );
            }

            $issue->forceFill([
                'resolved_at' => now(),
                'resolved_by' => $administrator->id,
                'resolution' => $already
                    ? 'Participant already existed; the response was already recorded.'
                    : 'Promoted: participant '.($name ?? $email).' was added and the response attached.',
            ])->save();

            return $participant;
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
        if ($import->status !== Import::STATUS_PENDING) {
            return;
        }

        $import->forceFill(['status' => Import::STATUS_CANCELLED])->save();
        Storage::disk('local')->delete($this->tmpPath($import));
    }

    /** Pending uploads abandoned for a day are cancelled and their files dropped. */
    public function pruneStalePending(): int
    {
        $stale = Import::query()
            ->where('status', Import::STATUS_PENDING)
            ->where('created_at', '<', now()->subDay())
            ->get();

        foreach ($stale as $import) {
            $this->cancel($import);
        }

        return $stale->count();
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
    ): Submission {
        $attempt = (int) Submission::query()
            ->where('form_id', $form->id)
            ->where('participant_id', $participant->id)
            ->max('attempt_number');

        return Submission::query()->create([
            'form_id' => $form->id,
            'participant_id' => $participant->id,
            'attempt_number' => $attempt + 1,
            'status' => 'submitted',
            'submitted_at' => $submittedAt ?? now(),
            'score' => $score,
            'maximum_score' => $maximumScore,
            'metadata' => array_filter([
                'source' => 'csv_import',
                'import_id' => $import->id,
                'import_row_number' => $rowNumber,
            ], fn (mixed $value): bool => $value !== null),
        ]);
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

        // The first physical line is the header row; it is never imported.
        if (fgetcsv($handle, 0, ',', '"', '') === false) {
            fclose($handle);

            return [];
        }

        while (($cells = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;

            if ($cells === [null]) {
                continue;
            }

            $rows[$line] = $cells;
            if ($limit !== null && count($rows) >= $limit) {
                break;
            }
        }
        fclose($handle);

        return $rows;
    }

    /** @return array<int, string> header index => trimmed label */
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
        fclose($handle);

        if ($headerRow === false || $headerRow === [null]) {
            return [];
        }

        $headers = collect($headerRow)
            ->map(fn ($label) => trim((string) $label))
            ->all();

        $seen = [];
        foreach ($headers as $index => $header) {
            if ($header === '') {
                throw new RuntimeException('Column '.($index + 1).' has no header. The file was not imported because its columns cannot be identified reliably.');
            }

            $key = Str::lower(preg_replace('/\s+/u', ' ', $header) ?? $header);
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

    private function value(array $row, array $map, string $field): ?string
    {
        $index = $map[$field] ?? null;
        if ($index === null || ! array_key_exists($index, $row)) {
            return null;
        }

        $value = trim((string) $row[$index]);

        return $value === '' ? null : $value;
    }

    private function resolveName(array $row, array $map): ?string
    {
        $direct = $this->value($row, $map, 'full_name');

        if (filled($direct)) {
            return $direct;
        }

        $first = $this->value($row, $map, 'first_name') ?? '';
        $last = $this->value($row, $map, 'last_name') ?? '';
        $combined = trim($first.' '.$last);

        return $combined === '' ? null : $combined;
    }

    private function parseDate(?string $value, string $timezone, string $format = 'auto'): ?CarbonInterface
    {
        if (blank($value)) {
            return null;
        }

        $value = trim($value);

        $format = self::validateTimestampFormat($format);

        // A date such as 08/09/2026 has two valid but conflicting meanings.
        // Do not choose one based on server locale; the uploader must provide
        // an unambiguous Google Forms timestamp or leave it unmapped.
        if ($format === 'auto'
            && preg_match('/^(\d{1,2})\/(\d{1,2})\/\d{4}\s/', $value, $parts) === 1
            && (int) $parts[1] <= 12 && (int) $parts[2] <= 12) {
            throw new RuntimeException('The timestamp “'.$value.'” is ambiguous (month/day versus day/month), so it was not guessed. Use an unambiguous timestamp format or leave Submitted at unmapped.');
        }

        $formats = match ($format) {
            'month_day_year' => ['n/j/Y G:i:s', 'n/j/Y G:i', 'n/j/Y g:i:s A', 'n/j/Y g:i A'],
            'day_month_year' => ['d/m/Y G:i:s', 'd/m/Y G:i', 'd/m/Y g:i:s A', 'd/m/Y g:i A'],
            'iso_8601' => ['Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s\\Z', 'Y-m-d H:i:s', 'Y-m-d H:i'],
            default => [
                'n/j/Y G:i:s', 'n/j/Y G:i', 'n/j/Y g:i:s A', 'n/j/Y g:i A',
                'd/m/Y G:i:s', 'd/m/Y G:i', 'd/m/Y g:i:s A', 'd/m/Y g:i A',
                'Y-m-d H:i:s', 'Y-m-d H:i', 'Y-m-d\\TH:i:sP', 'Y-m-d\\TH:i:s\\Z',
            ],
        };

        foreach ($formats as $dateFormat) {
            try {
                $date = Carbon::createFromFormat($dateFormat, $value, $timezone);
            } catch (\Throwable) {
                continue;
            }
            $errors = Carbon::getLastErrors();
            if ($date !== false && (($errors === false) || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        throw new RuntimeException('The timestamp “'.$value.'” is not a supported Google Forms date/time value, so it was not replaced with the current time.');
    }

    /** Return a database-safe two-decimal number, never a rounded approximation. */
    private function decimal(string $value): ?float
    {
        if (preg_match('/^-?\d+(?:\.\d{1,2})?$/', trim($value)) !== 1) {
            return null;
        }

        return (float) $value;
    }

    private function rowIsBlank(array $row, array $map): bool
    {
        foreach (array_keys($map) as $field) {
            if (filled($this->value($row, $map, $field))) {
                return false;
            }
        }

        return true;
    }

    /** @return array<int|string, mixed> the original cells keyed by their header label */
    private function rawDataFor(array $row, array $headers): array
    {
        $raw = [];

        foreach (array_slice($row, 0, 60) as $index => $cell) {
            $label = trim((string) ($headers[$index] ?? 'column '.((int) $index + 1)));
            $raw[$label === '' ? 'column '.((int) $index + 1) : $label] = (string) $cell;
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
