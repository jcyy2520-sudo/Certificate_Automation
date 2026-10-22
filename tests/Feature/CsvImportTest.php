<?php

namespace Tests\Feature;

use App\Models\EligibilityRule;
use App\Models\Form;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CsvImportTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    private Form $registration;

    private Form $pretest;

    private Form $posttest;

    private Form $evaluation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->webinar = Webinar::query()->create([
            'title' => 'Import Fixture '.uniqid(),
            'slug' => 'import-fixture-'.uniqid(),
            'status' => 'published',
            'timezone' => 'UTC',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHours(4),
            'created_by' => $this->administrator->id,
            'data_retention_days' => 30,
        ]);

        $this->registration = $this->webinar->forms()->create(['type' => 'registration', 'title' => 'Registration', 'status' => 'published']);
        $this->pretest = $this->webinar->forms()->create(['type' => 'pretest', 'title' => 'Pre-assessment', 'status' => 'published']);
        $this->posttest = $this->webinar->forms()->create(['type' => 'posttest', 'title' => 'Post-assessment', 'status' => 'published']);
        $this->evaluation = $this->webinar->forms()->create(['type' => 'evaluation', 'title' => 'Event evaluation', 'status' => 'published']);
    }

    private function csv(string ...$lines): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            'responses.csv',
            implode("\n", $lines),
        );
    }

    /** @return array{0: Import, 1: array<string, string>} */
    private function preview(Form $form, UploadedFile $file, bool $force = false): array
    {
        $payload = ['form_id' => $form->id, 'file' => $file];
        if ($force) {
            $payload['force'] = '1';
        }

        $response = $this->actingAs($this->administrator)
            ->post(route('admin.webinars.imports.preview', $this->webinar), $payload);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect();

        $import = Import::query()->latest('id')->firstOrFail();
        $this->assertSame(Import::STATUS_PENDING, $import->status);
        $this->assertSame($form->id, $import->form_id);

        return [$import, $import->column_map['map']];
    }

    private function commitImport(Import $import, array $payload): TestResponse
    {
        $payload += [
            'allow_import_time' => '1',
            'source_description' => 'Test Form v1, exported 2026-08-20',
            'source_confirmed' => '1',
            'review_confirmed' => '1',
        ];
        $this->actingAs($this->administrator)
            ->post(route('admin.webinars.imports.dry-run', [$this->webinar, $import]), $payload)
            ->assertSessionHasNoErrors()->assertSessionHas('dry_run');

        return $this->actingAs($this->administrator)
            ->put(route('admin.webinars.imports.store', [$this->webinar, $import]), $payload);
    }

    public function test_registration_import_creates_verified_participants(): void
    {
        [$import] = $this->preview($this->registration, $this->csv(
            '"Email Address","Full Name","Organization"',
            '"Maria.DelaCruz@Example.com "," Maria Dela Cruz "," St. Luke\'s"',
            '"j@santos.ph","Jose Santos",""',
        ));

        $response = $this->commitImport($import, [
            'map' => ['email' => '0', 'full_name' => '1', 'organization' => '2'],
        ]);

        $response->assertRedirect(route('admin.webinars.imports.index', $this->webinar));

        $participant = Participant::query()->where('email_normalized', 'maria.delacruz@example.com')->firstOrFail();
        $this->assertNotNull($participant->verified_at);
        $this->assertSame('Maria Dela Cruz', $participant->full_name);
        $this->assertSame("St. Luke's", $participant->organization);
        $this->assertTrue(Submission::query()->where('form_id', $this->registration->id)->where('participant_id', $participant->id)->exists());

        $second = Participant::query()->where('email_normalized', 'j@santos.ph')->firstOrFail();
        $this->assertNull($second->organization);
        $this->assertTrue(Submission::query()->where('form_id', $this->registration->id)->where('participant_id', $second->id)->exists());
    }

    public function test_assessment_rows_attach_by_email_and_record_scores(): void
    {
        $participant = $this->webinar->participants()->create([
            'email' => 'registered@example.com',
            'full_name' => 'Registered Person',
            'verified_at' => now(),
        ]);

        $this->webinar->eligibilityRules()->create([
            'requirement' => 'pretest', 'is_required' => true, 'minimum_score' => 7,
        ]);

        [$import] = $this->preview($this->pretest, $this->csv(
            '"Email Address","Score"',
            '"registered@example.com","8.5"',
        ));

        $this->commitImport($import, [
            'map' => ['email' => '0', 'score' => '1'],
        ])
            ->assertRedirect(route('admin.webinars.imports.index', $this->webinar));

        $submission = Submission::query()->where('form_id', $this->pretest->id)->where('participant_id', $participant->id)->firstOrFail();
        $this->assertSame(8.5, (float) $submission->score);
        $this->assertSame('submitted', $submission->status);
        $this->assertTrue(app(EligibilityService::class)->evaluate($participant->fresh())['eligible']);
    }

    public function test_unmatched_row_is_held_then_promoted(): void
    {
        [$import] = $this->preview($this->posttest, $this->csv(
            '"Email Address","Full Name","Score"',
            '"walkin@example.com","Ana Reyes","9"',
        ));

        $this->commitImport($import, [
            'map' => ['email' => '0', 'full_name' => '1', 'score' => '2'],
        ])
            ->assertRedirect(route('admin.webinars.imports.index', $this->webinar))
            ->assertSessionHas('warning');

        $issue = ImportIssue::query()->where('webinar_id', $this->webinar->id)->sole();
        $this->assertSame(ImportIssue::TYPE_UNMATCHED_EMAIL, $issue->issue_type);
        $this->assertNull($issue->resolved_at);
        $this->assertFalse(Participant::query()->where('email_normalized', 'walkin@example.com')->exists());

        $this->actingAs($this->administrator)
            ->post(route('admin.webinars.import-issues.promote', [$this->webinar, $issue]), ['full_name' => 'Ana Reyes', 'response_verified' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $participant = Participant::query()->where('email_normalized', 'walkin@example.com')->sole();
        $this->assertSame('Ana Reyes', $participant->full_name);
        $this->assertTrue(Submission::query()->where('form_id', $this->posttest->id)->where('participant_id', $participant->id)->exists());
        $this->assertNotNull($issue->fresh()->resolved_at);
    }

    public function test_a_realistic_google_forms_quiz_export_maps_and_parses(): void
    {
        // Byte-order mark, Google's real header wording, a fraction score, and
        // a slash-formatted timestamp — the shapes real exports arrive in.
        $contents = "\xEF\xBB\xBF\"Timestamp\",\"Email Address\",\"What is your full name?\",\"Name of School / Organization\",\"Score\"\n"
            ."\"8/26/2026 14:03\",\"Maria.DelaCruz@Example.com \",\"Maria Dela Cruz\",\"St. Luke's College\",\"8/10\"\n";
        $file = UploadedFile::fake()->createWithContent('responses.csv', $contents);

        [$import] = $this->preview($this->registration, $file);

        // The automatic mapping must land every column correctly on its own.
        $map = $import->column_map['map'];
        $this->assertSame(1, $map['email']);
        $this->assertSame(2, $map['full_name']);
        $this->assertSame(3, $map['organization']);
        $this->assertSame(4, $map['score']);
        $this->assertSame(0, $map['submitted_at']);

        $this->commitImport($import, [
            'map' => array_map(fn ($index) => (string) $index, $map),
        ])
            ->assertRedirect(route('admin.webinars.imports.index', $this->webinar));

        $participant = Participant::query()->where('email_normalized', 'maria.delacruz@example.com')->sole();
        // The school must never leak into the printed certificate name.
        $this->assertSame('Maria Dela Cruz', $participant->full_name);
        $this->assertSame("St. Luke's College", $participant->organization);

        $submission = Submission::query()->where('participant_id', $participant->id)->sole();
        $this->assertSame(8.0, (float) $submission->score);
        $this->assertSame(10.0, (float) $submission->maximum_score);
    }

    public function test_google_forms_full_name_header_with_a_formatting_instruction_is_suggested(): void
    {
        // This is the header shape used by the reviewed public registration
        // form. Google exports the question title verbatim, so mapping it as a
        // name must not depend on the title being exactly "Full Name".
        $map = CsvImportService::suggestMap([
            'Timestamp',
            'Full Name: (Surname, Firstname, MI)',
            'Email Address:',
            'School/University/Organization:',
            'Contact Number:',
        ], 'registration');

        $this->assertSame(0, $map['submitted_at']);
        $this->assertSame(1, $map['full_name']);
        $this->assertSame(2, $map['email']);
        $this->assertSame(3, $map['organization']);
        $this->assertArrayNotHasKey('full_name', CsvImportService::suggestMap([
            'Name of School / Organization', 'Email Address',
        ], 'registration'));
    }

    public function test_promotion_keeps_normal_certificate_requirements(): void
    {
        EligibilityRule::query()->create([
            'webinar_id' => $this->webinar->id,
            'requirement' => 'registration',
            'is_required' => true,
        ]);

        [$import] = $this->preview($this->posttest, $this->csv(
            '"Email Address","Full Name","Score"',
            '"walkin@example.com","Ana Reyes","9"',
        ));

        $this->commitImport($import, [
            'map' => ['email' => '0', 'full_name' => '1', 'score' => '2'],
        ]);

        $issue = ImportIssue::query()->where('webinar_id', $this->webinar->id)->sole();
        $this->actingAs($this->administrator)
            ->post(route('admin.webinars.import-issues.promote', [$this->webinar, $issue]), ['full_name' => 'Ana Reyes', 'response_verified' => '1'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $participant = Participant::query()->where('email_normalized', 'walkin@example.com')->sole();
        $this->assertSame(0, $participant->eligibilityOverrides()->count());
        $this->assertFalse(app(EligibilityService::class)->evaluate($participant->fresh())['eligible']);
    }

    public function test_duplicate_rows_inside_one_file_are_flagged(): void
    {
        $participant = $this->webinar->participants()->create([
            'email' => 'once@example.com', 'full_name' => 'Once', 'verified_at' => now(),
        ]);

        [$import] = $this->preview($this->evaluation, $this->csv(
            '"Email Address","Completed"',
            '"once@example.com","Yes"',
            '"Once@Example.com","Yes"',
        ));

        $this->commitImport($import, [
            'map' => ['email' => '0'],
            'required_columns' => ['1'],
        ]);

        $this->assertSame(1, Submission::query()->where('form_id', $this->evaluation->id)->where('participant_id', $participant->id)->count());
        $issue = ImportIssue::query()->where('import_id', $import->id)->sole();
        $this->assertSame(ImportIssue::TYPE_DUPLICATE_IN_FILE, $issue->issue_type);
        $this->assertSame(1, $import->fresh()->duplicate_rows);
    }

    public function test_an_existing_response_is_never_overwritten(): void
    {
        $participant = $this->webinar->participants()->create([
            'email' => 'again@example.com', 'full_name' => 'Again', 'verified_at' => now(),
        ]);
        $file = $this->csv('"Email Address","Score"', '"again@example.com","4"');

        [$first] = $this->preview($this->pretest, $file);
        $this->commitImport($first, ['map' => ['email' => '0', 'score' => '1']]);

        // The exact same bytes are refused unless the administrator insists…
        $this->actingAs($this->administrator)
            ->post(route('admin.webinars.imports.preview', $this->webinar), [
                'form_id' => $this->pretest->id, 'file' => $file,
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('file');

        // …and even then the recorded response is skipped, not rewritten.
        [$second] = $this->preview($this->pretest, $file, force: true);
        $this->commitImport($second, ['map' => ['email' => '0', 'score' => '1']]);

        $this->assertSame(1, Submission::query()->where('form_id', $this->pretest->id)->where('participant_id', $participant->id)->count());
        $this->assertSame(ImportIssue::TYPE_DUPLICATE_EXISTING, ImportIssue::query()->where('import_id', $second->id)->value('issue_type'));
    }

    public function test_rows_without_a_usable_email_become_issues(): void
    {
        [$import] = $this->preview($this->evaluation, $this->csv(
            '"Email Address","Feedback"',
            ',"Loved it"',
            '"not-an-email","Fine"',
        ));

        // An answer in an unmapped column must not hide a missing identity.
        $this->commitImport($import, ['map' => ['email' => '0', 'score' => null], 'required_columns' => ['1']]);

        $this->assertSame([ImportIssue::TYPE_MISSING_EMAIL, ImportIssue::TYPE_INVALID_EMAIL], ImportIssue::query()->where('import_id', $import->id)->pluck('issue_type')->all());
        $this->assertSame(2, $import->fresh()->total_rows);
        $this->assertSame(2, $import->fresh()->invalid_rows);
        $this->assertSame(0, $import->fresh()->valid_rows);
    }

    public function test_ambiguous_google_forms_timestamp_is_held_instead_of_becoming_now(): void
    {
        $participant = $this->webinar->participants()->create([
            'email' => 'dated@example.com', 'full_name' => 'Dated Person', 'verified_at' => now(),
        ]);

        [$import] = $this->preview($this->evaluation, $this->csv(
            '"Email Address","Timestamp","Completed"',
            '"dated@example.com","08/09/2026 09:30","Yes"',
        ));

        $this->commitImport($import, [
            'map' => ['email' => '0', 'submitted_at' => '1'],
            'required_columns' => ['2'],
        ])
            ->assertRedirect(route('admin.webinars.imports.index', $this->webinar));

        $this->assertSame(ImportIssue::TYPE_INVALID_TIMESTAMP, ImportIssue::query()->where('import_id', $import->id)->value('issue_type'));
        $this->assertSame(0, Submission::query()->where('form_id', $this->evaluation->id)->where('participant_id', $participant->id)->count());
    }

    public function test_administrator_can_declare_day_month_year_for_an_ambiguous_timestamp(): void
    {
        $participant = $this->webinar->participants()->create([
            'email' => 'declared-date@example.com', 'full_name' => 'Declared Date', 'verified_at' => now(),
        ]);
        [$import] = $this->preview($this->evaluation, $this->csv(
            '"Email Address","Timestamp","Completed"',
            '"declared-date@example.com","08/09/2026 09:30","Yes"',
        ));

        $this->commitImport($import, [
            'map' => ['email' => '0', 'submitted_at' => '1'],
            'required_columns' => ['2'],
            'timestamp_format' => 'day_month_year',
        ])
            ->assertRedirect(route('admin.webinars.imports.index', $this->webinar));

        $submission = Submission::query()->where('form_id', $this->evaluation->id)->where('participant_id', $participant->id)->sole();
        $this->assertSame('2026-09-08 09:30', $submission->submitted_at->utc()->format('Y-m-d H:i'));
        $this->assertSame('day_month_year', $import->fresh()->column_map['timestamp_format']);
    }

    public function test_dry_run_uses_import_rules_without_writing_rows_or_closing_the_pending_import(): void
    {
        [$import] = $this->preview($this->registration, $this->csv(
            '"Email Address","Full Name"',
            '"dry-run@example.com","Dry Run"',
        ));

        $this->actingAs($this->administrator)
            ->post(route('admin.webinars.imports.dry-run', [$this->webinar, $import]), [
                'map' => ['email' => '0', 'full_name' => '1'],
                'timestamp_format' => 'auto',
                'allow_import_time' => '1',
                'source_description' => 'Registration Form v1, exported 2026-08-20',
                'source_confirmed' => '1',
            ])
            ->assertRedirect(route('admin.webinars.imports.map', [$this->webinar, $import]))
            ->assertSessionHas('dry_run', fn (array $counts): bool => $counts['valid'] === 1 && $counts['invalid'] === 0);

        $this->assertSame(Import::STATUS_PENDING, $import->fresh()->status);
        $this->assertFalse(Participant::query()->where('email_normalized', 'dry-run@example.com')->exists());
        $this->assertSame(0, Submission::query()->count());
        $this->assertSame(0, ImportIssue::query()->count());
    }

    public function test_reconciliation_download_lists_imported_rows_without_retaining_source_file(): void
    {
        $participant = $this->webinar->participants()->create([
            'email' => 'reconcile@example.com', 'full_name' => 'Reconcile Person', 'verified_at' => now(),
        ]);
        [$import] = $this->preview($this->evaluation, $this->csv(
            '"Email Address","Score","Feedback"',
            '"reconcile@example.com","8","Useful"',
            '"not-an-email","3","Fine"',
        ));
        $this->commitImport($import, ['map' => ['email' => '0', 'score' => '1'], 'required_columns' => ['2']]);

        $response = $this->actingAs($this->administrator)
            ->get(route('admin.webinars.imports.reconciliation', [$this->webinar, $import]));

        $response->assertOk()->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString('Imported,2,reconcile@example.com,"Reconcile Person"', $content);
        $this->assertStringContainsString('"Held for review",3,not-an-email', $content);
        $this->assertSame($participant->id, Submission::query()->sole()->participant_id);
    }

    public function test_malformed_column_count_is_not_interpreted_as_a_registration(): void
    {
        [$import] = $this->preview($this->registration, $this->csv(
            '"Email Address","Full Name"',
            ',,"unexpected third column"',
        ));

        $this->commitImport($import, [
            'map' => ['email' => '0', 'full_name' => '1'],
        ])
            ->assertRedirect(route('admin.webinars.imports.index', $this->webinar));

        $this->assertSame(ImportIssue::TYPE_INVALID_ROW_STRUCTURE, ImportIssue::query()->where('import_id', $import->id)->value('issue_type'));
        $this->assertSame(0, Participant::query()->where('webinar_id', $this->webinar->id)->count());
    }

    public function test_blank_registration_name_is_held_not_created_as_anonymous_participant(): void
    {
        [$import] = $this->preview($this->registration, $this->csv(
            '"Email Address","Full Name"',
            '"no-name@example.com",""',
        ));

        $this->commitImport($import, [
            'map' => ['email' => '0', 'full_name' => '1'],
        ])
            ->assertRedirect(route('admin.webinars.imports.index', $this->webinar));

        $this->assertSame(ImportIssue::TYPE_MISSING_NAME, ImportIssue::query()->where('import_id', $import->id)->value('issue_type'));
        $this->assertFalse(Participant::query()->where('email_normalized', 'no-name@example.com')->exists());
    }

    public function test_disagreeing_score_and_maximum_columns_are_held_for_review(): void
    {
        $this->webinar->participants()->create([
            'email' => 'scored@example.com', 'full_name' => 'Scored Person', 'verified_at' => now(),
        ]);

        [$import] = $this->preview($this->pretest, $this->csv(
            '"Email Address","Score","Maximum score"',
            '"scored@example.com","8/10","20"',
        ));

        $this->commitImport($import, [
            'map' => ['email' => '0', 'score' => '1', 'maximum_score' => '2'],
        ]);

        $this->assertSame(ImportIssue::TYPE_INVALID_SCORE, ImportIssue::query()->where('import_id', $import->id)->value('issue_type'));
        $this->assertSame(0, Submission::query()->where('form_id', $this->pretest->id)->count());
    }

    public function test_review_screens_render(): void
    {
        $routes = [
            route('admin.webinars.imports.index', $this->webinar),
            route('admin.webinars.imports.create', $this->webinar),
        ];

        foreach ($routes as $route) {
            $this->actingAs($this->administrator)->get($route)->assertOk();
        }

        [$import] = $this->preview($this->registration, $this->csv('"Email Address","Full Name"', '"x@y.z","X Y"'));
        $this->actingAs($this->administrator)
            ->get(route('admin.webinars.imports.map', [$this->webinar, $import]))
            ->assertOk()
            ->assertSee('Parser check')
            ->assertSee('x@y.z');
    }

    public function test_separate_names_preserve_middle_name_and_suffix(): void
    {
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,First Name,Middle Name,Last Name,Suffix', 'maria@example.com,Maria,Santos,Reyes,Jr.'));
        $this->assertArrayNotHasKey('full_name', $map);
        $this->commitImport($import, ['map' => $map])->assertSessionHas('success');
        $this->assertSame('Maria Santos Reyes Jr.', Participant::query()->sole()->full_name);
    }

    public function test_ambiguous_email_and_grade_level_are_not_guessed(): void
    {
        $map = CsvImportService::suggestMap(['Alternate Email', 'Email Address', 'Full Name', 'Grade Level'], 'registration');
        $this->assertArrayNotHasKey('email', $map);
        $this->assertArrayNotHasKey('score', $map);
    }

    public function test_invalid_first_row_does_not_block_a_valid_correction(): void
    {
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,Full Name', 'maria@example.com,', 'maria@example.com,Maria Reyes'));
        $this->commitImport($import, ['map' => $map]);
        $this->assertSame('Maria Reyes', Participant::query()->sole()->full_name);
        $this->assertSame(1, $import->fresh()->valid_rows);
        $this->assertSame(1, $import->fresh()->invalid_rows);
        $this->assertSame(0, $import->fresh()->duplicate_rows);
    }

    public function test_conflicting_valid_duplicates_are_all_held(): void
    {
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,Full Name', 'shared@example.com,Maria Reyes', 'shared@example.com,Juan Reyes'));
        $this->commitImport($import, ['map' => $map]);
        $this->assertSame(0, Participant::query()->count());
        $this->assertSame(2, $import->fresh()->duplicate_rows);
    }

    public function test_score_precision_and_storage_limits_never_become_null_or_rounded(): void
    {
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,Full Name,Score', 'a@example.com,Person A,8.555/10', 'b@example.com,Person B,8/10.005', 'c@example.com,Person C,100000000', 'd@example.com,Person D,0/10'));
        $this->commitImport($import, ['map' => $map]);
        $this->assertSame(3, $import->fresh()->invalid_rows);
        $this->assertSame('0.00', Submission::query()->sole()->score);
        $this->assertSame('10.00', Submission::query()->sole()->maximum_score);
    }

    public function test_required_answers_hold_incomplete_evaluations(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'blank@example.com', 'full_name' => 'Blank Answer']);
        $this->webinar->eligibilityRules()->create(['requirement' => 'evaluation', 'is_required' => true]);
        [$import, $map] = $this->preview($this->evaluation, $this->csv('Email Address,Feedback', 'blank@example.com,'));
        $this->commitImport($import, ['map' => $map, 'required_columns' => ['1']]);
        $this->assertSame(ImportIssue::TYPE_INCOMPLETE_RESPONSE, $import->issues()->sole()->issue_type);
        $this->assertFalse(app(EligibilityService::class)->evaluate($participant)['eligible']);
    }

    public function test_summary_import_is_rejected_as_completion_evidence(): void
    {
        [$import, $map] = $this->preview($this->evaluation, $this->csv('Email Address', 'summary@example.com'));
        $this->actingAs($this->administrator)->post(route('admin.webinars.imports.dry-run', [$this->webinar, $import]), ['map' => $map, 'allow_import_time' => '1', 'source_description' => 'Evaluation Form v1, exported 2026-08-20', 'source_confirmed' => '1'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'completion'));
        $this->assertSame(0, Submission::query()->count());
    }

    public function test_name_conflicts_and_same_name_different_email_need_review(): void
    {
        $this->webinar->participants()->create(['email' => 'registered@example.com', 'full_name' => 'Maria Reyes']);
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,Full Name', 'registered@example.com,Juan Cruz', 'different@example.com,Maria Reyes'));
        $this->commitImport($import, ['map' => $map]);
        $this->assertEqualsCanonicalizing([ImportIssue::TYPE_NAME_CONFLICT, ImportIssue::TYPE_IDENTITY_CONFLICT], $import->issues()->pluck('issue_type')->all());
        $this->assertSame(0, Submission::query()->count());
    }

    public function test_commit_requires_matching_dry_check_and_confirmation(): void
    {
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,Full Name,Other Name', 'person@example.com,Maria Reyes,Juan Reyes'));
        $payload = ['map' => $map, 'allow_import_time' => '1', 'review_confirmed' => '1', 'source_description' => 'Registration Form v1, exported 2026-08-20', 'source_confirmed' => '1'];
        $this->actingAs($this->administrator)->put(route('admin.webinars.imports.store', [$this->webinar, $import]), $payload)->assertSessionHas('error');
        $this->actingAs($this->administrator)->post(route('admin.webinars.imports.dry-run', [$this->webinar, $import]), $payload)->assertSessionHas('dry_run');
        $payload['map']['full_name'] = 2;
        $this->actingAs($this->administrator)->put(route('admin.webinars.imports.store', [$this->webinar, $import]), $payload)->assertSessionHas('error');
        $payload['review_confirmed'] = '0';
        $this->actingAs($this->administrator)->put(route('admin.webinars.imports.store', [$this->webinar, $import]), $payload)->assertSessionHasErrors('review_confirmed');
        $this->assertSame(0, Participant::query()->count());
    }

    public function test_one_column_cannot_supply_two_identity_fields(): void
    {
        [$import] = $this->preview($this->registration, $this->csv('Email Address,Full Name', 'person@example.com,Maria Reyes'));
        $this->actingAs($this->administrator)->post(route('admin.webinars.imports.dry-run', [$this->webinar, $import]), ['map' => ['email' => 0, 'first_name' => 1, 'last_name' => 1], 'source_description' => 'Registration Form v1, exported 2026-08-20', 'source_confirmed' => '1'])->assertSessionHas('error');
        $this->assertSame(0, Participant::query()->count());
    }

    public function test_percentage_mode_converts_exactly_to_points(): void
    {
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,Full Name,Score', 'percent@example.com,Percent Person,80%'));
        $this->commitImport($import, ['map' => $map, 'score_mode' => 'percentage', 'default_maximum_score' => '10']);
        $this->assertSame('8.00', Submission::query()->sole()->score);
        $this->assertSame('10.00', Submission::query()->sole()->maximum_score);
    }

    public function test_missing_timestamp_requires_an_explicit_import_time_choice(): void
    {
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,Full Name,Timestamp', 'missing@example.com,Missing Date,'));
        $this->commitImport($import, ['map' => $map, 'allow_import_time' => '0']);
        $this->assertSame(ImportIssue::TYPE_INVALID_TIMESTAMP, $import->issues()->sole()->issue_type);
    }

    public function test_iso_utc_timestamp_is_not_shifted_by_the_export_timezone(): void
    {
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,Full Name,Timestamp', 'utc@example.com,UTC Person,2026-09-23T09:30:00Z'));
        $this->commitImport($import, ['map' => $map, 'timestamp_format' => 'iso_8601', 'timestamp_timezone' => 'Asia/Manila']);
        $this->assertSame('2026-09-23 09:30:00', Submission::query()->sole()->submitted_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_dry_check_details_and_interpreted_names_render(): void
    {
        [$import, $map] = $this->preview($this->registration, $this->csv('Email Address,Full Name', 'maria@example.com,Maria Reyes', ',Missing Email'));
        $this->actingAs($this->administrator)->post(route('admin.webinars.imports.dry-run', [$this->webinar, $import]), ['map' => $map, 'allow_import_time' => '1', 'source_description' => 'Registration Form v1, exported 2026-08-20', 'source_confirmed' => '1'])->assertSessionHas('dry_run');
        $this->actingAs($this->administrator)->get(route('admin.webinars.imports.map', [$this->webinar, $import]))->assertOk()->assertSee('Interpreted certificate names')->assertSee('The response has no email');
    }

    public function test_explicit_correction_preserves_history_and_changes_eligibility(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'correct@example.com', 'full_name' => 'Correct Person']);
        $this->webinar->eligibilityRules()->create(['requirement' => 'posttest', 'is_required' => true, 'minimum_score' => 7]);
        [$first, $map] = $this->preview($this->posttest, $this->csv('Email Address,Score', 'correct@example.com,4'));
        $this->commitImport($first, ['map' => $map]);
        [$second, $map] = $this->preview($this->posttest, $this->csv('Email Address,Score', 'correct@example.com,8'));
        $this->commitImport($second, ['map' => $map]);
        $issue = $second->issues()->sole();
        $this->actingAs($this->administrator)->post(route('admin.webinars.import-issues.replace', [$this->webinar, $issue]), ['reason' => 'Corrected against original quiz', 'replacement_confirmed' => '1'])->assertSessionHas('success');
        $this->assertSame(1, Submission::query()->where('status', 'superseded')->count());
        $this->assertSame('8.00', Submission::query()->where('status', 'submitted')->sole()->score);
        $this->assertTrue(app(EligibilityService::class)->evaluate($participant->fresh())['eligible']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'import.response_replaced']);
        $report = $this->actingAs($this->administrator)->get(route('admin.webinars.imports.reconciliation', [$this->webinar, $first]));
        $this->assertStringContainsString('Superseded', $report->streamedContent());
        $this->actingAs($this->administrator)->post(route('admin.webinars.import-issues.replace', [$this->webinar, $issue]), ['reason' => 'Replay correction', 'replacement_confirmed' => '1'])->assertSessionHas('error');
    }

    public function test_live_form_response_cannot_be_replaced_by_csv(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'live@example.com', 'full_name' => 'Live Person']);
        Submission::query()->create(['form_id' => $this->posttest->id, 'participant_id' => $participant->id, 'status' => 'submitted', 'score' => 4]);
        [$import, $map] = $this->preview($this->posttest, $this->csv('Email Address,Score', 'live@example.com,8'));
        $this->commitImport($import, ['map' => $map]);
        $this->actingAs($this->administrator)->post(route('admin.webinars.import-issues.replace', [$this->webinar, $import->issues()->sole()]), ['reason' => 'Attempt to replace live quiz', 'replacement_confirmed' => '1'])->assertSessionHas('error');
        $this->assertSame('4.00', Submission::query()->sole()->score);
    }
}
