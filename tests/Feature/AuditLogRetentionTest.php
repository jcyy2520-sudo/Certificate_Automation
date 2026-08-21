<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_audit_records_are_pruned_but_recent_events_remain(): void
    {
        AuditLog::query()->create(['action' => 'old.event', 'created_at' => now()->subDays(366)]);
        AuditLog::query()->create(['action' => 'recent.event', 'created_at' => now()->subDays(10)]);

        $this->artisan('security:prune-audit-logs', ['--days' => 365])->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['action' => 'old.event']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'recent.event']);
    }

    public function test_dry_run_reports_without_deleting_and_invalid_policy_is_rejected(): void
    {
        AuditLog::query()->create(['action' => 'old.event', 'created_at' => now()->subDays(40)]);

        $this->artisan('security:prune-audit-logs', ['--days' => 30, '--dry-run' => true])
            ->expectsOutputToContain('1 audit log record(s) would be pruned')
            ->assertSuccessful();

        $this->assertDatabaseHas('audit_logs', ['action' => 'old.event']);
        $this->artisan('security:prune-audit-logs', ['--days' => 0])->assertFailed();
    }
}
