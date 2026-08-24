<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicFormController;
use App\Models\Form;
use App\Models\Participant;
use App\Models\Question;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicFormTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    private Form $registration;

    protected function setUp(): void
    {
        parent::setUp();

        $administrator = User::factory()->create(['is_active' => true]);
        $this->webinar = Webinar::factory()->create([
            'title' => 'Cyber Hygiene Clinic', 'slug' => 'cyber-hygiene-clinic',
            'created_by' => $administrator->id,
            'requires_verification' => false,
        ]);
        $this->registration = $this->webinar->forms()->create([
            'type' => 'registration', 'title' => 'Registration', 'status' => 'published',
        ]);
    }

    public function test_a_share_link_opens_only_that_form(): void
    {
        $this->registration->fields()->create(['key' => 'role', 'label' => 'Job role', 'field_type' => 'text', 'is_required' => true]);

        $response = $this->get($this->registration->shareUrl());

        $response->assertOk()
            ->assertSee('Registration')
            ->assertSee('Job role')
            ->assertSee('Cyber Hygiene Clinic');

        // The page must offer no route into the rest of the application.
        $response->assertDontSee(route('login'))
            ->assertDontSee('/admin')
            ->assertDontSee('Dashboard')
            ->assertSee('noindex, nofollow, noarchive', false);
    }

    public function test_the_complete_public_form_journey_stays_isolated_and_ends_on_thank_you_pages(): void
    {
        $forms = collect([$this->registration]);

        foreach ([
            'pretest' => 'Pre-assessment',
            'posttest' => 'Post-assessment',
            'evaluation' => 'Evaluation',
        ] as $type => $title) {
            $forms->push($this->webinar->forms()->create([
                'type' => $type,
                'title' => $title,
                'status' => 'published',
            ]));
        }

        foreach ($forms as $form) {
            $this->get($form->shareUrl())
                ->assertOk()
                ->assertSee($form->title)
                ->assertSee('Submit')
                ->assertSee('role="progressbar"', false)
                ->assertDontSee('stepper-dot')
                ->assertDontSee(route('login'))
                ->assertDontSee('/admin')
                ->assertDontSee('Dashboard')
                ->assertSee('noindex, nofollow, noarchive', false);

            $this->post($form->shareUrl(), [
                'full_name' => 'Public Participant',
                'email' => 'participant@example.com',
                'privacy_acknowledged' => '1',
            ])->assertRedirect(route('forms.public.thanks', $form->public_token));

            $this->get(route('forms.public.thanks', $form->public_token))
                ->assertOk()
                ->assertSee('Your response has been recorded')
                ->assertSee('Thank you for completing this form.')
                ->assertSee('You can close this page now.')
                ->assertSee(route('forms.public.status', $form->public_token), false)
                ->assertSee('aria-valuenow="100"', false)
                ->assertDontSee('stepper-dot')
                ->assertDontSee('Submit')
                ->assertDontSee(route('login'))
                ->assertDontSee('/admin')
                ->assertDontSee('Dashboard');
        }

        $this->assertSame(1, Participant::query()->count());
        $this->assertSame(4, Submission::query()->count());
    }

    public function test_submitting_records_the_participant_and_shows_a_thank_you(): void
    {
        $field = $this->registration->fields()->create(['key' => 'role', 'label' => 'Job role', 'field_type' => 'text', 'is_required' => true]);

        $this->post($this->registration->shareUrl(), [
            'full_name' => 'Maria Santos',
            'email' => 'Maria@Example.com',
            'organization' => 'City Health Office',
            'privacy_acknowledged' => '1',
            'fields' => [$field->id => 'Nurse'],
        ])->assertRedirect(route('forms.public.thanks', $this->registration->public_token));

        $participant = Participant::query()->where('email', 'maria@example.com')->firstOrFail();
        $this->assertSame('Maria Santos', $participant->full_name);
        $this->assertSame($this->webinar->id, $participant->webinar_id);
        // Completing the registration form is what verifies a participant now.
        $this->assertNotNull($participant->verified_at);
        $submission = Submission::query()->firstOrFail();
        $this->assertSame(PublicFormController::PRIVACY_NOTICE_VERSION, $submission->metadata['privacy_notice_version']);
        $this->assertNotEmpty($submission->metadata['privacy_acknowledged_at']);

        $this->followingRedirects()
            ->post($this->registration->shareUrl().'x', [])
            ->assertNotFound();
    }

    public function test_the_thank_you_page_shows_a_score_only_when_the_form_reveals_it(): void
    {
        $this->post($this->registration->shareUrl(), [
            'full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'privacy_acknowledged' => '1',
        ]);
        $posttest = $this->publishedPosttest(showScore: true);
        $question = $posttest->questions->first();
        $correct = $question->choices->firstWhere('is_correct', true);

        $this->followingRedirects()
            ->post($posttest->shareUrl(), [
                'full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'privacy_acknowledged' => '1',
                'questions' => [$question->id => $correct->id],
            ])
            ->assertOk()
            ->assertSee('Your response has been recorded')
            ->assertSee('Your score')
            ->assertSee('10.0');

        // Refreshing the thank-you page must not resurface the result.
        $this->get(route('forms.public.thanks', $posttest->public_token))
            ->assertOk()
            ->assertDontSee('Your score');
    }

    public function test_a_hidden_score_is_never_shown_to_the_participant(): void
    {
        $this->post($this->registration->shareUrl(), [
            'full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'privacy_acknowledged' => '1',
        ]);
        $posttest = $this->publishedPosttest(showScore: false);
        $question = $posttest->questions->first();

        $this->followingRedirects()
            ->post($posttest->shareUrl(), [
                'full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'privacy_acknowledged' => '1',
                'questions' => [$question->id => $question->choices->firstWhere('is_correct', true)->id],
            ])
            ->assertOk()
            ->assertDontSee('Your score');

        // The score is still recorded for the organizer.
        $this->assertEquals(10.0, (float) Submission::query()->where('form_id', $posttest->id)->firstOrFail()->score);
    }

    public function test_responses_from_the_same_email_link_to_one_participant(): void
    {
        $posttest = $this->publishedPosttest();
        $question = $posttest->questions->first();

        $this->post($this->registration->shareUrl(), ['full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'privacy_acknowledged' => '1']);
        $this->post($posttest->shareUrl(), [
            'full_name' => 'Maria Santos', 'email' => 'MARIA@example.com', 'privacy_acknowledged' => '1',
            'questions' => [$question->id => $question->choices->firstWhere('is_correct', true)->id],
        ]);

        $this->assertSame(1, Participant::query()->count());
        $this->assertSame(2, Participant::query()->firstOrFail()->submissions()->count());
    }

    public function test_the_attempt_limit_is_enforced_per_email(): void
    {
        $this->post($this->registration->shareUrl(), ['full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'privacy_acknowledged' => '1']);

        $this->from($this->registration->shareUrl())
            ->post($this->registration->shareUrl(), ['full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'privacy_acknowledged' => '1'])
            ->assertRedirect(route('forms.public.thanks', $this->registration->public_token));

        $this->assertSame(1, Submission::query()->count());
    }

    public function test_a_closed_or_unpublished_form_shows_a_notice_and_refuses_responses(): void
    {
        $this->registration->update(['status' => 'draft']);

        $this->get($this->registration->shareUrl())
            ->assertOk()
            ->assertSee('not accepting responses')
            ->assertDontSee('Submit');

        $this->post($this->registration->shareUrl(), ['full_name' => 'Late', 'email' => 'late@example.com', 'privacy_acknowledged' => '1'])
            ->assertForbidden();

        $this->registration->update(['status' => 'published', 'closes_at' => now()->subHour()]);
        $this->get($this->registration->shareUrl())->assertOk()->assertSee('no longer accepting responses');
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_an_unknown_or_rotated_token_is_a_plain_404(): void
    {
        $this->get('/f/'.str_repeat('a', 24))->assertNotFound();

        $old = $this->registration->public_token;
        $this->registration->update(['public_token' => Form::newPublicToken()]);

        $this->get('/f/'.$old)->assertNotFound();
        $this->post('/f/'.$old, ['full_name' => 'X', 'email' => 'x@example.com'])->assertNotFound();
    }

    public function test_a_form_on_an_archived_webinar_stops_accepting_responses(): void
    {
        $this->webinar->update(['archived_at' => now()]);

        $this->get($this->registration->shareUrl())->assertNotFound();
    }

    public function test_answers_outside_the_offered_choices_are_rejected(): void
    {
        $posttest = $this->publishedPosttest();
        $foreign = Question::query()->create([
            'form_id' => $this->registration->id, 'prompt' => 'Other form question', 'question_type' => 'multiple_choice', 'points' => 1,
        ])->choices()->create(['label' => 'Injected', 'is_correct' => true]);

        $this->from($posttest->shareUrl())->post($posttest->shareUrl(), [
            'full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'privacy_acknowledged' => '1',
            'questions' => [$posttest->questions->first()->id => $foreign->id],
        ])->assertSessionHasErrors();

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_a_required_question_must_be_answered(): void
    {
        $posttest = $this->publishedPosttest();
        $answeredQuestion = $posttest->questions->first();
        $answer = $answeredQuestion->choices->first();
        $missingQuestion = $posttest->questions()->create([
            'prompt' => 'Should unique passwords be used?', 'question_type' => 'true_false', 'points' => 10,
        ]);
        $missingQuestion->choices()->create(['label' => 'Yes', 'is_correct' => true]);
        $missingQuestion->choices()->create(['label' => 'No', 'is_correct' => false]);

        $this->from($posttest->shareUrl())->post($posttest->shareUrl(), [
            'full_name' => 'Maria Santos', 'email' => 'maria@example.com', 'organization' => 'City Archives',
            'privacy_acknowledged' => '1', 'questions' => [$answeredQuestion->id => $answer->id],
        ])->assertSessionHasErrors('questions.'.$missingQuestion->id)
            ->assertSessionHas('_old_input', [
                'questions' => [$answeredQuestion->id => $answer->id],
                'privacy_acknowledged' => '1',
            ]);

        $this->get($posttest->shareUrl())
            ->assertOk()
            ->assertSee('value="'.$answer->id.'" checked', false)
            ->assertDontSee('value="Maria Santos"', false)
            ->assertDontSee('value="maria@example.com"', false)
            ->assertDontSee('value="City Archives"', false);

        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_name_and_email_are_always_required(): void
    {
        $this->from($this->registration->shareUrl())
            ->post($this->registration->shareUrl(), ['full_name' => '', 'email' => 'not-an-email', 'privacy_acknowledged' => '1'])
            ->assertSessionHasErrors(['full_name', 'email']);

        $this->assertDatabaseCount('participants', 0);
    }

    public function test_privacy_acknowledgement_is_required(): void
    {
        $this->from($this->registration->shareUrl())
            ->post($this->registration->shareUrl(), [
                'full_name' => 'Maria Santos', 'email' => 'maria@example.com',
            ])
            ->assertSessionHasErrors('privacy_acknowledged');

        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_text_answers_must_be_strings_not_nested_payloads(): void
    {
        $field = $this->registration->fields()->create([
            'key' => 'comments', 'label' => 'Comments', 'field_type' => 'textarea', 'is_required' => true,
        ]);

        $this->from($this->registration->shareUrl())
            ->post($this->registration->shareUrl(), [
                'full_name' => 'Maria Santos', 'email' => 'maria@example.com',
                'privacy_acknowledged' => '1', 'fields' => [$field->id => [str_repeat('x', 5000)]],
            ])
            ->assertSessionHasErrors('fields.'.$field->id);

        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submission_answers', 0);
    }

    public function test_a_public_form_cannot_overwrite_an_existing_identity(): void
    {
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Original Name',
            'email' => 'person@example.com', 'organization' => 'Original Organization',
        ]);

        $this->post($this->registration->shareUrl(), [
            'full_name' => 'Injected Name', 'email' => 'person@example.com',
            'organization' => 'Injected Organization', 'privacy_acknowledged' => '1',
        ])->assertRedirect();

        $participant->refresh();
        $this->assertSame('Original Name', $participant->full_name);
        $this->assertSame('Original Organization', $participant->organization);
    }

    public function test_only_an_open_webinar_before_the_registration_deadline_accepts_responses(): void
    {
        foreach (['draft', 'completed'] as $status) {
            $this->webinar->update(['status' => $status]);
            $this->get($this->registration->shareUrl())->assertOk()->assertSee('not accepting responses');
            $this->post($this->registration->shareUrl(), [
                'full_name' => 'Blocked', 'email' => $status.'@example.com', 'privacy_acknowledged' => '1',
            ])->assertForbidden();
        }

        $this->webinar->update([
            'status' => 'published', 'registration_opens_at' => now()->addHour(), 'registration_closes_at' => null,
        ]);
        $this->get($this->registration->shareUrl())->assertOk()->assertDontSee('not accepting responses');

        $this->webinar->update([
            'registration_opens_at' => now()->subHours(2), 'registration_closes_at' => now()->subHour(),
        ]);
        $this->get($this->registration->shareUrl())->assertOk()->assertSee('Registration is closed.');
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_an_erased_email_cannot_be_reused(): void
    {
        Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'email' => 'erased@example.com', 'privacy_erased_at' => now(),
        ]);

        $this->from($this->registration->shareUrl())
            ->post($this->registration->shareUrl(), ['full_name' => 'Someone', 'email' => 'erased@example.com', 'privacy_acknowledged' => '1'])
            ->assertRedirect(route('forms.public.thanks', $this->registration->public_token));

        $this->assertDatabaseCount('submissions', 0);
    }

    private function publishedPosttest(bool $showScore = true): Form
    {
        $form = $this->webinar->forms()->create([
            'type' => 'posttest', 'title' => 'Post-assessment', 'status' => 'published',
            'show_score' => $showScore, 'max_attempts' => 1,
        ]);
        $question = $form->questions()->create(['prompt' => 'Is password reuse safe?', 'question_type' => 'true_false', 'points' => 10]);
        $question->choices()->create(['label' => 'Yes', 'is_correct' => false, 'sort_order' => 1]);
        $question->choices()->create(['label' => 'No', 'is_correct' => true, 'sort_order' => 2]);

        return $form->load('questions.choices');
    }
}
