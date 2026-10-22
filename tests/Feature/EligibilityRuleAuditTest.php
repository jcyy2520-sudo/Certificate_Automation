<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\User;
use App\Models\Webinar;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class EligibilityRuleAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_rule_changes_record_the_actor_and_exact_old_and_new_requirements(): void
    {
        $admin = User::factory()->create();
        $webinar = Webinar::factory()->create(['created_by' => $admin->id]);
        $webinar->eligibilityRules()->create(['requirement' => 'posttest', 'is_required' => true, 'minimum_score' => 5]);
        $this->actingAs($admin)->put(route('admin.certification.rules', $webinar), [
            'rules' => ['posttest' => ['enabled' => 1, 'minimum_score' => 8]],
        ])->assertRedirect();
        $audit = AuditLog::query()->where('action', 'eligibility_rules.updated')->sole();
        $this->assertSame($admin->id, $audit->actor_user_id);
        $this->assertSame($admin->email, $audit->actor_email);
        $this->assertEquals(['posttest' => ['is_required' => true, 'minimum_score' => 5]], $audit->metadata['before']);
        $this->assertEquals(['posttest' => ['is_required' => true, 'minimum_score' => 8]], $audit->metadata['after']);
    }

    public function test_an_audit_failure_rolls_back_the_rule_change_instead_of_leaving_an_unattributed_edit(): void
    {
        $admin = User::factory()->create();
        $webinar = Webinar::factory()->create(['created_by' => $admin->id]);
        $rule = $webinar->eligibilityRules()->create(['requirement' => 'posttest', 'is_required' => true, 'minimum_score' => 5]);
        $this->mock(AuditService::class)->shouldReceive('record')->atLeast()->once()->andThrow(new RuntimeException('Synthetic audit failure'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($admin)->put(route('admin.certification.rules', $webinar), [
                'rules' => ['posttest' => ['enabled' => 1, 'minimum_score' => 8]],
            ]);
            $this->fail('Audit failure must abort the operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure', $exception->getMessage());
        }
        $this->assertEquals(5, $rule->fresh()->minimum_score);
        $this->assertDatabaseCount('audit_logs', 0);
    }
}
