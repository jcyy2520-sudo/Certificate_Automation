<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Form;
use App\Models\Participant;
use App\Models\Question;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    private Form $posttest;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->administrator)->post(route('admin.webinars.store'), [
            'title' => 'Cyber Hygiene Clinic', 'status' => 'published', 'timezone' => 'UTC',
            'data_retention_days' => 7, 'ends_at' => now()->addDay()->format('Y-m-d H:i:s'),
        ]);
        $this->webinar = Webinar::query()->where('slug', 'cyber-hygiene-clinic')->firstOrFail();
        $this->posttest = $this->webinar->forms->firstWhere('type', 'posttest');
    }

    // ---- Fields -------------------------------------------------------

    public function test_a_field_can_be_updated(): void
    {
        $registration = $this->webinar->forms->firstWhere('type', 'registration');
        $this->actingAs($this->administrator)->post(route('admin.forms.fields.store', [$this->webinar, $registration]), [
            'label' => 'Job role', 'field_type' => 'text', 'is_required' => '1',
        ]);
        $field = $registration->fields()->firstOrFail();

        $this->actingAs($this->administrator)->put(route('admin.forms.fields.update', [$this->webinar, $registration, $field]), [
            'label' => 'Position', 'field_type' => 'select',
            'options_text' => "Nurse\nDoctor\nAdmin", 'help_text' => 'Pick the closest match',
        ])->assertRedirect()->assertSessionHas('success');

        $field->refresh();
        $this->assertSame('Position', $field->label);
        $this->assertSame('job_role', $field->key, 'The key is generated once from the original label and never changes on edit.');
        $this->assertSame('select', $field->field_type);
        $this->assertSame(['Nurse', 'Doctor', 'Admin'], $field->options);
        $this->assertFalse($field->is_required, 'An unchecked box must clear the flag.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'form_field.updated']);
    }

    public function test_duplicate_labels_get_unique_auto_generated_keys(): void
    {
        $registration = $this->webinar->forms->firstWhere('type', 'registration');
        foreach (['Department', 'Department', 'Department'] as $label) {
            $this->actingAs($this->administrator)->post(route('admin.forms.fields.store', [$this->webinar, $registration]), [
                'label' => $label, 'field_type' => 'text',
            ]);
        }

        $keys = $registration->fields()->orderBy('id')->pluck('key')->all();
        $this->assertSame(['department', 'department_2', 'department_3'], $keys);
    }

    public function test_a_field_from_another_form_is_not_reachable(): void
    {
        $registration = $this->webinar->forms->firstWhere('type', 'registration');
        $this->actingAs($this->administrator)->post(route('admin.forms.fields.store', [$this->webinar, $registration]), [
            'label' => 'Job role', 'field_type' => 'text',
        ]);
        $field = $registration->fields()->firstOrFail();

        $this->actingAs($this->administrator)->put(route('admin.forms.fields.update', [$this->webinar, $this->posttest, $field]), [
            'label' => 'Hijacked', 'field_type' => 'text',
        ])->assertNotFound();
    }

    // ---- Questions ----------------------------------------------------

    public function test_an_unanswered_question_can_be_fully_rewritten(): void
    {
        $question = $this->addQuestion();

        $this->actingAs($this->administrator)->put(route('admin.forms.questions.update', [$this->webinar, $this->posttest, $question]), [
            'prompt' => 'Is reusing a password safe?', 'question_type' => 'multiple_choice', 'points' => '5',
            'choices' => ['Never', 'Only for throwaway accounts', 'Always'], 'correct_choice' => '1',
            'explanation' => 'Reuse spreads a breach.', 'is_required' => '1',
        ])->assertRedirect()->assertSessionHas('success');

        $question->refresh()->load('choices');
        $this->assertSame('Is reusing a password safe?', $question->prompt);
        $this->assertEquals(5.0, (float) $question->points);
        $this->assertSame(['Never', 'Only for throwaway accounts', 'Always'], $question->choices->pluck('label')->all());
        $this->assertSame('Only for throwaway accounts', $question->choices->firstWhere('is_correct', true)->label);
    }

    public function test_an_answered_question_keeps_its_choices_but_allows_wording_fixes(): void
    {
        $question = $this->addQuestion();
        $this->answer($question);

        // Wording and points stay editable. A locked question's real edit form
        // never renders choice inputs at all, so this submits none — exactly
        // what a real browser would send.
        $this->actingAs($this->administrator)->put(route('admin.forms.questions.update', [$this->webinar, $this->posttest, $question]), [
            'prompt' => 'Is password reuse safe? (corrected)', 'question_type' => 'true_false', 'points' => '20',
        ])->assertRedirect()->assertSessionHas('success');

        $question->refresh();
        $this->assertSame('Is password reuse safe? (corrected)', $question->prompt);
        $this->assertEquals(20.0, (float) $question->points);

        // A crafted request that does attempt to change the choices is refused
        // so recorded answers stay meaningful.
        $before = $question->choices()->pluck('label')->all();
        $this->actingAs($this->administrator)
            ->from(route('admin.forms.edit', [$this->webinar, $this->posttest]))
            ->put(route('admin.forms.questions.update', [$this->webinar, $this->posttest, $question]), [
                'prompt' => 'Is password reuse safe?', 'question_type' => 'true_false', 'points' => '10',
                'choices' => ['Completely different', 'Something else'], 'correct_choice' => '0',
            ])->assertSessionHasErrors('choices');

        $this->assertSame($before, $question->choices()->pluck('label')->all());
    }

    public function test_a_rewritten_question_still_needs_a_correct_choice(): void
    {
        $question = $this->addQuestion();

        $this->actingAs($this->administrator)
            ->from(route('admin.forms.edit', [$this->webinar, $this->posttest]))
            ->put(route('admin.forms.questions.update', [$this->webinar, $this->posttest, $question]), [
                'prompt' => 'Pick one', 'question_type' => 'multiple_choice', 'points' => '1',
                'choices' => ['One', 'Two'],
            ])->assertSessionHasErrors('choices');
    }

    // ---- Webinar deletion ---------------------------------------------

    public function test_deleting_a_webinar_requires_its_exact_title(): void
    {
        $this->actingAs($this->administrator)
            ->from(route('admin.webinars.show', $this->webinar))
            ->delete(route('admin.webinars.destroy', $this->webinar), ['confirm' => 'wrong title'])
            ->assertSessionHasErrors('confirm');

        $this->assertDatabaseHas('webinars', ['id' => $this->webinar->id]);
    }

    public function test_a_confirmed_webinar_deletion_removes_its_whole_tree(): void
    {
        Storage::fake('local');

        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Ana', 'email' => 'ana@example.com', 'verified_at' => now(),
        ]);
        Submission::query()->create([
            'form_id' => $this->posttest->id, 'participant_id' => $participant->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $certificate = Certificate::query()->create([
            'verification_code' => 'CERT-DELETEWEBINAR01', 'webinar_id' => $this->webinar->id,
            'participant_id' => $participant->id,
            'certificate_template_id' => $this->webinar->certificateTemplates()->firstOrFail()->id,
            'recipient_name' => 'Ana', 'storage_disk' => 'local', 'status' => 'issued', 'issued_at' => now(),
        ]);
        $certificatePath = 'certificates/'.$certificate->public_id.'.pdf';
        Storage::disk('local')->put($certificatePath, 'PRIVATE WEBINAR PDF');
        $certificate->update(['file_path' => $certificatePath]);
        $delivery = EmailDelivery::query()->create([
            'webinar_id' => $this->webinar->id, 'participant_id' => $participant->id,
            'certificate_id' => $certificate->id, 'type' => 'certificate',
            'recipient_email' => 'ana@example.com', 'subject' => 'Certificate',
            'payload' => ['html' => 'Hello Ana'], 'last_error' => 'Private provider error',
        ]);

        $this->actingAs($this->administrator)
            ->delete(route('admin.webinars.destroy', $this->webinar), ['confirm' => 'Cyber Hygiene Clinic'])
            ->assertRedirect(route('admin.webinars.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('webinars', ['id' => $this->webinar->id]);
        $this->assertSame(0, Participant::query()->count());
        $this->assertSame(0, Submission::query()->count());
        $this->assertSame(0, Form::query()->count());
        Storage::disk('local')->assertMissing($certificatePath);
        $this->assertNull($delivery->fresh()->recipient_email);
        $this->assertNull($delivery->fresh()->payload);
        $this->assertNull($delivery->fresh()->last_error);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webinar.deleted']);
    }

    // ---- Participant deletion -----------------------------------------

    public function test_deleting_a_participant_removes_responses_but_keeps_certificate_verification(): void
    {
        Storage::fake('local');

        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Ana Cruz', 'email' => 'ana@example.com', 'verified_at' => now(),
        ]);
        Submission::query()->create([
            'form_id' => $this->posttest->id, 'participant_id' => $participant->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        $certificate = Certificate::query()->create([
            'verification_code' => 'CERT-KEEPTHISONEALIVE', 'webinar_id' => $this->webinar->id,
            'participant_id' => $participant->id,
            'certificate_template_id' => $this->webinar->certificateTemplates()->firstOrFail()->id,
            'recipient_name' => 'Ana Cruz', 'storage_disk' => 'local', 'status' => 'issued', 'issued_at' => now(),
        ]);
        $certificatePath = 'certificates/'.$certificate->public_id.'.pdf';
        Storage::disk('local')->put($certificatePath, 'PRIVATE PARTICIPANT PDF');
        $certificate->update(['file_path' => $certificatePath]);

        $this->actingAs($this->administrator)
            ->delete(route('admin.participants.destroy', [$this->webinar, $participant]))
            ->assertRedirect(route('admin.participants.index', $this->webinar))
            ->assertSessionHas('success');

        $this->assertSame(0, Participant::withTrashed()->count(), 'The delete must be permanent, not a soft delete.');
        $this->assertSame(0, Submission::query()->count());

        $certificate->refresh();
        $this->assertNull($certificate->participant_id);
        $this->assertNull($certificate->recipient_name, 'The recipient name is personal data and must go.');
        $this->assertNull($certificate->file_path);
        Storage::disk('local')->assertMissing($certificatePath);
        $this->get(route('certificates.verify', $certificate->verification_code))->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'participant.deleted']);
    }

    public function test_a_participant_from_another_webinar_cannot_be_deleted(): void
    {
        $other = Webinar::query()->create([
            'title' => 'Other', 'slug' => 'other', 'timezone' => 'UTC', 'created_by' => $this->administrator->id,
        ]);
        $participant = Participant::query()->create(['webinar_id' => $this->webinar->id, 'email' => 'ana@example.com']);

        $this->actingAs($this->administrator)
            ->delete(route('admin.participants.destroy', [$other, $participant]))
            ->assertNotFound();

        $this->assertSame(1, Participant::query()->count());
    }

    // ---- Helpers ------------------------------------------------------

    private function addQuestion(): Question
    {
        $this->actingAs($this->administrator)->post(route('admin.forms.questions.store', [$this->webinar, $this->posttest]), [
            'prompt' => 'Is password reuse safe?', 'question_type' => 'true_false', 'points' => '10',
            'choices' => ['Yes', 'No'], 'correct_choice' => '1', 'is_required' => '1',
        ]);

        return $this->posttest->questions()->with('choices')->firstOrFail();
    }

    private function answer(Question $question): void
    {
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'email' => 'ana@example.com', 'verified_at' => now(),
        ]);
        $submission = Submission::query()->create([
            'form_id' => $this->posttest->id, 'participant_id' => $participant->id,
            'status' => 'submitted', 'submitted_at' => now(),
        ]);
        SubmissionAnswer::query()->create([
            'submission_id' => $submission->id, 'question_id' => $question->id,
            'value' => [$question->choices->firstWhere('is_correct', true)->id], 'is_correct' => true,
        ]);
    }
}
