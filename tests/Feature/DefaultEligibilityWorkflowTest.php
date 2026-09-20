<?php

namespace Tests\Feature;

use App\Models\EligibilityOverride;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultEligibilityWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    private EligibilityService $eligibility;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->actingAs($this->administrator)->post(route('admin.webinars.store'), [
            'title' => 'Default Eligibility Test Event',
            'status' => 'published',
            'timezone' => 'UTC',
            'data_retention_days' => 14,
            'ends_at' => now()->addDays(2)->format('Y-m-d H:i:s'),
        ])->assertRedirect();

        $this->webinar = Webinar::query()->where('slug', 'default-eligibility-test-event')->firstOrFail();
        $this->eligibility = app(EligibilityService::class);
    }

    public function test_new_webinar_defaults_to_pretest_posttest_evaluation_required(): void
    {
        $rules = $this->webinar->eligibilityRules()->where('is_required', true)->get();

        $this->assertEqualsCanonicalizing(
            ['pretest', 'posttest', 'evaluation'],
            $rules->pluck('requirement')->all(),
        );
        $this->assertSame(0, $rules->where('requirement', 'registration')->count());
    }

    public function test_participant_is_eligible_with_pretest_posttest_evaluation_and_no_registration(): void
    {
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => 'Walk In Attendee',
            'email' => 'walkin@example.com',
            'verified_at' => null, // No registration verified
        ]);

        foreach (['pretest', 'posttest', 'evaluation'] as $type) {
            $form = $this->webinar->forms()->where('type', $type)->firstOrFail();
            Submission::query()->create([
                'form_id' => $form->id,
                'participant_id' => $participant->id,
                'status' => 'submitted',
                'attempt_number' => 1,
                'submitted_at' => now(),
            ]);
        }

        $evaluation = $this->eligibility->evaluate($participant->fresh());
        $this->assertTrue($evaluation['eligible'], 'Participant should be eligible with pretest, posttest, and evaluation completed.');
        $this->assertFalse($evaluation['overridden']);

        $sqlEligible = $this->eligibility->eligibleParticipantsQuery($this->webinar)->pluck('participants.id')->all();
        $this->assertContains($participant->id, $sqlEligible);
    }

    public function test_participant_is_ineligible_if_any_required_stage_is_missing(): void
    {
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => 'Incomplete Attendee',
            'email' => 'incomplete@example.com',
        ]);

        // Submits pretest and posttest, but misses evaluation
        foreach (['pretest', 'posttest'] as $type) {
            $form = $this->webinar->forms()->where('type', $type)->firstOrFail();
            Submission::query()->create([
                'form_id' => $form->id,
                'participant_id' => $participant->id,
                'status' => 'submitted',
                'attempt_number' => 1,
                'submitted_at' => now(),
            ]);
        }

        $evaluation = $this->eligibility->evaluate($participant->fresh());
        $this->assertFalse($evaluation['eligible'], 'Participant missing evaluation should not be eligible.');

        $sqlEligible = $this->eligibility->eligibleParticipantsQuery($this->webinar)->pluck('participants.id')->all();
        $this->assertNotContains($participant->id, $sqlEligible);
    }

    public function test_administrator_override_supersedes_automatic_default_eligibility(): void
    {
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => 'Override Attendee',
            'email' => 'override@example.com',
        ]);

        // Has zero submissions, but administrator explicitly marks eligible
        EligibilityOverride::query()->create([
            'participant_id' => $participant->id,
            'decision' => 'eligible',
            'reason' => 'Off-platform attendee override',
            'overridden_by' => $this->administrator->id,
        ]);

        $evaluation = $this->eligibility->evaluate($participant->fresh());
        $this->assertTrue($evaluation['eligible']);
        $this->assertTrue($evaluation['overridden']);

        $sqlEligible = $this->eligibility->eligibleParticipantsQuery($this->webinar)->pluck('participants.id')->all();
        $this->assertContains($participant->id, $sqlEligible);
    }
}
