<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\UnattributedResponse;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\EligibilityService;
use App\Services\FormResponseService;
use App\Services\ParticipantPrivacyService;
use App\Services\UnattributedResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class UnattributedEvaluationImportTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    private Form $form;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($this->owner);
        $this->webinar = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $this->form = $this->webinar->forms()->create(['type' => 'evaluation', 'title' => 'Synthetic evaluation']);

    }

    private function pending(array $headers, array $rows, ?Form $form = null): Import
    {
        $out = fopen('php://temp', 'w+b');
        foreach ([$headers, ...$rows] as $row) {
            fputcsv($out, $row, ',', '"', '');
        }
        rewind($out);
        $contents = stream_get_contents($out);
        fclose($out);

        return app(CsvImportService::class)->storePending($this->webinar, $form ?? $this->form, UploadedFile::fake()->createWithContent('synthetic.csv', $contents));
    }

    private function importOptions(array $extra = []): array
    {
        return $extra + ['response_mode' => 'unattributed', 'source_description' => 'Synthetic evaluation export', 'source_confirmed' => true];
    }

    public function test_saves_distinct_literal_rows_without_participant_attribution_or_completion(): void
    {
        $this->webinar->eligibilityRules()->create(['requirement' => 'evaluation', 'is_required' => true]);
        $participant = $this->webinar->participants()->create(['email' => 'synthetic@example.test', 'full_name' => 'Synthetic Person', 'verified_at' => now()]);
        $headers = ['Timestamp', 'Name', 'Score', '  Feedback  ', 'Blank', 'Literal'];
        $cells = ['not a date', 'Do not attribute', 'not points', "  great\n\t ", '', '=2+2'];
        $import = $this->pending($headers, [$cells, $cells]);
        $counts = app(CsvImportService::class)->process($import, ['submitted_at' => 0, 'full_name' => 1, 'score' => 2], options: $this->importOptions());

        $this->assertSame(['total' => 2, 'valid' => 2, 'invalid' => 0, 'duplicate' => 0, 'unmatched' => 0], $counts);
        $rows = UnattributedResponse::query()->orderBy('row_number')->get();
        $this->assertSame([2, 3], $rows->pluck('row_number')->all());
        $this->assertNotSame($rows[0]->id, $rows[1]->id);
        $expected = [
            ['label' => 'Timestamp', 'value' => 'not a date'], ['label' => 'Name', 'value' => 'Do not attribute'],
            ['label' => 'Score', 'value' => 'not points'], ['label' => '  Feedback  ', 'value' => "  great\n\t "],
            ['label' => 'Blank', 'value' => ''], ['label' => 'Literal', 'value' => '=2+2'],
        ];
        $this->assertSame($expected, $rows[0]->originalCells($import->fresh()));
        $this->assertSame($expected, $rows[1]->originalCells($import->fresh()));
        $this->assertStringNotContainsString('Do not attribute', DB::table('unattributed_responses')->value('original_data'));
        $this->assertSame('unattributed', $import->fresh()->column_map['options']['response_mode']);
        $this->assertCount(6, app(UnattributedResponseService::class)->columns($import->fresh()));
        $this->assertDatabaseCount('participants', 1);
        foreach (['submissions', 'submission_answers', 'certificate_approvals', 'certificates', 'email_deliveries', 'jobs'] as $table) {
            $this->assertDatabaseCount($table, 0);
        }
        $this->assertFalse(app(EligibilityService::class)->evaluate($participant->fresh())['eligible']);
        Storage::disk('local')->assertMissing('imports-pending/import-'.$import->id.'.csv');
    }

    public function test_individual_mode_stays_default_and_other_form_types_cannot_be_unattributed(): void
    {
        $import = $this->pending(['Feedback'], [['Good']]);
        try {
            app(CsvImportService::class)->process($import, [], options: ['source_description' => 'Synthetic export', 'source_confirmed' => true]);
            $this->fail('An individual evaluation without email must still be rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('email', $exception->getMessage());
        }
        $this->assertSame('individual', CsvImportService::responseMode($import, null));
        foreach (['registration', 'pretest', 'posttest'] as $type) {
            $form = $this->webinar->forms()->create(['type' => $type, 'title' => $type]);
            $other = $this->pending(['Feedback'], [['Good']], $form);
            try {
                app(CsvImportService::class)->process($other, [], options: $this->importOptions());
                $this->fail('Unattributed mode is evaluation-only.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('evaluation', $exception->getMessage());
            }
        }
        foreach (['unknown', '', [], 1] as $mode) {
            try {
                CsvImportService::responseMode($import, $mode);
                $this->fail('An unsupported response mode must be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('response', strtolower($exception->getMessage()));
            }
        }
        $this->assertDatabaseCount('unattributed_responses', 0);
    }

    public function test_dry_check_and_failed_transaction_leave_no_rows_and_retry_cannot_duplicate_a_committed_import(): void
    {
        $import = $this->pending(['Feedback'], [['Same'], ['Same']]);
        $service = app(CsvImportService::class);
        $counts = $service->process($import, [], dryRun: true, options: $this->importOptions());
        $this->assertSame(2, $counts['valid']);
        $this->assertDatabaseCount('unattributed_responses', 0);
        $this->assertSame(Import::STATUS_PENDING, $import->fresh()->status);
        $this->assertSame([], $counts['sample']);
        Storage::disk('local')->assertExists('imports-pending/import-'.$import->id.'.csv');

        UnattributedResponse::creating(function (UnattributedResponse $response): void {
            if ($response->row_number === 3) {
                throw new RuntimeException('Synthetic transaction failure');
            }
        });
        try {
            $service->process($import, [], options: $this->importOptions());
            $this->fail('The synthetic write failure must abort the import.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic transaction failure', $exception->getMessage());
        } finally {
            UnattributedResponse::flushEventListeners();
        }
        $this->assertDatabaseCount('unattributed_responses', 0);
        $this->assertSame(Import::STATUS_PENDING, $import->fresh()->status);
        $this->assertSame(2, $service->process($import->fresh(), [], options: $this->importOptions())['valid']);
        try {
            $service->process($import->fresh(), [], options: $this->importOptions());
            $this->fail('A committed upload must never be imported twice.');
        } catch (RuntimeException) {
            $this->assertDatabaseCount('unattributed_responses', 2);
        }
    }

    public function test_malformed_rows_are_held_with_exact_source_cells_and_blank_values_are_retained(): void
    {
        $import = $this->pending(['Answer', 'Optional'], [['', '  '], ['only one'], ['one', 'two', 'overflow']]);
        $counts = app(CsvImportService::class)->process($import, [], options: $this->importOptions());
        $this->assertSame(['total' => 3, 'valid' => 1, 'invalid' => 2, 'duplicate' => 0, 'unmatched' => 0], $counts);
        $this->assertSame([['label' => 'Answer', 'value' => ''], ['label' => 'Optional', 'value' => '  ']], UnattributedResponse::query()->sole()->originalCells($import->fresh()));
        $issues = $import->issues()->orderBy('row_number')->get();
        $this->assertSame([3, 4], $issues->pluck('row_number')->all());
        $this->assertSame([ImportIssue::TYPE_INVALID_ROW_STRUCTURE, ImportIssue::TYPE_INVALID_ROW_STRUCTURE], $issues->pluck('issue_type')->all());
        $this->assertSame(['Answer' => 'only one'], $issues[0]->raw_data);
        $this->assertSame(['Answer' => 'one', 'Optional' => 'two', 'column 3' => 'overflow'], $issues[1]->raw_data);
        $this->assertNull($issues[0]->normalized_email);
    }

    public function test_literal_summary_keeps_whitespace_zero_and_blank_answers_distinct(): void
    {
        $import = $this->pending(['Literal answer'], [['Yes'], ['Yes'], [' Yes '], ['0'], [''], [' ']]);
        app(CsvImportService::class)->process($import, [], options: $this->importOptions());
        $responses = app(UnattributedResponseService::class);
        $columns = $responses->columns($import->fresh());
        $summary = $responses->summary($import->fresh(), reset($columns));
        $this->assertSame(6, $summary['total']);
        $this->assertSame([
            ['value' => 'Yes', 'count' => 2], ['value' => ' Yes ', 'count' => 1], ['value' => '0', 'count' => 1],
            ['value' => '', 'count' => 1], ['value' => ' ', 'count' => 1],
        ], $summary['counts']);
    }

    public function test_workspace_paginates_every_row_and_detail_and_export_preserve_full_originals(): void
    {
        $headers = ['Timestamp', '  Exact long feedback  ', 'Formula'];
        $long = str_repeat('Synthetic exact \\" UTF8 é 🌱 ', 100);
        $cells = ['uninterpreted date', $long."\n end  ", ' =2+2'];
        $import = $this->pending($headers, array_fill(0, 28, $cells));
        app(CsvImportService::class)->process($import, ['submitted_at' => 0], options: $this->importOptions());
        $first = $this->get(route('admin.unattributed.index', [$this->webinar, 'import' => $import->id]));
        $first->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 25 && $rows->total() === 28)->assertSee($long)->assertSee('data-question-column=', false);
        $second = $this->get(route('admin.unattributed.index', [$this->webinar, 'import' => $import->id, 'page' => 2]));
        $second->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 3 && $rows->first()->row_number === 27);
        $this->get(route('admin.unattributed.show', [$this->webinar, UnattributedResponse::query()->firstOrFail()]))->assertOk()->assertSee($long)->assertSee('uninterpreted date');
        $export = $this->get(route('admin.unattributed.export', [$this->webinar, 'import' => $import->id]));
        $export->assertOk()->assertDownload();
        $out = fopen('php://temp', 'w+b');
        fwrite($out, preg_replace('/^\xEF\xBB\xBF/', '', $export->streamedContent()));
        rewind($out);
        $exportHeaders = fgetcsv($out, 0, ',', '"', '');
        $this->assertSame(['Source row', ...$headers, 'Original imported cells (JSON)'], $exportHeaders);
        $seen = [];
        while (($row = fgetcsv($out, 0, ',', '"', '')) !== false) {
            $seen[] = (int) $row[0];
            $this->assertSame($cells[1], $row[2]);
            $this->assertSame("' =2+2", $row[3]);
            $this->assertSame([
                ['label' => 'Timestamp', 'value' => 'uninterpreted date'], ['label' => '  Exact long feedback  ', 'value' => $cells[1]],
                ['label' => 'Formula', 'value' => ' =2+2'],
            ], json_decode($row[4], true, flags: JSON_THROW_ON_ERROR));
        }
        fclose($out);
        $this->assertSame(range(2, 29), $seen);
    }

    public function test_foreign_import_response_and_users_without_participant_permission_cannot_read_values(): void
    {
        $import = $this->pending(['Answer'], [['Private synthetic answer']]);
        app(CsvImportService::class)->process($import, [], options: $this->importOptions());
        $response = UnattributedResponse::query()->sole();
        $other = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $this->get(route('admin.unattributed.show', [$other, $response]))->assertNotFound();
        $this->get(route('admin.unattributed.index', [$other, 'import' => $import->id]))->assertNotFound();
        $this->get(route('admin.unattributed.export', [$other, 'import' => $import->id]))->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => User::USER_ROLE]));
        $this->get(route('admin.unattributed.index', $this->webinar))->assertNotFound();
        $manager = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $manager->assignedWebinars()->attach($this->webinar->id, ['manage_participants' => false, 'manage_certificates' => true, 'send_emails' => false, 'assigned_by' => $this->owner->id]);
        $this->actingAs($manager);
        $this->get(route('admin.unattributed.index', $this->webinar))->assertForbidden();
        $this->get(route('admin.unattributed.show', [$this->webinar, $response]))->assertForbidden();
        $this->get(route('admin.unattributed.export', [$this->webinar, 'import' => $import->id]))->assertForbidden();
    }

    public function test_retention_removes_unattributed_cells_and_foreign_rows_survive(): void
    {
        $import = $this->pending(['Answer'], [['Private synthetic answer']]);
        app(CsvImportService::class)->process($import, [], options: $this->importOptions());
        $other = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $form = $other->forms()->create(['type' => 'evaluation', 'title' => 'Other']);
        $otherImport = Import::query()->create(['webinar_id' => $other->id, 'form_id' => $form->id, 'status' => Import::STATUS_COMMITTED, 'original_filename' => 'synthetic.csv', 'file_hash' => str_repeat('a', 64), 'file_size' => 1, 'imported_by' => $this->owner->id]);
        UnattributedResponse::query()->create(['webinar_id' => $other->id, 'form_id' => $form->id, 'import_id' => $otherImport->id, 'row_number' => 2, 'original_data' => ['Other answer']]);
        app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);
        $this->assertSame([$otherImport->id], UnattributedResponse::query()->pluck('import_id')->all());
        $this->assertNotNull($import->fresh()->privacy_erased_at);
        $this->get(route('admin.unattributed.export', [$this->webinar, 'import' => $import->id]))->assertNotFound();
        // A repeated retention run also cleans rows left by an interrupted sweep.
        UnattributedResponse::query()->create(['webinar_id' => $this->webinar->id, 'form_id' => $this->form->id, 'import_id' => $import->id, 'row_number' => 4, 'original_data' => ['Stranded']]);
        app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);
        $this->assertSame([$otherImport->id], UnattributedResponse::query()->pluck('import_id')->all());
        $otherImport->delete();
        $this->assertDatabaseCount('unattributed_responses', 0);
    }

    public function test_literal_summary_and_import_history_paginate_without_omitting_values_or_schemas(): void
    {
        $rows = [];
        for ($index = 0; $index < 28; $index++) {
            $rows[] = ['Literal '.$index];
        }
        $import = $this->pending(['  Exact source heading  '], $rows);
        app(CsvImportService::class)->process($import, [], options: $this->importOptions());
        $this->get(route('admin.unattributed.index', [$this->webinar, 'import' => $import->id, 'view' => 'summary']))
            ->assertOk()->assertViewHas('summary', ['total' => 28, 'distinct' => 28])
            ->assertViewHas('summaryRows', fn ($values) => $values->count() === 25 && $values->first()['value'] === 'Literal 0');
        $this->get(route('admin.unattributed.index', [$this->webinar, 'import' => $import->id, 'view' => 'summary', 'values_page' => 2]))
            ->assertOk()->assertViewHas('summaryRows', fn ($values) => $values->count() === 3 && $values->first()['value'] === 'Literal 25' && $values->last()['value'] === 'Literal 27');
        for ($index = 0; $index < 11; $index++) {
            $later = $this->pending(['Another heading '.$index], [['Another synthetic value']]);
            app(CsvImportService::class)->process($later, [], options: $this->importOptions());
        }
        $this->get(route('admin.unattributed.index', [$this->webinar, 'import' => $import->id, 'imports_page' => 2]))
            ->assertOk()->assertViewHas('imports', fn ($imports) => $imports->count() === 2 && $imports->total() === 12)
            ->assertViewHas('columns', fn ($columns) => array_column($columns, 'label') === ['  Exact source heading  '])
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 28);
    }

    public function test_retention_deadline_blocks_new_imports_and_all_saved_value_routes(): void
    {
        $import = $this->pending(['Answer'], [['Synthetic value']]);
        app(CsvImportService::class)->process($import, [], options: $this->importOptions());
        $response = UnattributedResponse::query()->sole();
        $pending = $this->pending(['Answer'], [['A later value']]);
        $this->webinar->forceFill(['ends_at' => now()->subDays(2), 'data_retention_days' => 1])->save();
        foreach ([
            route('admin.unattributed.index', $this->webinar),
            route('admin.unattributed.index', [$this->webinar, 'view' => 'summary']),
            route('admin.unattributed.show', [$this->webinar, $response]),
            route('admin.unattributed.export', [$this->webinar, 'import' => $import->id]),
        ] as $url) {
            $this->get($url)->assertNotFound();
        }
        try {
            app(CsvImportService::class)->process($pending->fresh(), [], options: $this->importOptions());
            $this->fail('Expired webinars must not retain new response values.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('retention', $exception->getMessage());
        }
        $this->assertDatabaseCount('unattributed_responses', 1);
        $this->assertSame(Import::STATUS_PENDING, $pending->fresh()->status);
        $this->artisan('privacy:erase-expired-participants')->assertSuccessful();
        $this->assertDatabaseCount('unattributed_responses', 0);
        $this->assertNotNull($import->fresh()->privacy_erased_at);
        $this->assertSame(Import::STATUS_CANCELLED, $pending->fresh()->status);
    }

    public function test_upload_only_and_administrator_locked_webinars_keep_their_access_boundary(): void
    {
        $import = $this->pending(['Answer'], [['Synthetic value']]);
        app(CsvImportService::class)->process($import, [], options: $this->importOptions());
        $response = UnattributedResponse::query()->sole();
        $this->webinar->forceFill(['admin_locked_at' => now(), 'admin_locked_by' => User::factory()->create()->id])->save();
        $this->get(route('admin.unattributed.index', $this->webinar))->assertStatus(423);
        $this->get(route('admin.unattributed.show', [$this->webinar, $response]))->assertStatus(423);
        $this->get(route('admin.unattributed.export', [$this->webinar, 'import' => $import->id]))->assertStatus(423);
        $this->webinar->forceFill(['admin_locked_at' => null, 'admin_locked_by' => null, 'creation_mode' => 'uploads'])->save();
        $this->get(route('admin.unattributed.index', $this->webinar))->assertNotFound();
        $this->get(route('admin.unattributed.show', [$this->webinar, $response]))->assertNotFound();
        $this->get(route('admin.unattributed.export', [$this->webinar, 'import' => $import->id]))->assertNotFound();
    }

    public function test_unattributed_imports_do_not_add_columns_or_values_to_individual_responses(): void
    {
        $field = $this->form->fields()->create(['key' => 'native_answer', 'label' => 'Native answer', 'field_type' => 'text']);
        $import = $this->pending(['Separate anonymous source column'], [['Literal anonymous value']]);
        app(CsvImportService::class)->process($import, [], options: $this->importOptions());
        $responses = app(FormResponseService::class);
        $columns = $responses->columns($this->form->fresh());
        $this->assertSame(['field_'.$field->id], array_keys($columns));
        $summary = $responses->summary($this->form, $columns);
        $this->assertSame(0, $summary['total']);
        $this->assertSame(0, $summary['imported']);
        $this->assertSame(['field_'.$field->id], array_keys($summary['questions']));
    }

    public function test_encrypted_row_storage_does_not_repeat_long_headers_for_each_response(): void
    {
        $shortHeaders = [];
        $longHeaders = [];
        for ($column = 0; $column < 200; $column++) {
            $shortHeaders[] = 'Column '.$column;
            $longHeaders[] = str_pad('Column '.$column.' ', 2000, 'h');
        }
        $values = array_fill(0, 200, 'v');
        $short = $this->pending($shortHeaders, array_fill(0, 50, $values));
        app(CsvImportService::class)->process($short, [], options: $this->importOptions());
        $shortBytes = (int) DB::table('unattributed_responses')->where('import_id', $short->id)->selectRaw('SUM(LENGTH(original_data)) AS bytes')->value('bytes');
        $long = $this->pending($longHeaders, array_fill(0, 50, $values));
        app(CsvImportService::class)->process($long, [], options: $this->importOptions());
        $longBytes = (int) DB::table('unattributed_responses')->where('import_id', $long->id)->selectRaw('SUM(LENGTH(original_data)) AS bytes')->value('bytes');
        $this->assertLessThan(250000, $longBytes, 'Fifty rows of short values must not persist forty million bytes of repeated headers.');
        $this->assertLessThanOrEqual($shortBytes + 50 * 128, $longBytes, 'Ciphertext size must depend on values rather than source-header lengths.');
        $row = UnattributedResponse::query()->where('import_id', $long->id)->firstOrFail();
        $original = $row->originalCells($long->fresh());
        $this->assertSame($longHeaders, array_column($original, 'label'));
        $this->assertSame($values, array_column($original, 'value'));
        $this->assertSame($values, $row->original_data);
        $metadata = json_encode($long->fresh()->column_map, JSON_THROW_ON_ERROR);
        $this->assertSame(1, substr_count($metadata, $longHeaders[0]), 'Committed source headers have one canonical copy.');
        $detail = $this->get(route('admin.unattributed.show', [$this->webinar, $row]));
        $detail->assertOk()->assertSee($longHeaders[0])->assertSee($longHeaders[199]);
        $export = $this->get(route('admin.unattributed.export', [$this->webinar, 'import' => $long->id]));
        $export->assertOk();
        $out = fopen('php://temp', 'w+b');
        fwrite($out, preg_replace('/^\xEF\xBB\xBF/', '', $export->streamedContent()));
        rewind($out);
        $headings = fgetcsv($out, 0, ',', '"', '');
        $first = fgetcsv($out, 0, ',', '"', '');
        fclose($out);
        $this->assertSame($longHeaders, array_slice($headings, 1, 200));
        $this->assertSame($original, json_decode($first[201], true, flags: JSON_THROW_ON_ERROR));
    }
}
