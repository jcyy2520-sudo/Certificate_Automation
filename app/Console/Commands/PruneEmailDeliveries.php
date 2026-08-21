<?php

namespace App\Console\Commands;

use App\Models\EmailDelivery;
use Illuminate\Console\Command;

class PruneEmailDeliveries extends Command
{
    protected $signature = 'security:prune-email-deliveries
                            {--days= : Override the configured terminal-delivery retention}
                            {--dry-run : Report without deleting}';

    protected $description = 'Delete terminal transactional-email records after their bounded retention period';

    public function handle(): int
    {
        $days = max(1, min(365, (int) ($this->option('days') ?: config('security.email_delivery_retention_days', 30))));
        $query = EmailDelivery::query()
            ->whereIn('status', ['sent', 'failed', 'cancelled'])
            ->where('updated_at', '<=', now()->subDays($days));
        $count = (clone $query)->count();

        if (! $this->option('dry-run')) {
            $query->delete();
        }

        $verb = $this->option('dry-run') ? 'would be deleted' : 'deleted';
        $this->info("{$count} terminal email delivery record(s) {$verb}.");

        return self::SUCCESS;
    }
}
