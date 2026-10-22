<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CertificateService;
use App\Services\CsvImportService;
use App\Services\EligibilityService;
use App\Services\FormResponseService;
use App\Services\ParticipantPrivacyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FormResponsesWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->admin);
        $this->webinar = Webinar::factory()->create(['created_by' => $this->admin->id, 'requires_verification' => false]);
        foreach (FormResponseService::TYPES as $type => $label) {
            $this->webinar->forms()->create(['type' => $type, 'title' => $label, 'status' => 'published']);
        }
        $this->webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
    }

    private function participant(string $email = 'person@example.com'): Participant
    {
        return $this->webinar->participants()->create(['email' => $email, 'full_name' => 'Test Participant', 'verified_at' => now()]);
    }

    private function approve(Participant $participant)
    {
        return $this->actingAs($this->admin)->post(route('admin.responses.approve', $this->webinar), ['participants' => [$participant->public_id], 'confirmed' => '1']);
    }

    public function test_native_submissions_appear_in_their_own_tabs_and_exports(): void
    {
        foreach ($this->webinar->forms->sortBy(fn ($form) => array_search($form->type, array_keys(FormResponseService::TYPES))) as $form) {
            $field = $form->fields()->create(['key' => 'feedback', 'label' => 'Useful feedback', 'field_type' => 'text', 'is_required' => true]);
            $this->post($form->shareUrl(), ['full_name' => 'Native Person', 'email' => 'Native@Example.com', 'fields' => [$field->id => 'Answer for '.$form->type], 'privacy_acknowledged' => '1'])->assertSessionHasNoErrors()->assertRedirect();
            $this->actingAs($this->admin)->get(route('admin.responses.index', [$this->webinar, 'type' => $form->type]))->assertOk()->assertSee('Answer for '.$form->type)->assertSee('Native');
            $csv = $this->get(route('admin.responses.export', [$this->webinar, 'type' => $form->type]))->assertOk()->streamedContent();
            $this->assertStringContainsString('Answer for '.$form->type, $csv);
            auth()->logout();
        }
        $this->assertSame(1, Participant::query()->count());
        $this->assertSame(4, Submission::query()->count());
    }

    public function test_imported_answers_in_all_four_forms_are_encrypted_mapped_and_idempotent(): void
    {
        $service = app(CsvImportService::class);
        foreach ($this->webinar->forms->sortBy(fn ($form) => array_search($form->type, array_keys(FormResponseService::TYPES))) as $form) {
            $field = $form->fields()->create(['key' => 'rating', 'label' => 'Overall rating', 'field_type' => 'radio', 'options' => ['1', '2', '3', '4', '5'], 'is_required' => true]);
            $csv = "Email,Full Name,Overall rating,Extra original\nPerson@Example.com,Test Participant,5,Keep this exactly\n";
            $import = $service->storePending($this->webinar, $form, UploadedFile::fake()->createWithContent('responses.csv', $csv));
            $options = ['required_columns' => [2], 'answer_map' => ['field_'.$field->id => 2], 'source_description' => 'Synthetic form export v1', 'source_confirmed' => true, 'allow_import_time' => true];
            $map = ['email' => 0, 'full_name' => 1];
            $result = $service->process($import, $map, options: $options, dryRun: true);
            $this->assertSame(1, $result['valid']);
            $result = $service->process($import->fresh(), $map, options: $options);
            $this->assertSame(1, $result['valid']);
            $submission = $form->submissions()->sole();
            $this->assertSame(['5'], $submission->imported_data['mapped']['field_'.$field->id]);
            $this->assertStringNotContainsString('Keep this exactly', DB::table('submissions')->where('id', $submission->id)->value('imported_data'));
            $this->actingAs($this->admin)->get(route('admin.responses.show', [$this->webinar, $submission]))->assertOk()->assertSee('Keep this exactly');
            $again = $service->storePending($this->webinar, $form, UploadedFile::fake()->createWithContent('responses.csv', $csv));
            $repeat = $service->process($again, $map, options: $options);
            $this->assertSame(0, $repeat['valid']);
            $this->assertSame(1, $repeat['duplicate']);
            $this->assertSame(1, $form->submissions()->count());
            $summary = app(FormResponseService::class)->summary($form, app(FormResponseService::class)->columns($form));
            $this->assertSame(1, $summary['questions']['field_'.$field->id]['counts'][5]);
        }
        $this->assertSame(1, Participant::query()->count());
    }

    public function test_native_and_imported_answers_share_summary_and_search_filters(): void
    {
        $form = $this->webinar->forms->firstWhere('type', 'registration');
        $field = $form->fields()->create(['key' => 'rating', 'label' => 'Rating', 'field_type' => 'radio', 'options' => ['Good', 'Great']]);
        $native = $form->submissions()->create(['participant_id' => $this->participant()->id, 'status' => 'submitted', 'attempt_number' => 1, 'submitted_at' => now()]);
        $native->answers()->create(['form_field_id' => $field->id, 'value' => ['Great']]);
        $form->submissions()->create(['participant_id' => $this->participant('imported@example.com')->id, 'status' => 'submitted', 'attempt_number' => 1, 'submitted_at' => now(), 'metadata' => ['source' => 'csv_import'], 'imported_data' => ['mapped' => ['field_'.$field->id => ['Great']]]]);
        $response = $this->actingAs($this->admin)->get(route('admin.responses.index', [$this->webinar, 'view' => 'summary']))->assertOk()->assertSee('2 (100%)');
        $this->post(route('admin.responses.filter', $this->webinar), ['search' => 'imported@', 'source' => 'imported'])->assertRedirect();
        $this->get(route('admin.responses.index', $this->webinar))->assertOk()->assertSee('imported@example.com')->assertDontSee('person@example.com');
    }

    public function test_each_eligibility_combination_preserves_score_rules_and_optional_forms(): void
    {
        $participant = $this->participant();
        foreach ([['registration'], ['registration', 'pretest', 'posttest'], array_keys(FormResponseService::TYPES)] as $requirements) {
            $rules = [];
            foreach ($requirements as $type) {
                $rules[$type] = ['enabled' => '1'];
            }
            $this->actingAs($this->admin)->put(route('admin.certification.rules', $this->webinar), ['rules' => $rules, 'return_to_responses' => '1'])->assertRedirect(route('admin.responses.index', [$this->webinar, 'view' => 'settings']));
            foreach (array_diff($requirements, ['registration']) as $type) {
                $form = $this->webinar->forms->firstWhere('type', $type);
                $form->submissions()->firstOrCreate(['participant_id' => $participant->id, 'attempt_number' => 1], ['status' => 'submitted', 'score' => 4, 'maximum_score' => 10, 'submitted_at' => now()]);
            }
            $this->assertTrue(app(EligibilityService::class)->evaluate($participant->fresh())['eligible']);
        }
        $this->webinar->eligibilityRules()->where('requirement', 'posttest')->update(['minimum_score' => 8]);
        $result = app(EligibilityService::class)->evaluate($participant->fresh());
        $this->assertFalse($result['eligible']);
        $this->assertFalse($result['requirements']['posttest']);
    }

    public function test_legacy_approval_is_optional_idempotent_audited_and_studio_connected(): void
    {
        $participant = $this->participant();
        $this->actingAs($this->admin)->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertViewHas('participants', fn ($rows) => $rows->contains('id', $participant->id));
        $this->approve($participant)->assertSessionHasNoErrors()->assertRedirect();
        $this->approve($participant)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('certificate_approvals', 1);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.approved', 'auditable_id' => $participant->id]);
        $this->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertViewHas('participants', fn ($rows) => $rows->contains('id', $participant->id));
        $this->get(route('admin.responses.index', [$this->webinar, 'view' => 'eligibility']))->assertOk()->assertSee('Available automatically');
    }

    public function test_eligible_issuance_has_no_approval_gate(): void
    {
        $participant = $this->participant();
        $this->expectExceptionMessage('This webinar has no active certificate template.');
        app(CertificateService::class)->queue($participant);
    }

    public function test_invalid_or_cross_webinar_approvals_are_atomic_and_password_protected(): void
    {
        $valid = $this->participant();
        $invalid = $this->participant('incomplete@example.com');
        $invalid->update(['verified_at' => null]);
        $this->actingAs($this->admin)->post(route('admin.responses.approve', $this->webinar), ['participants' => [$valid->public_id, $invalid->public_id], 'confirmed' => '1'])->assertSessionHasErrors('participants');
        $this->assertDatabaseCount('certificate_approvals', 0);
        $other = Webinar::factory()->create()->participants()->create(['email' => 'other@example.com', 'full_name' => 'Other Person', 'verified_at' => now()]);
        $this->approve($other)->assertSessionHasErrors('participants');
        config(['security.require_sensitive_action_password_confirmation' => true]);
        $this->approve($valid)->assertRedirect(route('admin.password.confirm'));
        $this->assertDatabaseCount('certificate_approvals', 0);
    }

    public function test_changed_rules_warn_without_removing_approval_or_historical_registration(): void
    {
        $participant = $this->participant();
        $this->approve($participant)->assertSessionHasNoErrors();
        $this->webinar->eligibilityRules()->create(['requirement' => 'evaluation', 'is_required' => true]);
        $this->get(route('admin.responses.index', [$this->webinar, 'view' => 'eligibility']))->assertOk()->assertSee('Review needed')->assertSee('Historical approval retained.');
        $this->assertNotNull($participant->fresh()->verified_at);
        $this->assertDatabaseCount('certificate_approvals', 1);
        $this->assertFalse(app(EligibilityService::class)->evaluate($participant->fresh())['eligible']);
    }

    public function test_permissions_and_cross_webinar_submission_access(): void
    {
        auth()->logout();
        $this->get(route('admin.responses.index', $this->webinar))->assertRedirect(route('login'));
        $form = $this->webinar->forms->first();
        $submission = $form->submissions()->create(['participant_id' => $this->participant()->id, 'status' => 'submitted', 'attempt_number' => 1]);
        $other = Webinar::factory()->create();
        $this->actingAs($this->admin)->get(route('admin.responses.show', [$other, $submission]))->assertNotFound();
        $this->get(route('admin.responses.index', [$this->webinar, 'type' => 'bogus']))->assertSessionHasErrors('type');
    }

    public function test_forms_preview_order_and_rating_reuse_existing_builder(): void
    {
        foreach ($this->webinar->forms as $form) {
            $this->actingAs($this->admin)->get(route('admin.forms.edit', [$this->webinar, $form]))->assertOk()->assertSee('Preview form')->assertSee('Form Responses')->assertDontSee('CSV imports');
            $this->post(route('admin.forms.fields.store', [$this->webinar, $form]), ['label' => 'Rate this', 'field_type' => 'rating', 'is_required' => '1'])->assertSessionHasNoErrors();
            $this->post(route('admin.forms.fields.store', [$this->webinar, $form]), ['label' => 'Comments', 'field_type' => 'textarea'])->assertSessionHasNoErrors();
            $rating = $form->fields()->first();
            $this->assertSame(['1', '2', '3', '4', '5'], $rating->options);
            $this->post(route('admin.forms.reorder', [$this->webinar, $form]), ['kind' => 'fields', 'item' => $rating->id, 'direction' => 'down'])->assertSessionHasNoErrors();
            $this->assertSame(2, $rating->fresh()->sort_order);
            $this->get(route('admin.forms.preview', [$this->webinar, $form]))->assertOk()->assertSee('Administrator preview')->assertSee('submissions disabled')->assertSee('Comments');
            $this->post(route('admin.forms.toggle', [$this->webinar, $form]), ['is_open' => '0'])->assertSessionHasNoErrors();
            $this->assertFalse($form->fresh()->acceptsResponses());
            $this->post(route('admin.forms.toggle', [$this->webinar, $form]), ['is_open' => '1'])->assertSessionHasNoErrors();
        }
    }

    public function test_erasure_removes_imported_cells_and_approval(): void
    {
        $participant = $this->participant();
        $this->approve($participant);
        $submission = $this->webinar->forms->first()->submissions()->create(['participant_id' => $participant->id, 'attempt_number' => 1, 'status' => 'submitted', 'imported_data' => ['original' => [['label' => 'Private', 'value' => 'Sensitive original']]]]);
        app(ParticipantPrivacyService::class)->erase($participant);
        $this->assertNull($submission->fresh()->imported_data);
        $this->assertDatabaseCount('certificate_approvals', 0);
    }

    public function test_invalid_mapped_choices_are_held_without_granting_completion(): void
    {
        $participant = $this->participant();
        $form = $this->webinar->forms->firstWhere('type', 'evaluation');
        $field = $form->fields()->create(['key' => 'rating', 'label' => 'Rating', 'field_type' => 'radio', 'options' => ['Good', 'Great'], 'is_required' => true]);
        $service = app(CsvImportService::class);
        $import = $service->storePending($this->webinar, $form, UploadedFile::fake()->createWithContent('invalid.csv', "Email,Rating\nperson@example.com,Unknown choice\n"));
        $result = $service->process($import, ['email' => 0], options: ['required_columns' => [1], 'answer_map' => ['field_'.$field->id => 1], 'source_description' => 'Evaluation export v1', 'source_confirmed' => true, 'allow_import_time' => true]);
        $this->assertSame(1, $result['invalid']);
        $this->assertSame(0, $participant->submissions()->count());
        $this->assertStringContainsString('Unrecognized choice', $import->issues()->sole()->issue_message);
        $this->assertSame('Unknown choice', $import->issues()->sole()->raw_data['Rating']);
    }

    public function test_response_pagination_history_and_spreadsheet_formula_protection(): void
    {
        $form = $this->webinar->forms->firstWhere('type', 'registration');
        $participant = $this->participant();
        $field = $form->fields()->create(['key' => 'comment', 'label' => 'Comments', 'field_type' => 'text']);
        for ($attempt = 1; $attempt <= 26; $attempt++) {
            $submission = $form->submissions()->create(['participant_id' => $participant->id, 'attempt_number' => $attempt, 'status' => 'submitted', 'submitted_at' => now()->addSeconds($attempt)]);
            $submission->answers()->create(['form_field_id' => $field->id, 'value' => [$attempt === 26 ? '=HYPERLINK("example")' : 'Response '.$attempt]]);
        }
        $form->submissions()->create(['participant_id' => $participant->id, 'attempt_number' => 27, 'status' => 'superseded', 'submitted_at' => now()]);
        $this->get(route('admin.responses.index', $this->webinar))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 26 && $rows->count() === 25)
            ->assertSee('27 responses for this participant');
        $this->get(route('admin.responses.index', [$this->webinar, 'page' => 2]))->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->count() === 1);
        $csv = $this->get(route('admin.responses.export', $this->webinar))->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $summary = app(FormResponseService::class)->summary($form, app(FormResponseService::class)->columns($form));
        $this->assertSame(26, $summary['total']);
        $this->assertCount(20, $summary['questions']['field_'.$field->id]['texts']);
    }

    public function test_approval_then_certificate_generation_preserves_name_and_prevents_duplicate_entries(): void
    {
        $participant = $this->participant();
        $file = UploadedFile::fake()->image('certificate.png', 1200, 850);
        Storage::disk('local')->put('certificate-backgrounds/test-workflow.png', file_get_contents($file->getRealPath()));
        $this->webinar->certificateTemplates()->create(['name' => 'Workflow', 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/test-workflow.png', 'is_active' => true, 'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 36, 'name_font_family' => 'sans', 'accent' => '#172554']]);
        $this->approve($participant)->assertSessionHasNoErrors();
        $service = app(CertificateService::class);
        $certificate = $service->issue($participant);
        $repeat = $service->issue($participant->fresh());
        $this->assertSame($certificate->id, $repeat->id);
        $this->assertSame('Test Participant', $certificate->recipient_name);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($certificate->file_path));
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('certificate_approvals', 1);
        $this->assertDatabaseCount('participants', 1);
    }
}
