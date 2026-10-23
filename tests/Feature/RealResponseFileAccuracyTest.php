<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Models\Import;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\EligibilityService;
use App\Services\FormResponseService;
use App\Services\ResponseFileReader;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Monolog\Handler\NullHandler;
use RuntimeException;
use Tests\Support\OpenXmlResponseOracle;
use Tests\TestCase;
use Throwable;

/** Opt-in, read-only originals; all imported records live only in SQLite memory.
 *
 * REAL_RESPONSE_FILES_DIRECTORY must identify the four owner's original exports.
 * Optional REAL_RESPONSE_ACCURACY_REPORT writes aggregates/source row coordinates only.
 * Failures disclose coordinates/categories and digests, never actual/expected cells.
 */
class RealResponseFileAccuracyTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshMemoryDatabase;
    }

    private const FILES = [
        'registration' => ['Digital Integrity_ Upholding Ethics in IT Operations (Responses).xlsx', 238, 13],
        'pretest' => ['PRE-TEST (Responses) (1).xlsx', 127, 13],
        'posttest' => ['POST-TEST (Responses) (1).xlsx', 123, 13],
        'evaluation' => ['POST EVALUATION (Responses).xlsx', 124, 6],
    ];

    private Webinar $webinar;

    private array $mismatches = [];

    private array $proof = [];

    private int $cellsCompared = 0;

    private int $eligibilityComparisons = 0;

    private array $acceptedSources = [];

    private string $fakeDisk;

    private string $context = 'initializing';

    public function refreshDatabase(): void
    {
        // This executes before RefreshDatabase can connect or run migrations.
        if (! $this->app->environment('testing')) {
            throw new RuntimeException('Real-file verification requires APP_ENV=testing.');
        }
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
        ]);
        $this->refreshMemoryDatabase();
    }

    protected function setUp(): void
    {
        if (! getenv('REAL_RESPONSE_FILES_DIRECTORY')) {
            $this->markTestSkipped('Opt in with REAL_RESPONSE_FILES_DIRECTORY; real exports are not repository fixtures.');
        }
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config([
            'app.debug' => false,
            'logging.default' => 'real_accuracy_null',
            'logging.channels.real_accuracy_null' => ['driver' => 'monolog', 'handler' => NullHandler::class],
            'webinar.email.provider' => 'log',
            'webinar.email.certificate_provider' => 'log',
            'accounts.email_provider' => 'log',
            'services.brevo.key' => '',
            'mail.default' => 'array',
            'queue.default' => 'sync',
            'filesystems.default' => 'local',
            'webinar.document_disk' => 'local',
            'webinar.certificate_disk' => 'local',
        ]);
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Bus::fake();
        $this->mock(TransactionalMailer::class)->shouldNotReceive('send');
        $this->fakeDisk = 'real-response-accuracy-'.bin2hex(random_bytes(8));
        $disk = Storage::fake($this->fakeDisk);
        Storage::set('local', $disk);
        $owner = User::factory()->create(['role' => User::USER_ROLE, 'email' => 'accuracy-owner@example.test', 'name' => 'Accuracy Test Owner']);
        $this->actingAs($owner);
        $this->webinar = Webinar::factory()->create([
            'created_by' => $owner->id, 'title' => 'Isolated real-file accuracy verification',
            'requires_verification' => false, 'timezone' => 'Asia/Manila', 'retention_due_at' => now()->addYear(),
        ]);
        foreach (FormResponseService::TYPES as $type => $title) {
            $this->webinar->forms()->create(['type' => $type, 'title' => $title, 'status' => 'published']);
            // Requiring evaluation proves anonymous evaluations never confer individual eligibility.
            $this->webinar->eligibilityRules()->create(['requirement' => $type, 'is_required' => true]);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fakeDisk) && $this->app !== null) {
            $disk = Storage::disk($this->fakeDisk);
            // Only files inventoried on this random fake adapter can be removed.
            foreach ($disk->allFiles() as $file) {
                $disk->delete($file);
            }
        }
        parent::tearDown();
    }

    public function test_every_real_source_cell_survives_review_storage_and_rendering_or_is_safely_held(): void
    {
        $paths = [];
        $hashes = [];
        foreach (self::FILES as $type => [$name]) {
            $paths[$type] = rtrim((string) getenv('REAL_RESPONSE_FILES_DIRECTORY'), '/\\').DIRECTORY_SEPARATOR.$name;
            $this->assertTrue(is_file($paths[$type]), 'Missing opted-in original for '.$type.'.');
            $hashes[$type] = hash_file('sha256', $paths[$type]);
        }
        try {
            foreach (self::FILES as $type => [$name, $rowCount, $width]) {
                $this->verifyFile($type, $paths[$type], $name, $rowCount, $width);
            }
            $this->assertDatabaseCount('certificates', 0);
            $this->assertDatabaseCount('certificate_approvals', 0);
            $this->assertDatabaseCount('certificate_batches', 0);
            $this->assertDatabaseCount('email_deliveries', 0);
            $this->assertDatabaseCount('jobs', 0);
            $this->assertDatabaseCount('failed_jobs', 0);
            $this->assertSame(0, $this->webinar->forms()->where('type', 'evaluation')->firstOrFail()->submissions()->count());
            $this->assertSame(0, app(EligibilityService::class)->eligibleParticipantsQuery($this->webinar->fresh())->count());
            $this->verifyEligibility();
            Mail::assertNothingSent();
            Mail::assertNothingQueued();
            Notification::assertNothingSent();
            Queue::assertNothingPushed();
            Bus::assertNothingDispatched();
            Http::assertNothingSent();
        } catch (Throwable $exception) {
            // Avoid PHPUnit/HTTP diagnostics serializing real imported values.
            $frames = array_values(array_filter($exception->getTrace(), fn ($frame) => ($frame['file'] ?? null) === __FILE__));
            $this->fail('Real-file verification aborted at '.$this->context.': '.get_class($exception).'; diagnostic sha256='.hash('sha256', $exception->getMessage()).'; test_line='.($frames[0]['line'] ?? $exception->getLine()));
        } finally {
            foreach ($paths as $type => $path) {
                $this->assertSame($hashes[$type], hash_file('sha256', $path), $type.' original changed.');
            }
        }
        $this->proof['cells_compared'] = $this->cellsCompared;
        $this->proof['eligibility_comparisons'] = $this->eligibilityComparisons;
        $this->proof['mismatch_count'] = count($this->mismatches);
        $this->proof['mismatches_by_category'] = array_count_values(array_column($this->mismatches, 'category'));
        $this->proof['originals_unchanged'] = true;
        $this->proof['side_effects'] = ['certificates' => 0, 'approvals' => 0, 'batches' => 0, 'deliveries' => 0, 'jobs' => 0, 'http' => 0, 'mail' => 0];
        if ($report = getenv('REAL_RESPONSE_ACCURACY_REPORT')) {
            file_put_contents($report, json_encode($this->proof, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR).PHP_EOL);
        }
        $this->assertSame(0, count($this->mismatches), 'Cell accuracy mismatches (safe coordinates/digests only): '.json_encode(array_slice($this->mismatches, 0, 25), JSON_THROW_ON_ERROR));
    }

    private function verifyFile(string $type, string $path, string $name, int $rowCount, int $width): void
    {
        $this->context = $type.' decoding';
        $oracle = OpenXmlResponseOracle::read($path);
        $this->assertSame($rowCount + 1, count($oracle['rows']), $type.' source row count.');
        $this->assertSame($width, count($oracle['rows'][1]), $type.' source column count.');
        $this->assertSame($rowCount, $oracle['stats']['date_cells'], $type.' source timestamps.');
        $this->assertSame($type === 'registration' ? 300 : 0, $oracle['stats']['whitespace_cells']);
        $this->assertSame(in_array($type, ['pretest', 'posttest'], true) ? $rowCount : 0, $oracle['stats']['formatted_score_cells']);
        $file = new UploadedFile($path, $name, null, null, true);
        $decoded = $this->csvRows(app(ResponseFileReader::class)->contents($file));
        $this->assertSame(count($oracle['rows']), count($decoded), $type.' decoded row count.');
        foreach ($oracle['rows'] as $row => $source) {
            $this->assertSame($width, count($decoded[$row] ?? []), $type.' decoded row width at '.$row.'.');
            foreach ($source as $column => $value) {
                $this->compare($value, $decoded[$row][$column] ?? null, 'decoded', $type, $row, $column);
            }
        }
        $form = $this->webinar->forms()->where('type', $type)->firstOrFail();
        $this->context = $type.' preview upload';
        $response = $this->post(route('admin.webinars.imports.preview', $this->webinar), ['form_id' => $form->id, 'file' => $file]);
        $this->assertSame(302, $response->status(), $type.' upload HTTP status.');
        $import = $this->webinar->imports()->where('form_id', $form->id)->latest('id')->first();
        $this->assertTrue($import !== null, $type.' pending upload was not created.');
        foreach ($oracle['rows'][1] as $column => $label) {
            $this->compare($label, $import->column_map['headers'][$column] ?? null, 'stored_header', $type, 1, $column);
        }
        $this->proof['files'][$type] = ['source_rows' => $rowCount, 'source_columns' => $width, 'source_cells' => ($rowCount + 1) * $width] + $oracle['stats'];
        if ($type === 'evaluation') {
            $this->assertArrayNotHasKey('email', $import->column_map['map']);
            $this->assertArrayNotHasKey('full_name', $import->column_map['map']);
            $before = $this->stateCounts();
            $rejected = false;
            try {
                CsvImportService::validateMap($import, $import->column_map['map']);
            } catch (RuntimeException $exception) {
                $rejected = str_contains($exception->getMessage(), 'email');
            }
            $this->assertTrue($rejected, 'Anonymous evaluation must require an identity-bearing export.');
            $this->assertSame($before, $this->stateCounts());
            $this->assertSame(Import::STATUS_PENDING, $import->fresh()->status);
            app(CsvImportService::class)->cancel($import);
            $this->proof['files'][$type]['individual_import_blocked'] = true;

            return;
        }
        $map = $import->column_map['map'];
        $this->assertArrayHasKey('email', $map);
        $this->assertArrayHasKey('submitted_at', $map);
        if ($type === 'registration') {
            $this->assertArrayHasKey('full_name', $map);
        } else {
            $this->assertArrayHasKey('score', $map);
        }
        $payload = ['map' => $map, 'timestamp_format' => 'iso_8601', 'timestamp_timezone' => 'Asia/Manila', 'allow_import_time' => false,
            'source_description' => 'Opt-in original '.$type.' workbook accuracy verification', 'source_confirmed' => '1', 'review_confirmed' => '1'];
        $before = $this->stateCounts();
        $this->context = $type.' dry check';
        $dryResponse = $this->post(route('admin.webinars.imports.dry-run', [$this->webinar, $import]), $payload);
        $this->assertSame(302, $dryResponse->status());
        $dry = $this->app['session']->get('dry_run');
        $this->assertTrue(is_array($dry), $type.' dry check did not produce counts.');
        $counts = array_intersect_key($dry, array_flip(['total', 'valid', 'invalid', 'duplicate', 'unmatched']));
        $this->assertSame($before, $this->stateCounts(), $type.' dry check must roll back every record.');
        $this->assertSame(Import::STATUS_PENDING, $import->fresh()->status);
        $this->context = $type.' confirmed commit';
        $committed = $this->post(route('admin.webinars.imports.store', [$this->webinar, $import]), $payload);
        $this->assertSame(302, $committed->status());
        $import->refresh();
        $this->assertSame(Import::STATUS_COMMITTED, $import->status, $type.' reviewed commit status.');
        $this->assertSame($counts, ['total' => $import->total_rows, 'valid' => $import->valid_rows, 'invalid' => $import->invalid_rows,
            'duplicate' => $import->duplicate_rows, 'unmatched' => $import->unmatched_rows], $type.' dry and committed counts.');
        $this->assertSame($rowCount, array_sum(array_diff_key($counts, ['total' => true])), $type.' all source rows accounted.');
        $submissions = $form->submissions()->where('metadata->import_id', $import->id)->with(['participant', 'answers'])->get()->keyBy(fn ($row) => $row->metadata['import_row_number']);
        $issues = $import->issues()->get()->keyBy('row_number');
        $this->assertSame($counts['valid'], $submissions->count());
        $this->assertSame($rowCount - $counts['valid'], $issues->count());
        foreach ($oracle['rows'] as $rowNumber => $source) {
            if ($rowNumber === 1) {
                continue;
            }
            $submission = $submissions->get($rowNumber);
            $this->context = $type.' outcome row '.$rowNumber;
            $issue = $issues->get($rowNumber);
            $this->assertTrue(($submission === null) !== ($issue === null), $type.' row '.$rowNumber.' must have exactly one outcome.');
            if ($submission) {
                $original = $submission->imported_data['original'] ?? [];
                $this->assertSame($width, count($original), $type.' original cell count at '.$rowNumber.'.');
                foreach ($source as $column => $value) {
                    $this->compare($value, $original[$column]['value'] ?? null, 'stored_original', $type, $rowNumber, $column);
                    $this->compare($oracle['rows'][1][$column], $original[$column]['label'] ?? null, 'stored_original_label', $type, $rowNumber, $column);
                }
                $expectedEmail = mb_strtolower(trim($source[$map['email']]));
                $this->compare($expectedEmail, $submission->participant->email_normalized, 'normalized_identity', $type, $rowNumber, $map['email']);
                $this->assertFalse(isset($this->acceptedSources[$type][$expectedEmail]), $type.' repeats an accepted source identity at row '.$rowNumber.'.');
                $score = null;
                if (isset($map['score'])) {
                    $this->assertSame(1, preg_match('/^(\d+) \/ (\d+)$/D', $source[$map['score']], $scoreParts), $type.' independent score syntax at row '.$rowNumber.'.');
                    $this->assertSame(10, (int) $scoreParts[2], $type.' independent maximum at row '.$rowNumber.'.');
                    $score = (int) $scoreParts[1];
                }
                // Only acceptance/participant linkage comes from the import outcome.
                // Identity and points come directly from independently decoded source cells.
                $this->acceptedSources[$type][$expectedEmail] = ['row' => $rowNumber, 'participant_id' => $submission->participant_id, 'score' => $score];
                $sourceIdentity = app(FormResponseService::class)->sourceIdentity($submission);
                foreach (['full_name' => 'name', 'email' => 'email', 'submitted_at' => 'submitted_at', 'score' => 'score'] as $field => $identityKey) {
                    if (isset($map[$field])) {
                        $this->compare($source[$map[$field]], $sourceIdentity[$identityKey] ?? null, 'source_identity', $type, $rowNumber, $map[$field]);
                    }
                }
                $this->assertTrue(json_decode(DB::table('submissions')->where('id', $submission->id)->value('imported_data'), true) === null, 'Imported originals must be encrypted.');
                if (isset($map['score']) && preg_match('/^(\d+) \/ (\d+)$/D', $source[$map['score']], $parts)) {
                    $this->compare(number_format((float) $parts[1], 2, '.', ''), $submission->score, 'interpreted_score', $type, $rowNumber, $map['score']);
                    $this->compare(number_format((float) $parts[2], 2, '.', ''), $submission->maximum_score, 'interpreted_maximum', $type, $rowNumber, $map['score']);
                }
                $this->compare($source[$map['submitted_at']], $submission->submitted_at?->timezone('Asia/Manila')->format('Y-m-d H:i:s'), 'interpreted_timestamp', $type, $rowNumber, $map['submitted_at']);
                $this->verifyDetails($submission, $source, $oracle['rows'][1], $type, $rowNumber);
            } else {
                foreach ($source as $column => $value) {
                    $this->compare($value, $issue->raw_data[$oracle['rows'][1][$column]] ?? null, 'held_original', $type, $rowNumber, $column);
                }
                $this->assertTrue(json_decode(DB::table('import_issues')->where('id', $issue->id)->value('raw_data'), true) === null, 'Held originals must be encrypted.');
            }
        }
        $this->verifyTable($form, $oracle['rows'], $type);
        $this->verifyHeldDetails($type, $oracle['rows']);
        $this->proof['files'][$type]['outcomes'] = $counts;
        $this->proof['files'][$type]['held_by_category'] = $issues->groupBy('issue_type')->map->count()->all();
        $this->proof['files'][$type]['held_source_rows_by_category'] = $issues->groupBy('issue_type')->map(fn ($group) => $group
            ->pluck('row_number')->map(fn ($row) => (int) $row)->sort()->values()->all())->sortKeys()->all();
    }

    private function verifyEligibility(): void
    {
        $this->context = 'independent eligibility source evidence';
        $registration = $this->acceptedSources['registration'] ?? [];
        $this->assertSame(205, count($registration));
        $this->assertSame(205, $this->webinar->participants()->count());
        $this->assertSame(84, count($this->acceptedSources['pretest'] ?? []));
        $this->assertSame(76, count($this->acceptedSources['posttest'] ?? []));
        $this->assertDatabaseCount('eligibility_overrides', 0);
        $registeredById = [];
        foreach ($registration as $email => $evidence) {
            $registeredById[$evidence['participant_id']] = $evidence + ['email' => $email];
        }
        foreach (['pretest', 'posttest'] as $type) {
            foreach ($this->acceptedSources[$type] as $email => $evidence) {
                $this->assertTrue(isset($registration[$email]), $type.' accepted row '.$evidence['row'].' has no accepted registration.');
                $this->assertSame($registration[$email]['participant_id'], $evidence['participant_id'], $type.' accepted row '.$evidence['row'].' must link by source email.');
            }
        }
        $scenarios = [
            'registration_pre_post_no_minimum' => ['minimum' => null, 'additional' => null],
            'registration_pre_post_minimum_0' => ['minimum' => 0, 'additional' => null],
            'registration_pre_post_minimum_8' => ['minimum' => 8, 'additional' => null],
            'registration_pre_post_minimum_10' => ['minimum' => 10, 'additional' => null],
            'registration_pre_post_evaluation_required' => ['minimum' => null, 'additional' => 'evaluation'],
            'registration_pre_post_attendance_required' => ['minimum' => null, 'additional' => 'attendance'],
        ];
        $service = app(EligibilityService::class);
        $before = $this->stateCounts();
        foreach ($scenarios as $name => $scenario) {
            $this->context = 'eligibility scenario '.$name;
            $this->webinar->eligibilityRules()->update(['is_required' => false, 'minimum_score' => null]);
            $required = ['registration', 'pretest', 'posttest'];
            if ($scenario['additional'] !== null) {
                $required[] = $scenario['additional'];
            }
            foreach ($required as $requirement) {
                $this->webinar->eligibilityRules()->updateOrCreate(['requirement' => $requirement], [
                    'is_required' => true,
                    'minimum_score' => in_array($requirement, ['pretest', 'posttest'], true) ? $scenario['minimum'] : null,
                ]);
            }
            $webinar = $this->webinar->fresh();
            $participants = $webinar->participants()->with(['submissions.form', 'eligibilityOverrides'])->orderBy('id')->get();
            $this->assertSame(205, $participants->count(), $name.' participant coverage.');
            $expectedIds = [];
            $evaluatedIds = [];
            foreach ($participants as $participant) {
                $source = $registeredById[$participant->id] ?? null;
                $this->assertTrue($source !== null, $name.' participant has no accepted registration source.');
                $requirements = ['registration' => true];
                foreach (['pretest', 'posttest'] as $type) {
                    $evidence = $this->acceptedSources[$type][$source['email']] ?? null;
                    $requirements[$type] = $evidence !== null
                        && ($scenario['minimum'] === null || $evidence['score'] >= $scenario['minimum']);
                }
                if ($scenario['additional'] !== null) {
                    // No identity-bearing evaluation or attendance proof was supplied.
                    $requirements[$scenario['additional']] = false;
                }
                $expected = ! in_array(false, $requirements, true);
                if ($expected) {
                    $expectedIds[] = (int) $participant->id;
                }
                $evaluated = $service->evaluate($participant);
                $this->compareEligibility($expected, $evaluated['eligible'], 'eligibility_evaluate', $name, $source['row']);
                $this->compareEligibility(false, $evaluated['overridden'], 'eligibility_override', $name, $source['row']);
                ksort($requirements);
                ksort($evaluated['requirements']);
                $this->compareEligibility($requirements, $evaluated['requirements'], 'eligibility_requirements', $name, $source['row']);
                if ($evaluated['eligible']) {
                    $evaluatedIds[] = (int) $participant->id;
                }
            }
            $sqlIds = $service->eligibleParticipantsQuery($webinar)->orderBy('participants.id')->pluck('participants.id')->map(fn ($id) => (int) $id)->all();
            $this->compareEligibility($expectedIds, $evaluatedIds, 'eligibility_evaluate_roster', $name, 0);
            $this->compareEligibility($expectedIds, $sqlIds, 'eligibility_sql_roster', $name, 0);
            if ($scenario['additional'] !== null) {
                $this->assertCount(0, $expectedIds, $name.' cannot be satisfied without source evidence.');
            }
            $this->proof['eligibility']['scenarios'][$name] = [
                'required' => $required, 'minimum_test_points' => $scenario['minimum'], 'participants_checked' => $participants->count(),
                'independently_expected_eligible' => count($expectedIds), 'evaluated_eligible' => count($evaluatedIds), 'sql_eligible' => count($sqlIds),
            ];
        }
        $this->assertSame($before, $this->stateCounts(), 'Eligibility verification must preserve accepted and held response records.');
        $this->webinar->eligibilityRules()->update(['is_required' => false, 'minimum_score' => null]);
        $this->webinar->eligibilityRules()->whereIn('requirement', array_keys(FormResponseService::TYPES))->update(['is_required' => true]);
        $this->proof['eligibility']['accepted_registration_participants'] = count($registration);
        $this->proof['eligibility']['accepted_pretest_source_rows'] = count($this->acceptedSources['pretest']);
        $this->proof['eligibility']['accepted_posttest_source_rows'] = count($this->acceptedSources['posttest']);
        $this->proof['eligibility']['participant_scenario_checks'] = count($registration) * count($scenarios);
        $this->proof['eligibility']['sql_roster_checks'] = count($scenarios);
    }

    private function verifyDetails(Submission $submission, array $source, array $headers, string $type, int $row): void
    {
        $this->context = $type.' inspect row '.$row;
        $response = $this->get(route('admin.responses.show', [$this->webinar, $submission]));
        $this->assertSame(200, $response->status(), $type.' inspect status at '.$row.'.');
        $dom = $this->html($response->getContent());
        $cells = $dom->query('(//section//dl)[2]/div/dd');
        $labels = $dom->query('(//section//dl)[2]/div/dt');
        $this->assertSame(count($source), $cells->length, $type.' inspect original count at '.$row.'.');
        foreach ($source as $column => $value) {
            $this->compare($value, $cells->item($column)?->textContent, 'rendered_original', $type, $row, $column);
            $this->compare($headers[$column], $labels->item($column)?->textContent, 'rendered_original_label', $type, $row, $column);
        }
    }

    private function verifyTable($form, array $sourceRows, string $type): void
    {
        $page = 1;
        $shown = 0;
        $importMap = Import::query()->where('form_id', $form->id)->where('status', Import::STATUS_COMMITTED)->sole()->column_map['map'];
        do {
            $this->context = $type.' table page '.$page;
            $response = $this->get(route('admin.responses.index', [$this->webinar, 'type' => $type, 'page' => $page]));
            $this->assertSame(200, $response->status(), $type.' table status on page '.$page.'.');
            $paginator = $response->viewData('rows');
            $columns = $response->viewData('columns');
            $dom = $this->html($response->getContent());
            $labels = $dom->query('//table[contains(concat(" ", normalize-space(@class), " "), " response-data-table ")]/thead/tr/th/span');
            $this->assertSame(count($columns), $labels->length, $type.' rendered source header count.');
            foreach (array_values($columns) as $column => $definition) {
                $this->compare($sourceRows[1][$definition['source_index']], $labels->item($column)?->textContent, 'rendered_table_header', $type, 1, $definition['source_index']);
            }
            $rows = $dom->query('//table[contains(concat(" ", normalize-space(@class), " "), " response-data-table ")]/tbody/tr');
            $this->assertSame($paginator->count(), $rows->length, $type.' rendered row count.');
            foreach ($paginator as $index => $submission) {
                $sourceRow = $submission->metadata['import_row_number'];
                $source = $sourceRows[$sourceRow];
                $row = $rows->item($index);
                $name = $dom->query('./th/a', $row)->item(0)?->textContent;
                $email = $dom->query('./th/span[1]', $row)->item(0)?->textContent;
                if (isset($importMap['full_name'])) {
                    $this->compare($source[$importMap['full_name']], $name, 'rendered_source_name', $type, $sourceRow, $importMap['full_name']);
                }
                $this->compare($source[$importMap['email']], $email, 'rendered_source_email', $type, $sourceRow, $importMap['email']);
                foreach (['submitted_at' => ['Source time: ', './td[1]/span[starts-with(text(), "Source time: ")]'],
                    'score' => ['Source score: ', './td[2]/span[starts-with(text(), "Source score: ")]']] as $field => [$prefix, $query]) {
                    if (isset($importMap[$field])) {
                        $this->compare($prefix.$source[$importMap[$field]], $dom->query($query, $row)->item(0)?->textContent, 'rendered_source_'.$field, $type, $sourceRow, $importMap[$field]);
                    }
                }
                $cells = $dom->query('./td[contains(concat(" ", normalize-space(@class), " "), " response-answer-cell ")]', $row);
                $this->assertSame(count($columns), $cells->length, $type.' rendered answer column count.');
                foreach (array_values($columns) as $column => $definition) {
                    $sourceColumn = $definition['source_index'] ?? null;
                    $this->assertTrue(is_int($sourceColumn), 'Expected import-owned source index.');
                    $full = $dom->query('./details/p', $cells->item($column))->item(0);
                    $text = $full?->textContent ?? $dom->query('./span', $cells->item($column))->item(0)?->textContent;
                    $this->compare($source[$sourceColumn] === '' ? '—' : $source[$sourceColumn], $text, 'rendered_table', $type, $sourceRow, $sourceColumn);
                }
                $shown++;
            }
            $page++;
        } while ($page <= $paginator->lastPage());
        $this->assertSame($form->submissions()->where('status', 'submitted')->count(), $shown, $type.' all paginated submissions rendered.');
        $this->proof['files'][$type]['rendered_pages'] = $page - 1;
        $this->proof['files'][$type]['rendered_submissions'] = $shown;
    }

    private function verifyHeldDetails(string $type, array $sourceRows): void
    {
        $this->context = $type.' held source details';
        $response = $this->get(route('admin.webinars.imports.index', [$this->webinar, 'type' => $type]));
        $this->assertSame(200, $response->status());
        $issues = $response->viewData('issues');
        $this->assertLessThanOrEqual(200, $issues->count());
        $dom = $this->html($response->getContent());
        $rows = $dom->query('//ul/li[.//summary[text()="Inspect source values"]]');
        $this->assertSame($issues->count(), $rows->length);
        foreach ($issues as $index => $issue) {
            $values = $dom->query('.//details[summary[text()="Inspect source values"]]/dl/div/dd', $rows->item($index));
            $source = $sourceRows[$issue->row_number];
            $this->assertSame(count($source), $values->length);
            foreach ($source as $column => $value) {
                $this->compare($value, $values->item($column)?->textContent, 'rendered_held_original', $type, $issue->row_number, $column);
            }
        }
    }

    private function stateCounts(): array
    {
        return collect(['participants', 'submissions', 'submission_answers', 'import_issues'])->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
    }

    private function csvRows(string $csv): array
    {
        $handle = fopen('php://temp', 'w+b');
        fwrite($handle, $csv);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $rows[count($rows) + 1] = $row;
        }
        fclose($handle);

        return $rows;
    }

    private function html(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $before = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }

        return new DOMXPath($document);
    }

    private function compare(mixed $expected, mixed $actual, string $category, string $type, int $row, int $column): void
    {
        $this->cellsCompared++;
        $this->recordMismatch($expected, $actual, $category, $type, $row, $column);
    }

    private function compareEligibility(mixed $expected, mixed $actual, string $category, string $scenario, int $registrationRow): void
    {
        $this->eligibilityComparisons++;
        $this->recordMismatch($expected, $actual, $category, $scenario, $registrationRow, 0);
    }

    private function recordMismatch(mixed $expected, mixed $actual, string $category, string $type, int $row, int $column): void
    {
        if ($expected !== $actual) {
            $this->mismatches[] = ['category' => $category, 'form' => $type, 'row' => $row, 'column' => $column + 1,
                'expected_sha256' => hash('sha256', serialize($expected)), 'actual_sha256' => hash('sha256', serialize($actual))];
        }
    }
}
