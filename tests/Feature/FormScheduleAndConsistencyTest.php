<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Participant;
use App\Models\Question;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Models\Webinar;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class FormScheduleAndConsistencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_manila_form_schedule_is_stored_in_utc_and_rendered_in_the_webinar_timezone(): void
    {
        [$webinar, $form] = $this->formInTimezone('Asia/Manila');

        $this->put(route('admin.forms.update', [$webinar, $form]), $this->settings([
            'opens_at' => '2026-09-10T09:30',
            'closes_at' => '2026-09-10T11:00',
        ]))->assertRedirect()->assertSessionHas('success');

        $form->refresh();
        $this->assertSame('2026-09-10 01:30', $form->opens_at?->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-09-10 03:00', $form->closes_at?->utc()->format('Y-m-d H:i'));

        $this->get(route('admin.forms.edit', [$webinar, $form]))
            ->assertOk()
            ->assertDontSee('name="opens_at"', false)
            ->assertSee('value="2026-09-10T11:00"', false);
    }

    public function test_new_york_form_schedule_is_converted_with_the_applicable_dst_offset(): void
    {
        [$webinar, $form] = $this->formInTimezone('America/New_York');

        $this->put(route('admin.forms.update', [$webinar, $form]), $this->settings([
            'opens_at' => '2026-07-15T09:30',
            'closes_at' => '2026-07-15T11:00',
        ]))->assertRedirect()->assertSessionHas('success');

        $form->refresh();
        $this->assertSame('2026-07-15 13:30', $form->opens_at?->utc()->format('Y-m-d H:i'));
        $this->assertSame('2026-07-15 15:00', $form->closes_at?->utc()->format('Y-m-d H:i'));
    }

    public function test_nonexistent_dst_local_time_is_rejected_without_changing_the_schedule(): void
    {
        [$webinar, $form] = $this->formInTimezone('America/New_York');

        $this->from(route('admin.forms.edit', [$webinar, $form]))
            ->put(route('admin.forms.update', [$webinar, $form]), $this->settings([
                // New York advances from 01:59 to 03:00 on this date.
                'opens_at' => '2026-03-08T02:30',
                'closes_at' => '2026-03-08T04:00',
            ]))
            ->assertRedirect(route('admin.forms.edit', [$webinar, $form]))
            ->assertSessionHasErrors('opens_at');

        $form->refresh();
        $this->assertNull($form->opens_at);
        $this->assertNull($form->closes_at);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'form.updated']);
    }

    public function test_answered_question_type_is_rechecked_before_any_mutation(): void
    {
        [$webinar, $form] = $this->formInTimezone('UTC');
        $question = $this->answeredQuestion($webinar, $form);

        $this->from(route('admin.forms.edit', [$webinar, $form]))
            ->put(route('admin.forms.questions.update', [$webinar, $form, $question]), [
                'prompt' => 'Changed wording must roll back too',
                'question_type' => 'multiple_choice',
                'points' => '20',
            ])
            ->assertRedirect(route('admin.forms.edit', [$webinar, $form]))
            ->assertSessionHasErrors('question_type');

        $question->refresh();
        $this->assertSame('Is password reuse safe?', $question->prompt);
        $this->assertSame('true_false', $question->question_type);
        $this->assertEquals(10.0, (float) $question->points);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'question.updated']);
    }

    public function test_question_creation_and_its_audit_record_are_atomic(): void
    {
        [$webinar, $form] = $this->formInTimezone('UTC');

        $this->mock(AuditService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('record')
                ->once()
                ->andThrow(new RuntimeException('simulated audit storage failure'));
        });
        $this->withoutExceptionHandling();

        try {
            $this->post(route('admin.forms.questions.store', [$webinar, $form]), [
                'prompt' => 'Should this survive?',
                'question_type' => 'true_false',
                'points' => '1',
                'choices' => ['Yes', 'No'],
                'correct_choice' => '1',
            ]);
            $this->fail('The simulated audit failure was not raised.');
        } catch (RuntimeException $exception) {
            $this->assertSame('simulated audit storage failure', $exception->getMessage());
        }

        $this->assertDatabaseMissing('questions', ['form_id' => $form->id]);
        $this->assertDatabaseCount('question_choices', 0);
    }

    /**
     * @return array{Webinar, Form}
     */
    public function test_a_form_can_be_opened_and_closed_with_the_availability_switch(): void
    {
        [$webinar, $form] = $this->formInTimezone('UTC');
        $this->assertSame('draft', $form->status);

        // Opening publishes the form in one click, without the full settings form.
        $this->post(route('admin.forms.toggle', [$webinar, $form]), ['status' => 'published'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('published', $form->fresh()->status);

        // Closing stops new responses.
        $this->post(route('admin.forms.toggle', [$webinar, $form]), ['status' => 'closed'])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame('closed', $form->fresh()->status);

        // Only the two valid states are accepted.
        $this->post(route('admin.forms.toggle', [$webinar, $form]), ['status' => 'draft'])
            ->assertSessionHasErrors('status');
    }

    private function formInTimezone(string $timezone): array
    {
        $administrator = User::factory()->create();
        $webinar = Webinar::factory()->create([
            'created_by' => $administrator->id,
            'timezone' => $timezone,
        ]);
        $form = $webinar->forms()->create([
            'type' => 'posttest',
            'title' => 'Post-assessment',
            'status' => 'draft',
        ]);

        $this->actingAs($administrator);

        return [$webinar, $form];
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function settings(array $overrides = []): array
    {
        return [
            'title' => 'Post-assessment',
            'description' => null,
            'status' => 'draft',
            'opens_at' => null,
            'closes_at' => null,
            'max_attempts' => 1,
            ...$overrides,
        ];
    }

    private function answeredQuestion(Webinar $webinar, Form $form): Question
    {
        $question = $form->questions()->create([
            'prompt' => 'Is password reuse safe?',
            'question_type' => 'true_false',
            'points' => 10,
            'is_required' => true,
        ]);
        $correctChoice = $question->choices()->create([
            'label' => 'No',
            'is_correct' => true,
            'sort_order' => 1,
        ]);
        $question->choices()->create([
            'label' => 'Yes',
            'is_correct' => false,
            'sort_order' => 2,
        ]);
        $participant = Participant::query()->create([
            'webinar_id' => $webinar->id,
            'email' => 'participant@example.test',
            'verified_at' => now(),
        ]);
        $submission = Submission::query()->create([
            'form_id' => $form->id,
            'participant_id' => $participant->id,
            'status' => 'submitted',
            'submitted_at' => now(),
        ]);
        SubmissionAnswer::query()->create([
            'submission_id' => $submission->id,
            'question_id' => $question->id,
            'value' => [$correctChoice->id],
            'is_correct' => true,
        ]);

        return $question;
    }
}
