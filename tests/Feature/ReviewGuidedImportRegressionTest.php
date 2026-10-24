<?php

namespace Tests\Feature;

use App\Models\ImportIssue;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Independent review reproductions; synthetic records only. */
class ReviewGuidedImportRegressionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Storage::set('local', Storage::fake('review-guided-'.bin2hex(random_bytes(8))));
    }

    private function pending(): array
    {
        $owner = User::factory()->create(['role' => User::USER_ROLE, 'account_source' => 'public_request']);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Synthetic registration']);
        $this->actingAs($owner);
        $import = app(CsvImportService::class)->storePending($webinar, $form, UploadedFile::fake()->createWithContent(
            'synthetic-review.csv', "Name,Email,Feedback\nSynthetic Review,review@example.test,Exact feedback\n"
        ));

        return [$webinar, $import];
    }

    public function test_administrator_can_open_a_different_users_pending_mapping_page(): void
    {
        [$webinar, $import] = $this->pending();
        $admin = User::factory()->create();
        $this->actingAs($admin)->withSession(['administrative_webinars' => [$webinar->id => now()->addHour()->timestamp]]);
        $this->get(route('admin.webinars.imports.index', $webinar))->assertOk();
        $this->get(route('admin.webinars.imports.map', [$webinar, $import]))->assertOk();
    }

    public function test_assigned_manager_can_open_a_different_users_pending_mapping_page(): void
    {
        [$webinar, $import] = $this->pending();
        $manager = User::factory()->create(['role' => User::MANAGER_ROLE, 'account_source' => 'admin_invitation']);
        $manager->assignedWebinars()->attach($webinar->id, ['assigned_by' => $webinar->created_by, 'manage_participants' => true]);
        $this->actingAs($manager);
        $this->get(route('admin.webinars.imports.index', $webinar))->assertOk();
        $this->get(route('admin.webinars.imports.map', [$webinar, $import]))->assertOk();
    }

    public function test_unattributed_export_headings_remain_unambiguous_when_source_headings_match_metadata(): void
    {
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $form = $webinar->forms()->create(['type' => 'evaluation', 'title' => 'Synthetic evaluation']);
        $this->actingAs($owner);
        $import = app(CsvImportService::class)->storePending($webinar, $form, UploadedFile::fake()->createWithContent(
            'synthetic-export.csv', "Source row,Original imported cells (JSON),Feedback\nUser answer,Original answer,Exact feedback\n"
        ));
        app(CsvImportService::class)->process($import, [], options: [
            'response_mode' => 'unattributed', 'source_description' => 'Synthetic review export', 'source_confirmed' => true,
        ]);
        $export = $this->get(route('admin.unattributed.export', [$webinar, 'import' => $import->id]))->assertOk();
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $export->streamedContent()));
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);

        $this->assertSame(count($headers), count(array_unique($headers)), 'Export metadata and original source headings must remain uniquely addressable.');
    }

    public function test_reconciliation_accounts_for_successfully_saved_unattributed_rows(): void
    {
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $form = $webinar->forms()->create(['type' => 'evaluation', 'title' => 'Synthetic evaluation']);
        $this->actingAs($owner);
        $import = app(CsvImportService::class)->storePending($webinar, $form, UploadedFile::fake()->createWithContent(
            'synthetic-reconciliation.csv', "Answer\nSame\nSame\n"
        ));
        app(CsvImportService::class)->process($import, [], options: [
            'response_mode' => 'unattributed', 'source_description' => 'Synthetic review export', 'source_confirmed' => true,
        ]);
        $this->get(route('admin.webinars.imports.index', $webinar))->assertOk()->assertSee('Reconciliation CSV');
        $export = $this->get(route('admin.webinars.imports.reconciliation', [$webinar, $import]))->assertOk();
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $export->streamedContent());
        rewind($stream);
        fgetcsv($stream, 0, ',', '"', '');
        $sourceRows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $sourceRows[] = (int) $row[1];
        }
        fclose($stream);

        $this->assertSame([2, 3], $sourceRows, 'Every saved source row needs a durable reconciliation outcome.');
    }

    public function test_correction_headers_remain_unique_after_spreadsheet_sanitization(): void
    {
        [$webinar, $import] = $this->pending();
        ImportIssue::query()->create([
            'webinar_id' => $webinar->id, 'import_id' => $import->id, 'row_number' => 2,
            'issue_type' => ImportIssue::TYPE_INVALID_EMAIL, 'issue_message' => 'Synthetic held row',
            'raw_data' => ['=Answer' => 'First original answer', "'=Answer" => 'Second original answer'],
        ]);
        $export = $this->get(route('admin.webinars.imports.corrections', $webinar))->assertOk();
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, preg_replace('/^\xEF\xBB\xBF/', '', $export->streamedContent()));
        rewind($stream);
        $headers = fgetcsv($stream, 0, ',', '"', '');
        fclose($stream);

        $this->assertSame(count($headers), count(array_unique($headers)), 'Source headings that need spreadsheet sanitization must remain uniquely addressable.');
    }
}
