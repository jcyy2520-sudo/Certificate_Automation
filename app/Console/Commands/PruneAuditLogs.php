<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use Illuminate\Console\Command;

class PruneAuditLogs extends Command
{
    protected $signature = 'security:prune-audit-logs {--days= : Override the configured retention period} {--dry-run}';

    protected $description = 'Delete expired security audit records according to the configured retention policy';

    public function handle(): int
    {
        $days = $this->option('days') === null
            ? (int) config('security.audit_log_retention_days', 365)
            : filter_var($this->option('days'), FILTER_VALIDATE_INT);

        if (! is_int($days) || $days < 1 || $days > 3650) {
            $this->error('Audit retention must be between 1 and 3650 days.');

            return self::INVALID;
        }

        $expired = AuditLog::query()->where('created_at', '<', now()->subDays($days));
        $count = (clone $expired)->count();

        if (! $this->option('dry-run')) {
            $expired->delete();
        }

        $verb = $this->option('dry-run') ? 'would be pruned' : 'pruned';
        $this->info("{$count} audit log record(s) {$verb}; retention is {$days} day(s).");

        return self::SUCCESS;
    }
}
