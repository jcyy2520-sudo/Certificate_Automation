<?php

namespace Tests\Feature;

use App\Models\UnattributedResponse;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\EligibilityService;
use App\Services\UnattributedResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

/** Synthetic supported-bound measurements, never a hosted capacity guarantee. */
class UnattributedImportCapacityTest extends TestCase
{
    use RefreshDatabase {
        refreshDatabase as private refreshMemoryDatabase;
    }

    public function refreshDatabase(): void
    {
        if (! $this->app->environment('testing')) {
            throw new RuntimeException('Synthetic capacity verification requires the testing environment.');
        }
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:', 'database.connections.sqlite.url' => null]);
        $this->refreshMemoryDatabase();
    }

    public function test_1499_rows_by_200_columns_with_long_headers_remain_bounded(): void
    {
        $this->measureCsv(1499, 200, 1, 1499);
    }

    public function test_4999_rows_by_60_columns_with_long_headers_remain_bounded(): void
    {
        $this->measureCsv(4999, 60, 2, 2500);
    }

    private function measureCsv(int $rowCount, int $width, int $expectedPageRows, int $expectedPages): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Storage::fake('local');
        Http::preventStrayRequests();
        Http::fake();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        Bus::fake();
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($owner);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $form = $webinar->forms()->create(['type' => 'evaluation', 'title' => 'Synthetic capacity evaluation']);
        $webinar->eligibilityRules()->create(['requirement' => 'evaluation', 'is_required' => true]);
        $headers = [];
        $values = [];
        for ($column = 0; $column < $width; $column++) {
            $headers[] = str_pad('Synthetic question '.$column.' ', 2000, 'h');
            $values[] = ['0', '', 'FALSE', '  v  '][$column % 4];
        }
        $out = fopen('php://temp', 'w+b');
        fputcsv($out, $headers, ',', '"', '');
        for ($row = 0; $row < $rowCount; $row++) {
            fputcsv($out, $values, ',', '"', '');
        }
        rewind($out);
        $contents = stream_get_contents($out);
        fclose($out);
        $file = UploadedFile::fake()->createWithContent('synthetic-capacity.csv', $contents);
        $fileBytes = strlen($contents);
        unset($contents);
        $this->assertSame(300000, ($rowCount + 1) * $width);
        $service = app(CsvImportService::class);
        $options = ['response_mode' => 'unattributed', 'source_description' => 'Synthetic supported-bound capacity check', 'source_confirmed' => true];
        $measure = [];
        foreach (['pending', 'dry_check', 'commit', 'table', 'table_next', 'summary', 'streamed_export'] as $phase) {
            memory_reset_peak_usage();
            $started = hrtime(true);
            if ($phase === 'pending') {
                $import = $service->storePending($webinar, $form, $file);
            } elseif ($phase === 'dry_check') {
                $counts = $service->process($import, [], dryRun: true, options: $options);
                $this->assertSame($rowCount, $counts['valid']);
                $this->assertDatabaseCount('unattributed_responses', 0);
            } elseif ($phase === 'commit') {
                $counts = $service->process($import, [], options: $options);
                $this->assertSame(['total' => $rowCount, 'valid' => $rowCount, 'invalid' => 0, 'duplicate' => 0, 'unmatched' => 0], $counts);
                $import->refresh();
            } elseif ($phase === 'table') {
                $response = $this->get(route('admin.unattributed.index', [$webinar, 'import' => $import->id]));
                $response->assertOk();
                $this->assertSame($rowCount, $response->viewData('rows')->total());
                $this->assertSame($expectedPageRows, $response->viewData('rows')->count());
                $this->assertSame($expectedPages, $response->viewData('rows')->lastPage());
                $firstPageRows = $response->viewData('rows')->getCollection()->pluck('row_number')->all();
                $this->assertSame(range(2, $expectedPageRows + 1), $firstPageRows);
                $this->assertCount($width, $response->viewData('columns'));
                $tableBytes = strlen($response->getContent());
                $this->assertSame(1, preg_match('/<a\b[^>]*href="([^"]+)"[^>]*rel="next"[^>]*>/', $response->getContent(), $nextLink));
                $nextUrl = html_entity_decode($nextLink[1], ENT_QUOTES | ENT_HTML5, 'UTF-8');
                unset($response);
            } elseif ($phase === 'table_next') {
                $response = $this->get($nextUrl);
                $response->assertOk();
                $this->assertSame($rowCount, $response->viewData('rows')->total());
                $this->assertSame($expectedPageRows, $response->viewData('rows')->count());
                $nextPageRows = $response->viewData('rows')->getCollection()->pluck('row_number')->all();
                $this->assertSame(range(2 + $expectedPageRows, 1 + 2 * $expectedPageRows), $nextPageRows);
                $this->assertSame(2 * $expectedPageRows, count(array_unique([...$firstPageRows, ...$nextPageRows])));
                $this->assertCount($width, $response->viewData('columns'));
                unset($response);
            } elseif ($phase === 'summary') {
                $summary = app(UnattributedResponseService::class)->summary($import, ['source_index' => 0]);
                $this->assertSame(['total' => $rowCount, 'counts' => [['value' => '0', 'count' => $rowCount]]], $summary);
            } else {
                $download = $this->get(route('admin.unattributed.export', [$webinar, 'import' => $import->id]));
                $download->assertOk();
                $wireBytes = 0;
                $wireRows = 0;
                $hash = hash_init('sha256');
                ob_start(static function (string $chunk) use (&$wireBytes, &$wireRows, $hash): string {
                    $wireBytes += strlen($chunk);
                    $wireRows += substr_count($chunk, "\n");
                    hash_update($hash, $chunk);

                    return '';
                }, 8192);
                try {
                    // Consume the full stream without collecting or saving CSV bytes.
                    $download->baseResponse->sendContent();
                } finally {
                    ob_end_clean();
                }
                $wireDigest = hash_final($hash);
                $this->assertSame($rowCount + 1, $wireRows);
                $this->assertGreaterThan($rowCount * $width * 2000, $wireBytes);
                unset($download);
            }
            $measure[$phase] = ['elapsed_seconds' => round((hrtime(true) - $started) / 1000000000, 4), 'peak_allocated_bytes' => memory_get_peak_usage(true)];
        }
        $this->assertDatabaseCount('unattributed_responses', $rowCount);
        $this->assertSame($rowCount, UnattributedResponse::query()->where('import_id', $import->id)->distinct()->count('row_number'));
        $first = UnattributedResponse::query()->where('import_id', $import->id)->orderBy('row_number')->firstOrFail();
        $last = UnattributedResponse::query()->where('import_id', $import->id)->orderByDesc('row_number')->firstOrFail();
        $this->assertSame(2, $first->row_number);
        $this->assertSame($rowCount + 1, $last->row_number);
        $this->assertSame($values, $first->original_data);
        $this->assertSame($values, $last->original_data);
        $this->assertSame($headers, array_column($last->originalCells($import), 'label'));
        $cipherBytes = (int) DB::table('unattributed_responses')->where('import_id', $import->id)->selectRaw('SUM(LENGTH(original_data)) AS bytes')->value('bytes');
        $metadataBytes = strlen(DB::table('imports')->where('id', $import->id)->value('column_map'));
        $this->assertLessThan($rowCount * ($width * 32 + 2048), $cipherBytes);
        $this->assertLessThan($width * 2256 + 65536, $metadataBytes);
        foreach (['participants', 'submissions', 'submission_answers', 'certificate_approvals', 'certificates', 'certificate_batches', 'email_deliveries', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertSame(0, app(EligibilityService::class)->eligibleParticipantsQuery($webinar)->count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        Notification::assertNothingSent();
        Queue::assertNothingPushed();
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
        Storage::disk('local')->assertMissing('imports-pending/import-'.$import->id.'.csv');
        if (getenv('UNATTRIBUTED_CAPACITY_REPORT') === '1') {
            $directory = storage_path('framework/testing/production-final-20261024');
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.'/unattributed-capacity-'.$rowCount.'x'.$width.'.json', json_encode([
                'format' => 'csv', 'response_rows' => $rowCount, 'columns' => $width, 'cells_including_header' => ($rowCount + 1) * $width,
                'header_characters_each' => 2000, 'source_bytes' => $fileBytes, 'row_ciphertext_bytes' => $cipherBytes,
                'import_metadata_bytes' => $metadataBytes, 'first_table_html_bytes' => $tableBytes,
                'rows_per_table_page' => $expectedPageRows, 'table_pages' => $expectedPages,
                'first_two_pages_unique_source_rows' => 2 * $expectedPageRows,
                'export_wire_bytes' => $wireBytes, 'export_csv_records' => $wireRows, 'export_sha256' => $wireDigest,
                'row_count' => $rowCount, 'eligible_participants' => 0, 'side_effects' => 0, 'measurements' => $measure,
                'evidence_scope' => 'Local PHP 8.3 and SQLite memory; synthetic short literal values; no hosted capacity or PostgreSQL guarantee.',
            ], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT).PHP_EOL);
        }
    }
}
