<?php

namespace Tests\Feature;

use App\Models\EligibilityOverride;
use App\Models\EligibilityRule;
use App\Models\Form;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_webinar_domain_relationships_and_eligibility_are_wired(): void
    {
        $administrator = User::factory()->create();
        $webinar = Webinar::query()->create([
            'title' => 'Security Awareness', 'slug' => 'security-awareness',
            'created_by' => $administrator->id,
        ]);
        $participant = Participant::query()->create([
            'webinar_id' => $webinar->id, 'full_name' => 'Maria Santos',
            'email' => 'maria@example.com', 'verified_at' => now(),
        ]);
        $posttest = Form::query()->create([
            'webinar_id' => $webinar->id, 'type' => 'posttest', 'title' => 'Post-test',
        ]);
        EligibilityRule::query()->create([
            'webinar_id' => $webinar->id, 'requirement' => 'registration', 'is_required' => true,
        ]);
        EligibilityRule::query()->create([
            'webinar_id' => $webinar->id, 'requirement' => 'posttest',
            'is_required' => true, 'minimum_score' => 8,
        ]);
        Submission::query()->create([
            'form_id' => $posttest->id, 'participant_id' => $participant->id,
            'status' => 'submitted', 'score' => 9, 'maximum_score' => 10, 'submitted_at' => now(),
        ]);

        $result = app(EligibilityService::class)->evaluate($participant);

        $this->assertTrue($result['eligible']);
        $this->assertEqualsCanonicalizing(['registration' => true, 'posttest' => true], $result['requirements']);
        $this->assertTrue($webinar->fresh()->participants->contains($participant));
    }

    public function test_audited_administrator_override_takes_precedence(): void
    {
        $administrator = User::factory()->create();
        $webinar = Webinar::query()->create(['title' => 'Workshop', 'slug' => 'workshop', 'created_by' => $administrator->id]);
        $participant = Participant::query()->create(['webinar_id' => $webinar->id, 'email' => 'person@example.com']);
        EligibilityRule::query()->create(['webinar_id' => $webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        EligibilityOverride::query()->create([
            'participant_id' => $participant->id, 'decision' => 'eligible',
            'reason' => 'Attendance confirmed manually.', 'overridden_by' => $administrator->id,
        ]);

        $result = app(EligibilityService::class)->evaluate($participant);

        $this->assertTrue($result['eligible']);
        $this->assertTrue($result['overridden']);
    }
}
