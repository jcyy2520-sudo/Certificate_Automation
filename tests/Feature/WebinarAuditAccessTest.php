<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarAuditAccessTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $admin = User::factory()->create();
        $manager = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $unrelated = User::factory()->create(['name' => 'Unrelated private actor', 'role' => User::USER_ROLE]);
        $a = Webinar::factory()->create(['created_by' => $admin->id]);
        $b = Webinar::factory()->create(['created_by' => $admin->id]);
        $manager->assignedWebinars()->attach($a->id, ['assigned_by' => $admin->id, 'manage_participants' => true, 'manage_certificates' => false, 'send_emails' => false]);
        foreach ([[$a, $admin, 'webinar_a.corrected'], [$b, $unrelated, 'webinar_b.private'], [null, $unrelated, 'account.private']] as [$webinar, $actor, $action]) {
            AuditLog::query()->create(['webinar_id' => $webinar?->id, 'action' => $action, 'actor_user_id' => $actor->id,
                'user_id' => $actor->id, 'actor_name' => $actor->name, 'actor_email' => $actor->email,
                'ip_address_hash' => 'synthetic-private-network-fingerprint']);
        }

        return [$admin, $manager, $a, $b];
    }

    public function test_assigned_manager_sees_only_that_webinars_history_and_actor_options(): void
    {
        [$admin, $manager, $a, $b] = $this->fixture();
        $this->actingAs($manager)->get(route('admin.webinars.audit', $a))->assertOk()
            ->assertSee('webinar_a.corrected')->assertDontSee('webinar_b.private')->assertDontSee('account.private')
            ->assertDontSee('Unrelated private actor')->assertDontSee($admin->email)
            ->assertDontSee('synthetic-private-network-fingerprint')->assertDontSee('All system activity');
        $this->get(route('admin.webinars.audit', $b))->assertNotFound();
        $this->get(route('admin.audit.index'))->assertForbidden();
    }

    public function test_query_filter_and_pagination_cannot_escape_the_webinar_scope(): void
    {
        [, $manager, $a, $b] = $this->fixture();
        $this->actingAs($manager)->get(route('admin.webinars.audit', [$a, 'webinar' => $b->id]))->assertNotFound();
        $this->get(route('admin.webinars.audit', [$a, 'action' => 'private', 'page' => 2]))
            ->assertOk()->assertDontSee('webinar_b.private')->assertDontSee('account.private');
    }

    public function test_administrator_has_complete_history_and_each_webinar_has_its_own_view(): void
    {
        [$admin, , $a, $b] = $this->fixture();
        $this->actingAs($admin)->get(route('admin.audit.index'))->assertOk()
            ->assertSee('webinar_a.corrected')->assertSee('webinar_b.private')->assertSee('account.private');
        $this->get(route('admin.webinars.audit', $a))->assertOk()->assertSee('webinar_a.corrected')
            ->assertDontSee('webinar_b.private')->assertDontSee('account.private')->assertSee('All system activity');
        $this->get(route('admin.webinars.audit', $b))->assertOk()->assertSee('webinar_b.private')->assertDontSee('webinar_a.corrected');
    }

    public function test_regular_account_and_revoked_assignment_do_not_gain_an_audit_route(): void
    {
        [, $manager, $a] = $this->fixture();
        $regular = User::factory()->create(['role' => User::USER_ROLE]);
        $a->update(['created_by' => $regular->id]);
        $this->actingAs($regular)->get(route('admin.webinars.audit', $a))->assertForbidden();
        $manager->assignedWebinars()->detach($a->id);
        $this->actingAs($manager)->get(route('admin.webinars.audit', $a))->assertNotFound();
    }
}
