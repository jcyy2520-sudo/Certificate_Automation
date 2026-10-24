<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Models\Import;
use App\Models\Submission;
use App\Models\UnattributedResponse;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\EligibilityService;
use App\Services\FormResponseService;
use App\Services\ResponseFileReader;
use App\Services\UnattributedResponseService;
use DateTimeImmutable;
use DateTimeZone;
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
use Tests\Support\PrivateWorkbookBrowserCapture;
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

    private array $sourceMaps = [];

    private ?PrivateWorkbookBrowserCapture $browserCapture = null;

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
        $this->browserCapture = new PrivateWorkbookBrowserCapture;
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
            $this->fail('Real-file verification aborted at '.$this->context.': '.get_class($exception).'; diagnostic sha256='.hash('sha256', $exception->getMessage()).'; location='.basename($exception->getFile()).':'.$exception->getLine().'; test_line='.($frames[0]['line'] ?? $exception->getLine()));
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
        $this->browserCapture?->emit();
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
        $this->verifyPreview($import, $oracle['rows'], $type);
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
            $this->assertTrue($rejected, 'The default individual evaluation must require an identity-bearing export.');
            $this->assertSame($before, $this->stateCounts());
            $this->assertSame(Import::STATUS_PENDING, $import->fresh()->status);
            $this->proof['files'][$type]['individual_import_blocked'] = true;
            $this->verifyUnattributed($import, $oracle['rows']);

            return;
        }
        $map = $import->column_map['map'];
        $this->sourceMaps[$type] = $map;
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
                $this->acceptedSources[$type][$expectedEmail] = ['row' => $rowNumber, 'participant_id' => $submission->participant_id, 'score' => $score,
                    'name' => isset($map['full_name']) ? trim($source[$map['full_name']]) : null];
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
        $this->verifyExport($form, $oracle['rows'], $type);
        $this->verifyHeldDetails($type, $oracle['rows']);
        $this->proof['files'][$type]['outcomes'] = $counts;
        $this->proof['files'][$type]['held_by_category'] = $issues->groupBy('issue_type')->map->count()->all();
        $this->proof['files'][$type]['held_source_rows_by_category'] = $issues->groupBy('issue_type')->map(fn ($group) => $group
            ->pluck('row_number')->map(fn ($row) => (int) $row)->sort()->values()->all())->sortKeys()->all();
    }

    private function verifyUnattributed(Import $import, array $sourceRows): void
    {
        $this->context = 'evaluation unattributed dry check';
        $payload = ['response_mode' => 'unattributed', 'map' => $import->column_map['map'],
            'source_description' => 'Opt-in original evaluation workbook accuracy verification', 'source_confirmed' => '1', 'review_confirmed' => '1'];
        $before = $this->stateCounts();
        $dryResponse = $this->post(route('admin.webinars.imports.dry-run', [$this->webinar, $import]), $payload);
        $this->assertSame(302, $dryResponse->status());
        $review = $import->fresh()->column_map['review_results'] ?? null;
        $this->assertSame(['total' => 124, 'valid' => 124, 'invalid' => 0, 'duplicate' => 0, 'unmatched' => 0], $review);
        $this->assertSame($before, $this->stateCounts(), 'Unattributed dry check must roll back every response.');
        $this->context = 'evaluation unattributed confirmed commit';
        $committed = $this->post(route('admin.webinars.imports.store', [$this->webinar, $import]), $payload);
        $this->assertSame(302, $committed->status());
        $this->assertSame(route('admin.unattributed.index', [$this->webinar, 'import' => $import->id]), $committed->headers->get('Location'));
        $import->refresh();
        $this->assertSame(Import::STATUS_COMMITTED, $import->status);
        $this->assertSame('unattributed', $import->column_map['options']['response_mode'] ?? null);
        $this->assertSame(124, $import->valid_rows);
        $after = $this->stateCounts();
        $this->assertSame(124, $after['unattributed_responses'] - $before['unattributed_responses']);
        unset($before['unattributed_responses'], $after['unattributed_responses']);
        $this->assertSame($before, $after, 'Anonymous feedback must not affect participants, submissions, held rows or deliveries.');
        $individual = app(FormResponseService::class);
        $this->assertSame([], $individual->columns($import->form->fresh()));
        $this->assertSame(0, $individual->query($import->form)->count());
        $this->assertSame(0, app(EligibilityService::class)->eligibleParticipantsQuery($this->webinar->fresh())->count());

        $responses = UnattributedResponse::query()->where('import_id', $import->id)->orderBy('row_number')->get();
        $this->assertSame(124, $responses->count());
        $this->assertSame(range(2, 125), $responses->pluck('row_number')->all());
        $this->assertSame(124, $responses->pluck('id')->unique()->count());
        $detailCaptureRow = $responses->first()->row_number;
        $longest = -1;
        foreach (array_slice($sourceRows, 1, null, true) as $sourceRow => $source) {
            $length = max(array_map('mb_strlen', $source));
            if ($length > $longest) {
                $longest = $length;
                $detailCaptureRow = $sourceRow;
            }
        }
        foreach ($responses as $response) {
            $sourceRow = $response->row_number;
            $this->context = 'evaluation unattributed stored row '.$sourceRow;
            $this->assertSame($this->webinar->id, (int) $response->webinar_id);
            $this->assertSame($import->form_id, (int) $response->form_id);
            $original = $response->originalCells($import);
            $this->assertSame(6, count($original));
            foreach ($sourceRows[$sourceRow] as $column => $value) {
                $this->compare($sourceRows[1][$column], $original[$column]['label'] ?? null, 'unattributed_stored_label', 'evaluation', $sourceRow, $column);
                $this->compare($value, $original[$column]['value'] ?? null, 'unattributed_stored_value', 'evaluation', $sourceRow, $column);
            }
            $this->assertTrue(json_decode(DB::table('unattributed_responses')->where('id', $response->id)->value('original_data'), true) === null, 'Unattributed originals must be encrypted.');
            $this->verifyUnattributedDetail($response, $sourceRows, $sourceRow === $detailCaptureRow);
        }
        $this->verifyUnattributedTable($import, $sourceRows);
        $this->verifyUnattributedExport($import, $sourceRows);
        $this->verifyUnattributedSummaries($import, $sourceRows);
        $this->proof['files']['evaluation']['outcomes'] = $review;
        $this->proof['files']['evaluation']['unattributed_saved_rows'] = $responses->count();
        $this->proof['files']['evaluation']['unattributed_details_verified'] = $responses->count();
        $this->proof['files']['evaluation']['individual_completions'] = 0;
    }

    private function verifyUnattributedDetail(UnattributedResponse $response, array $sourceRows, bool $capture): void
    {
        $sourceRow = $response->row_number;
        $this->context = 'evaluation unattributed detail row '.$sourceRow;
        $page = $this->get(route('admin.unattributed.show', [$this->webinar, $response]));
        $this->assertSame(200, $page->status());
        $dom = $this->html($page->getContent());
        $labels = $dom->query('//section//dl/div/dt');
        $cells = $dom->query('//section//dl/div/dd');
        $this->assertSame(6, $labels->length);
        $this->assertSame(6, $cells->length);
        $browserChecks = [];
        foreach ($sourceRows[$sourceRow] as $column => $value) {
            $this->compare($sourceRows[1][$column], $labels->item($column)?->textContent, 'unattributed_detail_label', 'evaluation', $sourceRow, $column);
            $display = $dom->query('./details/p | ./span[1]', $cells->item($column))->item(0)?->textContent;
            $this->compare($value === '' ? '—' : $value, $display, 'unattributed_detail_value', 'evaluation', $sourceRow, $column);
            $browserChecks[] = ['selector' => 'section.panel dl > div:nth-child('.($column + 1).') dt',
                'expected' => $sourceRows[1][$column], 'row' => 1, 'column' => $column + 1];
            $browserChecks[] = ['selector' => 'section.panel dl > div:nth-child('.($column + 1).') dd', 'content' => 'literal',
                'expected' => $value === '' ? '—' : $value, 'row' => $sourceRow, 'column' => $column + 1];
        }
        if ($capture) {
            $this->browserCapture?->page('evaluation-unattributed-detail', $page->getContent(), $browserChecks, 'unattributed-detail');
        }
    }

    private function verifyUnattributedTable(Import $import, array $sourceRows): void
    {
        $page = 1;
        $visited = [];
        do {
            $this->context = 'evaluation unattributed table page '.$page;
            $response = $this->get(route('admin.unattributed.index', [$this->webinar, 'import' => $import->id, 'page' => $page]));
            $this->assertSame(200, $response->status());
            $paginator = $response->viewData('rows');
            $columns = $response->viewData('columns');
            $this->assertSame(range(0, 5), array_column(array_values($columns), 'source_index'));
            $dom = $this->html($response->getContent());
            $labels = $dom->query('//table[contains(@class, "response-data-table")]/thead/tr/th[@data-question-column]/span');
            $this->assertSame(6, $labels->length);
            $browserChecks = [];
            foreach ($sourceRows[1] as $column => $label) {
                $this->compare($label, $labels->item($column)?->textContent, 'unattributed_table_header', 'evaluation', 1, $column);
                $browserChecks[] = ['selector' => '.response-data-table thead th:nth-child('.($column + 2).') span',
                    'expected' => $label, 'row' => 1, 'column' => $column + 1];
            }
            $rows = $dom->query('//table[contains(@class, "response-data-table")]/tbody/tr');
            $this->assertSame($paginator->count(), $rows->length);
            foreach ($paginator as $index => $saved) {
                $sourceRow = $saved->row_number;
                $this->assertFalse(isset($visited[$sourceRow]), 'Repeated anonymous source-row coordinate.');
                $visited[$sourceRow] = true;
                $cells = $dom->query('./td[@data-question-column]', $rows->item($index));
                $this->assertSame(6, $cells->length);
                foreach ($sourceRows[$sourceRow] as $column => $value) {
                    $text = $dom->query('./details/p | ./span[1]', $cells->item($column))->item(0)?->textContent;
                    $this->compare($value === '' ? '—' : $value, $text, 'unattributed_table_value', 'evaluation', $sourceRow, $column);
                    $this->compare($sourceRows[1][$column], $cells->item($column)?->getAttribute('data-label'), 'unattributed_table_label', 'evaluation', $sourceRow, $column);
                    $browserChecks[] = ['selector' => '.response-data-table tbody tr:nth-child('.($index + 1).') .response-answer-cell',
                        'index' => $column, 'content' => 'answer', 'expected' => $value === '' ? '—' : $value,
                        'label' => $sourceRows[1][$column], 'row' => $sourceRow, 'column' => $column + 1];
                }
            }
            $this->browserCapture?->page('evaluation-unattributed-responses-'.$page, $response->getContent(), $browserChecks, 'unattributed-responses');
            $page++;
        } while ($page <= $paginator->lastPage());
        $this->assertSame(range(2, 125), array_keys($visited));
        $this->assertSame(5, $page - 1);
        $this->proof['files']['evaluation']['rendered_unattributed_pages'] = $page - 1;
        $this->proof['files']['evaluation']['rendered_unattributed_rows'] = count($visited);
    }

    private function verifyUnattributedExport(Import $import, array $sourceRows): void
    {
        $this->context = 'evaluation unattributed exact CSV export';
        $response = $this->get(route('admin.unattributed.export', [$this->webinar, 'import' => $import->id]));
        $this->assertSame(200, $response->getStatusCode());
        $rows = $this->csvRows(preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent()));
        $this->assertSame(125, count($rows));
        $headers = ['Source row', ...$sourceRows[1], 'Original imported cells (JSON)'];
        foreach ($headers as $column => $label) {
            $this->compare($this->exportCell($label), $rows[1][$column] ?? null, 'unattributed_export_header', 'evaluation', 1, $column);
        }
        $visited = [];
        foreach (array_slice($rows, 1) as $row) {
            $this->assertSame(8, count($row));
            $sourceRow = (int) $row[0];
            $this->assertTrue($sourceRow >= 2 && $sourceRow <= 125 && ! isset($visited[$sourceRow]), 'Unexpected or repeated exported anonymous source-row coordinate.');
            $visited[$sourceRow] = true;
            $original = json_decode($row[7], true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(6, count($original));
            foreach ($sourceRows[$sourceRow] as $column => $value) {
                $this->compare($this->exportCell($value), $row[$column + 1], 'unattributed_export_cell', 'evaluation', $sourceRow, $column);
                $this->compare($sourceRows[1][$column], $original[$column]['label'] ?? null, 'unattributed_export_original_label', 'evaluation', $sourceRow, $column);
                $this->compare($value, $original[$column]['value'] ?? null, 'unattributed_export_original_value', 'evaluation', $sourceRow, $column);
            }
        }
        $this->assertSame(range(2, 125), array_keys($visited));
        $this->proof['files']['evaluation']['exported_unattributed_rows'] = count($visited);
    }

    private function verifyUnattributedSummaries(Import $import, array $sourceRows): void
    {
        $columns = app(UnattributedResponseService::class)->columns($import);
        foreach (array_keys($columns) as $column => $key) {
            $expected = [];
            foreach (array_slice($sourceRows, 1, null, true) as $sourceRow => $source) {
                $position = null;
                foreach ($expected as $index => $entry) {
                    if ($entry['value'] === $source[$column]) {
                        $position = $index;
                        break;
                    }
                }
                if ($position === null) {
                    $position = count($expected);
                    $expected[] = ['value' => $source[$column], 'count' => 0, 'source_row' => $sourceRow];
                }
                $expected[$position]['count']++;
            }
            $page = 1;
            $seen = 0;
            do {
                $this->context = 'evaluation literal summary column '.($column + 1).' page '.$page;
                $response = $this->get(route('admin.unattributed.index', [$this->webinar, 'import' => $import->id,
                    'view' => 'summary', 'column' => $key, 'values_page' => $page]));
                $this->assertSame(200, $response->status());
                $this->assertSame(['total' => 124, 'distinct' => count($expected)], $response->viewData('summary'));
                $paginator = $response->viewData('summaryRows');
                $dom = $this->html($response->getContent());
                $this->compare($sourceRows[1][$column], $dom->query('//table[contains(@class, "response-data-table")]/thead/tr/th[1]/span')->item(0)?->textContent,
                    'unattributed_summary_header', 'evaluation', 1, $column);
                $rows = $dom->query('//table[contains(@class, "response-data-table")]/tbody/tr');
                $this->assertSame($paginator->count(), $rows->length);
                $browserChecks = [['selector' => '.response-data-table thead th:first-child span', 'expected' => $sourceRows[1][$column], 'row' => 1, 'column' => $column + 1]];
                foreach ($paginator as $offset => $entry) {
                    $wanted = $expected[$seen];
                    $this->compare($wanted['value'], $entry['value'], 'unattributed_summary_value', 'evaluation', $wanted['source_row'], $column);
                    $this->assertSame($wanted['count'], $entry['count'], 'Literal summary count at column '.($column + 1).' value '.($seen + 1).'.');
                    $cell = $dom->query('./td[1]', $rows->item($offset))->item(0);
                    $display = $dom->query('./details/p | ./span[1]', $cell)->item(0)?->textContent;
                    $this->compare($wanted['value'] === '' ? '—' : $wanted['value'], $display, 'unattributed_summary_rendered_value', 'evaluation', $wanted['source_row'], $column);
                    $this->compare((string) $wanted['count'], $dom->query('./td[2]', $rows->item($offset))->item(0)?->textContent,
                        'unattributed_summary_rendered_count', 'evaluation', $wanted['source_row'], $column);
                    $browserChecks[] = ['selector' => '.response-data-table tbody tr:nth-child('.($offset + 1).') td:first-child', 'content' => 'literal',
                        'expected' => $wanted['value'] === '' ? '—' : $wanted['value'], 'row' => $wanted['source_row'], 'column' => $column + 1];
                    $browserChecks[] = ['selector' => '.response-data-table tbody tr:nth-child('.($offset + 1).') td:nth-child(2)',
                        'expected' => (string) $wanted['count'], 'row' => $wanted['source_row'], 'column' => $column + 1];
                    $seen++;
                }
                $this->browserCapture?->page('evaluation-unattributed-summary-'.($column + 1).'-'.$page, $response->getContent(), $browserChecks, 'unattributed-summary');
                $page++;
            } while ($page <= $paginator->lastPage());
            $this->assertSame(count($expected), $seen);
            $this->assertSame(124, array_sum(array_column($expected, 'count')));
            $this->proof['files']['evaluation']['literal_summaries'][] = ['column' => $column + 1, 'source_values' => 124, 'distinct_values' => count($expected), 'rendered_pages' => $page - 1];
        }
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
        $expectedColumns = $this->answerIndexes($headers, $this->sourceMaps[$type]);
        $answers = $dom->query('(//section//dl)[1]/div/dd');
        $answerLabels = $dom->query('(//section//dl)[1]/div/dt');
        $this->assertSame(count($expectedColumns), $answers->length, $type.' inspect mapped column inventory.');
        foreach ($expectedColumns as $offset => $column) {
            $this->compare($headers[$column], $answerLabels->item($offset)?->textContent, 'rendered_mapped_label', $type, $row, $column);
            $this->compare($source[$column] === '' ? 'No available answer' : $source[$column], $answers->item($offset)?->textContent, 'rendered_mapped', $type, $row, $column);
        }
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
        $visited = [];
        $importMap = Import::query()->where('form_id', $form->id)->where('status', Import::STATUS_COMMITTED)->sole()->column_map['map'];
        $expectedColumns = $this->answerIndexes($sourceRows[1], $importMap);
        do {
            $this->context = $type.' table page '.$page;
            $response = $this->get(route('admin.responses.index', [$this->webinar, 'type' => $type, 'page' => $page]));
            $this->assertSame(200, $response->status(), $type.' table status on page '.$page.'.');
            $paginator = $response->viewData('rows');
            $columns = $response->viewData('columns');
            $this->assertSame($expectedColumns, array_column(array_values($columns), 'source_index'), $type.' independent source column inventory.');
            $dom = $this->html($response->getContent());
            $browserChecks = [];
            $labels = $dom->query('//table[contains(concat(" ", normalize-space(@class), " "), " response-data-table ")]/thead/tr/th/span');
            $this->assertSame(count($columns), $labels->length, $type.' rendered source header count.');
            foreach (array_values($columns) as $column => $definition) {
                $this->compare($sourceRows[1][$definition['source_index']], $labels->item($column)?->textContent, 'rendered_table_header', $type, 1, $definition['source_index']);
                $browserChecks[] = ['selector' => '.response-data-table thead th:nth-child('.($column + 4).') span',
                    'expected' => $sourceRows[1][$expectedColumns[$column]], 'row' => 1, 'column' => $expectedColumns[$column] + 1];
            }
            $rows = $dom->query('//table[contains(concat(" ", normalize-space(@class), " "), " response-data-table ")]/tbody/tr');
            $this->assertSame($paginator->count(), $rows->length, $type.' rendered row count.');
            foreach ($paginator as $index => $submission) {
                $sourceRow = $submission->metadata['import_row_number'];
                $this->assertFalse(isset($visited[$sourceRow]), $type.' repeated source row '.$sourceRow.' across pages.');
                $visited[$sourceRow] = true;
                $source = $sourceRows[$sourceRow];
                $row = $rows->item($index);
                $name = $dom->query('./th/a', $row)->item(0)?->textContent;
                $email = $dom->query('./th/span[1]', $row)->item(0)?->textContent;
                if (isset($importMap['full_name'])) {
                    $this->compare($source[$importMap['full_name']], $name, 'rendered_source_name', $type, $sourceRow, $importMap['full_name']);
                }
                $this->compare($source[$importMap['email']], $email, 'rendered_source_email', $type, $sourceRow, $importMap['email']);
                $rowSelector = '.response-data-table tbody tr:nth-child('.($index + 1).')';
                $sourceEmail = mb_strtolower(trim($source[$importMap['email']]));
                $expectedName = isset($importMap['full_name']) ? $source[$importMap['full_name']] : $this->acceptedSources['registration'][$sourceEmail]['name'];
                $browserChecks[] = ['selector' => $rowSelector.' th > a', 'expected' => $expectedName, 'row' => $sourceRow, 'column' => 0];
                $browserChecks[] = ['selector' => $rowSelector.' th > span:first-of-type', 'expected' => $source[$importMap['email']],
                    'row' => $sourceRow, 'column' => $importMap['email'] + 1];
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
                    $browserChecks[] = ['selector' => '.response-data-table tbody tr:nth-child('.($index + 1).') .response-answer-cell',
                        'index' => $column, 'content' => 'answer', 'expected' => $source[$sourceColumn] === '' ? '—' : $source[$sourceColumn],
                        'label' => $sourceRows[1][$sourceColumn], 'row' => $sourceRow, 'column' => $sourceColumn + 1];
                }
                $shown++;
            }
            $this->browserCapture?->page($type.'-responses-'.$page, $response->getContent(), $browserChecks, 'responses');
            $page++;
        } while ($page <= $paginator->lastPage());
        $this->assertSame($form->submissions()->where('status', 'submitted')->count(), $shown, $type.' all paginated submissions rendered.');
        $expectedRows = array_column(array_values($this->acceptedSources[$type]), 'row');
        $actualRows = array_keys($visited);
        sort($expectedRows);
        sort($actualRows);
        $this->assertSame($expectedRows, $actualRows, $type.' exact unique source-row coverage.');
        $this->proof['files'][$type]['rendered_pages'] = $page - 1;
        $this->proof['files'][$type]['rendered_submissions'] = $shown;
    }

    private function answerIndexes(array $headers, array $map): array
    {
        $identity = array_values(array_diff_key($map, ['organization' => true]));

        return array_values(array_filter(array_keys($headers), fn ($index) => ! in_array($index, $identity, true)));
    }

    private function verifyPreview(Import $import, array $sourceRows, string $type): void
    {
        $this->context = $type.' exact parser preview';
        $next = route('admin.webinars.imports.map', [$this->webinar, $import]);
        $lastPage = (int) ceil((count($sourceRows) - 1) / CsvImportService::PREVIEW_ROWS);
        $visited = [];
        $page = 0;
        do {
            $page++;
            $this->assertLessThanOrEqual($lastPage, $page, $type.' preview must terminate.');
            $response = $this->get($next);
            $this->assertSame(200, $response->status());
            $preview = $response->viewData('previewRows');
            $pagination = $response->viewData('previewPagination');
            $this->assertSame($page, $pagination->currentPage());
            $this->assertSame(count($sourceRows) - 1, $pagination->total());
            $dom = $this->html($response->getContent());
            $table = '//table[@data-source-preview]';
            $labels = $dom->query($table.'/thead/tr/th[position() > 1]');
            $browserChecks = [];
            $this->assertSame(count($sourceRows[1]), $labels->length);
            foreach ($sourceRows[1] as $column => $header) {
                $this->compare($header, $labels->item($column)?->textContent, 'preview_header', $type, 1, $column);
                $browserChecks[] = ['selector' => '[data-source-preview] thead th:nth-child('.($column + 2).')',
                    'expected' => $header, 'row' => 1, 'column' => $column + 1];
            }
            $rows = $dom->query($table.'/tbody/tr');
            $this->assertSame(count($preview), $rows->length);
            foreach ($preview as $index => $row) {
                $sourceRow = ($page - 1) * CsvImportService::PREVIEW_ROWS + $index + 2;
                $this->assertSame($sourceRow, $row['row_number']);
                $this->assertFalse(isset($visited[$sourceRow]));
                $visited[$sourceRow] = true;
                $cells = $dom->query('./td[position() > 1]', $rows->item($index));
                $this->assertSame(count($sourceRows[1]), $cells->length);
                foreach ($sourceRows[$sourceRow] as $column => $value) {
                    $this->compare($value, $row['cells'][$column] ?? null, 'preview_decoded', $type, $sourceRow, $column);
                    $this->compare($value, $cells->item($column)?->textContent, 'preview_rendered', $type, $sourceRow, $column);
                    $browserChecks[] = ['selector' => '[data-source-preview] tbody tr:nth-child('.($index + 1).') td:nth-child('.($column + 2).')',
                        'expected' => $value, 'row' => $sourceRow, 'column' => $column + 1];
                }
            }
            // All anonymous evaluation rows get browser geometry checks. Other
            // sources already have every accepted/held row in their real tables.
            if ($type === 'evaluation' || $page === 1 || $page === $lastPage) {
                $this->browserCapture?->page($type.'-preview-'.$page, $response->getContent(), $browserChecks, 'preview');
            }
            $next = $dom->query('//a[@rel="next"]/@href')->item(0)?->nodeValue;
        } while ($next !== null);
        $this->assertSame(range(2, count($sourceRows)), array_keys($visited), $type.' every source row previewed exactly once.');
        $analysis = $import->column_map['analysis'];
        $this->assertSame(count($sourceRows) - 1, $analysis['rows']);
        $this->assertSame(count($sourceRows[1]), $analysis['column_count']);
        foreach ($sourceRows[1] as $column => $header) {
            $empty = count(array_filter(array_slice($sourceRows, 1), fn ($row) => trim($row[$column]) === ''));
            $this->assertSame($empty, $analysis['columns'][$column]['empty'], $type.' source blank count for column '.$column.'.');
            $this->assertSame(count($sourceRows) - 1 - $empty, $analysis['columns'][$column]['filled']);
        }
        $this->proof['files'][$type]['previewed_rows'] = count($visited);
        $this->proof['files'][$type]['preview_pages'] = $page;
        $this->proof['files'][$type]['all_rows_analyzed'] = true;
    }

    private function verifyExport($form, array $sourceRows, string $type): void
    {
        $this->context = $type.' exact CSV export';
        $response = $this->get(route('admin.responses.export', [$this->webinar, 'type' => $type]));
        $this->assertSame(200, $response->getStatusCode());
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $response->streamedContent());
        $rows = $this->csvRows($csv);
        $map = $this->sourceMaps[$type];
        $columns = $this->answerIndexes($sourceRows[1], $map);
        $headers = ['Name', 'Email', 'Submitted at (UTC)', 'Source', 'Status', 'Score',
            ...array_map(fn ($index) => $sourceRows[1][$index], $columns), 'Original imported cells (JSON)'];
        $this->assertSame(count($this->acceptedSources[$type]) + 1, count($rows));
        foreach ($headers as $column => $label) {
            $this->compare($this->exportCell($label), $rows[1][$column] ?? null, 'export_header', $type, 1, $column);
        }
        $visited = [];
        foreach (array_slice($rows, 1) as $row) {
            $this->assertSame(count($headers), count($row), $type.' export record width.');
            $original = json_decode($row[count($row) - 1], true, flags: JSON_THROW_ON_ERROR);
            $email = mb_strtolower(trim((string) ($original[$map['email']]['value'] ?? '')));
            $evidence = $this->acceptedSources[$type][$email] ?? null;
            $this->assertTrue($evidence !== null, $type.' unexpected exported identity.');
            $sourceRow = $evidence['row'];
            $this->assertFalse(isset($visited[$sourceRow]), $type.' repeated export source row '.$sourceRow.'.');
            $visited[$sourceRow] = true;
            $source = $sourceRows[$sourceRow];
            $name = isset($map['full_name']) ? $source[$map['full_name']] : $this->acceptedSources['registration'][$email]['name'];
            $time = (new DateTimeImmutable($source[$map['submitted_at']], new DateTimeZone('Asia/Manila')))->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:sP');
            $expected = [$name, $source[$map['email']], $time, 'Imported', 'submitted',
                $evidence['score'] === null ? '' : number_format($evidence['score'], 2, '.', ''),
                ...array_map(fn ($index) => $source[$index], $columns)];
            foreach ($expected as $column => $value) {
                $this->compare($this->exportCell($value), $row[$column], 'export_cell', $type, $sourceRow, $column);
            }
            foreach ($source as $column => $value) {
                $this->compare($sourceRows[1][$column], $original[$column]['label'] ?? null, 'export_original_label', $type, $sourceRow, $column);
                $this->compare($value, $original[$column]['value'] ?? null, 'export_original', $type, $sourceRow, $column);
            }
        }
        $expectedRows = array_column(array_values($this->acceptedSources[$type]), 'row');
        $actualRows = array_keys($visited);
        sort($expectedRows);
        sort($actualRows);
        $this->assertSame($expectedRows, $actualRows, $type.' exact export source-row coverage.');
        $this->proof['files'][$type]['exported_rows'] = count($visited);
    }

    private function exportCell(string $value): string
    {
        return preg_match('/^[\p{Z}\x00-\x20]*[=+\-@]/u', $value) === 1 ? "'".$value : $value;
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
        $browserChecks = [];
        $this->assertSame($issues->count(), $rows->length);
        foreach ($issues as $index => $issue) {
            $values = $dom->query('.//details[summary[text()="Inspect source values"]]/dl/div/dd', $rows->item($index));
            $source = $sourceRows[$issue->row_number];
            $this->assertSame(count($source), $values->length);
            foreach ($source as $column => $value) {
                $this->compare($value, $values->item($column)?->textContent, 'rendered_held_original', $type, $issue->row_number, $column);
                $browserChecks[] = ['selector' => '[data-source-values]', 'index' => $index, 'content' => 'held', 'cell' => $column,
                    'expected' => $value, 'row' => $issue->row_number, 'column' => $column + 1];
            }
        }
        $this->browserCapture?->page($type.'-held', $response->getContent(), $browserChecks, 'held');
    }

    private function stateCounts(): array
    {
        return collect(['participants', 'submissions', 'submission_answers', 'import_issues', 'unattributed_responses', 'certificates', 'certificate_approvals', 'certificate_batches', 'email_deliveries', 'jobs', 'failed_jobs'])
            ->mapWithKeys(fn ($table) => [$table => DB::table($table)->count()])->all();
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
