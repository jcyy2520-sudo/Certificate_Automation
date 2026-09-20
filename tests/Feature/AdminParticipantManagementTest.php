<?php

namespace Tests\Feature;

use App\Models\EligibilityOverride;
use App\Models\EligibilityRule;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminParticipantManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->webinar = Webinar::query()->create([
            'title' => 'Records Management', 'slug' => 'records-management',
            'status' => 'published', 'timezone' => 'UTC', 'created_by' => $this->administrator->id,
        ]);
        $this->participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Maria Santos',
            'email' => 'maria@example.com', 'organization' => 'City Archives',
        ]);
    }

    public function test_the_participant_list_renders_and_can_be_searched(): void
    {
        Participant::query()->create(['webinar_id' => $this->webinar->id, 'full_name' => 'Ben Ocampo', 'email' => 'ben@example.com']);

        $this->actingAs($this->administrator)
            ->get(route('admin.participants.index', $this->webinar))
            ->assertOk()
            ->assertSee('Maria Santos')
            ->assertSee('Ben Ocampo');

        $filterResponse = $this->actingAs($this->administrator)
            ->post(route('admin.participants.filter', $this->webinar), ['search' => 'ben@']);
        $filterResponse->assertRedirect(route('admin.participants.index', $this->webinar));
        $this->assertStringNotContainsString('ben', (string) $filterResponse->headers->get('Location'));

        $this->get(route('admin.participants.index', $this->webinar))
            ->assertOk()
            ->assertSee('Ben Ocampo')
            ->assertDontSee('Maria Santos');
    }

    public function test_the_participant_list_distinguishes_not_sent_and_missing_email_states(): void
    {
        EligibilityRule::query()->create([
            'webinar_id' => $this->webinar->id,
            'requirement' => 'registration',
            'is_required' => true,
        ]);
        Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => 'No Email Participant',
        ]);

        $this->actingAs($this->administrator)
            ->get(route('admin.participants.index', $this->webinar))
            ->assertOk()
            ->assertSee('Not sent')
            ->assertSee('Email needed')
            ->assertDontSee('Ready to send')
            ->assertDontSee('Ready to generate and email');
    }

    public function test_the_participant_record_renders_with_its_eligibility_state(): void
    {
        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);

        $this->actingAs($this->administrator)
            ->get(route('admin.participants.show', [$this->webinar, $this->participant]))
            ->assertOk()
            ->assertSee('Maria Santos')
            ->assertSee('City Archives')
            ->assertSee('not eligible');
    }

    public function test_a_participant_from_another_webinar_is_not_reachable(): void
    {
        $other = Webinar::query()->create(['title' => 'Other', 'slug' => 'other-event', 'created_by' => $this->administrator->id]);

        $this->actingAs($this->administrator)
            ->get(route('admin.participants.show', [$other, $this->participant]))
            ->assertNotFound();

        $this->actingAs($this->administrator)
            ->post(route('admin.participants.override', [$other, $this->participant]), [
                'decision' => 'eligible', 'reason' => 'Attendance confirmed by the host.',
            ])->assertNotFound();
    }

    public function test_an_override_is_recorded_audited_and_applied(): void
    {
        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);

        $this->assertFalse(app(EligibilityService::class)->evaluate($this->participant)['eligible']);

        $this->actingAs($this->administrator)
            ->post(route('admin.participants.override', [$this->webinar, $this->participant]), [
                'decision' => 'eligible',
                'reason' => 'Attendance confirmed against the platform recording.',
            ])->assertRedirect()->assertSessionHas('success');

        $result = app(EligibilityService::class)->evaluate($this->participant->fresh());
        $this->assertTrue($result['eligible']);
        $this->assertTrue($result['overridden']);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'eligibility.overridden',
            'user_id' => $this->administrator->id,
        ]);
    }

    public function test_an_active_override_is_visible_and_can_be_removed_after_password_confirmation(): void
    {
        config()->set('security.require_sensitive_action_password_confirmation', true);
        EligibilityRule::query()->create([
            'webinar_id' => $this->webinar->id,
            'requirement' => 'registration',
            'is_required' => true,
        ]);
        $override = EligibilityOverride::query()->create([
            'participant_id' => $this->participant->id,
            'decision' => 'eligible',
            'reason' => 'Off-platform attendance was verified.',
            'overridden_by' => $this->administrator->id,
        ]);
        $route = route('admin.participants.override.destroy', [$this->webinar, $this->participant, $override]);

        $this->actingAs($this->administrator)
            ->get(route('admin.participants.show', [$this->webinar, $this->participant]))
            ->assertOk()
            ->assertSee('Active eligibility override')
            ->assertSee($this->administrator->name)
            ->assertSee($override->created_at->format('M j, Y g:i A'))
            ->assertSee('Off-platform attendance was verified.')
            ->assertSee($route, false)
            ->assertSee('Remove override');

        $this->delete($route)->assertRedirect(route('admin.password.confirm'));
        $this->assertDatabaseHas('eligibility_overrides', ['id' => $override->id]);

        $this->post(route('admin.password.confirm.store'), ['password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
        $this->delete($route)
            ->assertRedirect()
            ->assertSessionHas('success', 'Eligibility override removed. Eligibility was recalculated.');

        $this->assertDatabaseMissing('eligibility_overrides', ['id' => $override->id]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'eligibility.override_removed',
            'user_id' => $this->administrator->id,
            'auditable_type' => EligibilityOverride::class,
            'auditable_id' => $override->id,
        ]);
        $this->assertFalse(app(EligibilityService::class)->evaluate($this->participant->fresh())['eligible']);
    }

    public function test_an_override_requires_a_substantive_reason(): void
    {
        $this->actingAs($this->administrator)
            ->post(route('admin.participants.override', [$this->webinar, $this->participant]), [
                'decision' => 'eligible', 'reason' => 'ok',
            ])->assertSessionHasErrors('reason');

        $this->assertDatabaseCount('eligibility_overrides', 0);
    }
}
