<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportHeldReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_groups_scope_rows_and_correction_csv_retains_every_original_cell_and_reference(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::USER_ROLE]));
        $webinar = Webinar::factory()->create(['created_by' => auth()->id()]);
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Registration']);
        $import = Import::create(['webinar_id' => $webinar->id, 'form_id' => $form->id, 'status' => 'committed',
            'original_filename' => 'held.csv', 'file_hash' => hash('sha256', 'held'), 'file_size' => 20, 'imported_by' => auth()->id(), 'column_map' => ['headers' => ['Name', 'Email', 'Feedback']]]);
        $original = ['Name' => '  Person É  ', 'Email' => '=formula@example.test', 'Feedback' => ' Exact \\'.'"quoted"'."\nanswer  ", 'Extra column' => '0'];
        foreach ([ImportIssue::TYPE_DUPLICATE_IN_FILE, ImportIssue::TYPE_UNMATCHED_EMAIL, ImportIssue::TYPE_INVALID_EMAIL, ImportIssue::TYPE_NAME_CONFLICT] as $offset => $type) {
            ImportIssue::create(['import_id' => $import->id, 'webinar_id' => $webinar->id, 'row_number' => $offset + 2,
                'issue_type' => $type, 'issue_message' => 'Review reason '.$type, 'raw_data' => $original, 'normalized_email' => 'person@example.test']);
        }
        $resolved = ImportIssue::create(['import_id' => $import->id, 'webinar_id' => $webinar->id, 'row_number' => 99,
            'issue_type' => ImportIssue::TYPE_DUPLICATE_IN_FILE, 'issue_message' => 'Resolved', 'raw_data' => ['Name' => 'Should not appear'], 'resolved_at' => now()]);
        $this->get(route('admin.webinars.imports.index', [$webinar, 'type' => 'registration', 'issue_group' => 'duplicates']))
            ->assertOk()->assertSee('Review reason duplicate_in_file')->assertDontSee('Review reason unmatched_email')->assertDontSee('Review reason invalid_email')
            ->assertSee('Download correction sheet')->assertSee('Original source cells');
        $download = $this->get(route('admin.webinars.imports.corrections', [$webinar, 'type' => 'registration', 'issue_group' => 'duplicates']))->assertOk();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, ltrim($download->streamedContent(), "\xEF\xBB\xBF"));
        rewind($stream);
        $headers = fgetcsv($stream, escape: '');
        $row = fgetcsv($stream, escape: '');
        $record = array_combine($headers, $row);
        $this->assertSame((string) $import->id, $record['Import ID']);
        $this->assertSame('2', $record['Source row']);
        $this->assertSame($original, json_decode($record['Original source cells (JSON)'], true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame("'=formula@example.test", $record['Email']);
        $this->assertSame($original['Feedback'], $record['Feedback']);
        $this->assertFalse(fgetcsv($stream, escape: ''));
        fclose($stream);
        $this->assertNotNull($resolved->fresh()->resolved_at);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_correction_export_is_unavailable_to_another_owner_or_an_unknown_filter(): void
    {
        $this->actingAs(User::factory()->create(['role' => User::USER_ROLE]));
        $foreign = Webinar::factory()->create();
        $this->get(route('admin.webinars.imports.corrections', $foreign))->assertNotFound();
        $own = Webinar::factory()->create(['created_by' => auth()->id()]);
        $this->get(route('admin.webinars.imports.corrections', [$own, 'issue_group' => 'unknown']))->assertSessionHasErrors('issue_group');
    }
}
