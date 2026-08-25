<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\User;
use App\Models\Webinar;
use App\Services\ParticipantPrivacyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Module 1: the storage the import pipeline is built on.
 *
 * These tests protect the properties later modules depend on rather than any
 * parsing behaviour, which does not exist yet.
 */
class ImportSchemaTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create([
            'role' => User::ADMINISTRATOR_ROLE,
            'is_active' => true,
        ]);
        // Built already past its retention deadline. `retention_due_at` is
        // derived from the schedule and may only shorten once committed, so a
        // past deadline is created rather than assigned afterwards.
        $this->webinar = Webinar::query()->create([
            'title' => 'Import Schema Webinar',
            'slug' => 'import-schema-webinar',
            'status' => 'completed',
            'ends_at' => now()->subDays(10),
            'data_retention_days' => 7,
            'timezone' => 'UTC',
            'created_by' => $this->administrator->id,
        ]);
        $this->form = $this->webinar->forms()->create([
            'type' => 'pretest',
            'title' => 'Pre-test',
            'status' => 'published',
        ]);
    }

    private function import(array $attributes = []): Import
    {
        return Import::query()->create(array_merge([
            'webinar_id' => $this->webinar->id,
            'form_id' => $this->form->id,
            'status' => Import::STATUS_COMMITTED,
            'original_filename' => 'pretest-responses.csv',
            'file_hash' => Import::hashContents('row,row,row'),
            'file_size' => 2048,
            'column_map' => ['Email Address' => 'email', 'Score' => 'score'],
            'total_rows' => 500,
            'valid_rows' => 487,
            'invalid_rows' => 3,
            'duplicate_rows' => 8,
            'unmatched_rows' => 2,
            'imported_by' => $this->administrator->id,
            'committed_at' => now(),
        ], $attributes));
    }

    private function issue(Import $import, array $attributes = []): ImportIssue
    {
        return ImportIssue::query()->create(array_merge([
            'import_id' => $import->id,
            'webinar_id' => $this->webinar->id,
            'row_number' => 42,
            'issue_type' => ImportIssue::TYPE_UNMATCHED_EMAIL,
            'issue_message' => 'Completed the pre-test but is not a registered participant.',
            'normalized_email' => 'juan@example.com',
            'raw_data' => ['Email Address' => 'Juan@Example.com ', 'Score' => '8/10'],
            'created_at' => now(),
        ], $attributes));
    }

    public function test_an_import_and_its_issues_persist_with_their_relationships(): void
    {
        $import = $this->import();
        $issue = $this->issue($import);

        $this->assertTrue($import->is($issue->import));
        $this->assertTrue($this->webinar->is($import->webinar));
        $this->assertTrue($this->form->is($import->form));
        $this->assertTrue($this->administrator->is($import->importedBy));
        $this->assertSame(1, $import->issues()->count());
        $this->assertSame(1, $this->webinar->imports()->count());
        $this->assertSame(1, $this->webinar->importIssues()->count());
        $this->assertTrue($import->retainsPersonalData());
        $this->assertFalse($issue->isResolved());
    }

    public function test_personal_columns_are_encrypted_at_rest(): void
    {
        $import = $this->import();
        $this->issue($import, [
            'resolution' => 'Promoted after confirming late registration.',
            'resolved_at' => now(),
            'resolved_by' => $this->administrator->id,
        ]);

        $raw = DB::table('import_issues')->sole();

        // The address, the original spreadsheet row, and the reviewer's note are
        // all personal data and must never sit in the database as plain text.
        $this->assertStringNotContainsString('juan@example.com', $raw->normalized_email);
        $this->assertStringNotContainsString('juan@example.com', (string) $raw->raw_data);
        $this->assertStringNotContainsString('8/10', (string) $raw->raw_data);
        $this->assertStringNotContainsString('late registration', (string) $raw->resolution);

        // ...but still round-trip through the model.
        $issue = ImportIssue::query()->sole();
        $this->assertSame('juan@example.com', $issue->normalized_email);
        $this->assertSame('8/10', $issue->raw_data['Score']);
    }

    public function test_the_email_digest_is_deterministic_and_kept_in_step(): void
    {
        $import = $this->import();
        $first = $this->issue($import);
        $second = $this->issue($import, ['row_number' => 77]);
        $other = $this->issue($import, ['row_number' => 78, 'normalized_email' => 'maria@example.com']);

        // Two rows for the same person group together even though neither the
        // ciphertext nor a random IV is shared between them.
        $this->assertSame($first->normalized_email_hash, $second->normalized_email_hash);
        $this->assertNotSame($first->normalized_email_hash, $other->normalized_email_hash);
        $this->assertSame(ImportIssue::emailHash('juan@example.com'), $first->normalized_email_hash);

        // A near-miss address is never folded into the same person.
        $this->assertNotSame(
            ImportIssue::emailHash('juan@example.com'),
            ImportIssue::emailHash('juann@example.com'),
        );

        // Changing the address rewrites the digest rather than leaving a stale one.
        $first->update(['normalized_email' => 'maria@example.com']);
        $this->assertSame(ImportIssue::emailHash('maria@example.com'), $first->fresh()->normalized_email_hash);

        // Clearing it clears the digest too.
        $first->update(['normalized_email' => null]);
        $this->assertNull($first->fresh()->normalized_email_hash);
    }

    public function test_the_unmatched_review_screen_can_group_one_person_across_files(): void
    {
        $evaluationForm = $this->webinar->forms()->create([
            'type' => 'evaluation', 'title' => 'Evaluation', 'status' => 'published',
        ]);
        $pretest = $this->import();
        $evaluation = $this->import(['form_id' => $evaluationForm->id]);

        $this->issue($pretest);
        $this->issue($evaluation, ['row_number' => 12]);
        $this->issue($pretest, ['row_number' => 13, 'normalized_email' => 'maria@example.com']);

        $rows = ImportIssue::query()
            ->where('webinar_id', $this->webinar->id)
            ->where('issue_type', ImportIssue::TYPE_UNMATCHED_EMAIL)
            ->whereNull('resolved_at')
            ->where('normalized_email_hash', ImportIssue::emailHash('juan@example.com'))
            ->with('import.form:id,type')
            ->get();

        $this->assertSame(2, $rows->count());
        $this->assertEqualsCanonicalizing(
            ['pretest', 'evaluation'],
            $rows->pluck('import.form.type')->all(),
        );
    }

    public function test_deleting_a_webinar_removes_its_imports_and_issues(): void
    {
        $import = $this->import();
        $this->issue($import);

        $this->webinar->delete();

        $this->assertSame(0, Import::query()->count());
        $this->assertSame(0, ImportIssue::query()->count());
    }

    public function test_an_administrator_owning_import_history_cannot_be_deleted(): void
    {
        $this->import();

        // Import history names who performed it. Deleting that account would
        // leave the record unattributable.
        $this->expectException(QueryException::class);
        $this->administrator->delete();
    }

    public function test_retention_purges_import_personal_data_but_keeps_the_evidence(): void
    {
        $import = $this->import();
        $this->issue($import);
        $this->issue($import, ['row_number' => 43, 'normalized_email' => 'maria@example.com']);

        $result = app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);

        $this->assertSame(['imports' => 1, 'issues' => 2], $result);

        // Every trace of a person is gone...
        $this->assertSame(0, ImportIssue::query()->count());

        // ...while the fact that an import happened, by whom, and how many rows
        // it covered survives as evidence, carrying no personal data.
        $import->refresh();
        $this->assertNotNull($import->privacy_erased_at);
        $this->assertFalse($import->retainsPersonalData());
        $this->assertNull($import->column_map);
        $this->assertSame(500, $import->total_rows);
        $this->assertSame(487, $import->valid_rows);
        $this->assertSame($this->administrator->id, (int) $import->imported_by);
    }

    public function test_the_purge_is_idempotent_and_reaches_stranded_issues(): void
    {
        $import = $this->import();
        $this->issue($import);

        app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);

        // A second run must not double-count an already erased import.
        $second = app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);
        $this->assertSame(['imports' => 0, 'issues' => 0], $second);

        // An issue left behind by a partially completed earlier run is still
        // collected, even though its import was already stamped.
        $this->issue($import, ['row_number' => 99]);
        $third = app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);
        $this->assertSame(1, $third['issues']);
        $this->assertSame(0, ImportIssue::query()->count());
    }

    public function test_the_retention_command_purges_unmatched_rows_that_have_no_participant(): void
    {
        $import = $this->import();
        $this->issue($import);

        // An unmatched row belongs to someone who never registered, so the
        // per-participant sweep cannot reach it. The deadline must still apply.
        $this->artisan('privacy:erase-expired-participants')
            ->expectsOutputToContain('1 import issue record(s) erased.');

        $this->assertSame(0, ImportIssue::query()->count());
        $this->assertNotNull($import->fresh()->privacy_erased_at);
    }

    public function test_a_dry_run_reports_without_erasing_anything(): void
    {
        $import = $this->import();
        $this->issue($import);
        $this->artisan('privacy:erase-expired-participants', ['--dry-run' => true])
            ->expectsOutputToContain('1 import issue record(s) would be erased.');

        $this->assertSame(1, ImportIssue::query()->count());
        $this->assertNull($import->fresh()->privacy_erased_at);
    }

    public function test_the_same_file_can_be_imported_again_deliberately(): void
    {
        $hash = Import::hashContents('identical bytes');
        $this->import(['file_hash' => $hash]);

        // The hash warns an administrator, but must never block a re-import
        // after a cancelled or rolled back run.
        $repeat = $this->import(['file_hash' => $hash]);

        $this->assertSame(2, Import::query()->where('file_hash', $hash)->count());
        $this->assertTrue($repeat->exists);
    }
}
