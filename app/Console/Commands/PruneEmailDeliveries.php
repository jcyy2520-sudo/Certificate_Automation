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
        $days = $this->option('days') === null
            ? filter_var(config('security.email_delivery_retention_days', 30), FILTER_VALIDATE_INT)
            : filter_var($this->option('days'), FILTER_VALIDATE_INT);

        if (! is_int($days) || $days < 1 || $days > 365) {
            $this->error('Email delivery retention must be between 1 and 365 days.');

            return self::INVALID;
        }

        $query = EmailDelivery::query()
            ->whereIn('status', ['sent', 'failed', 'cancelled'])
            ->where('updated_at', '<=', now()->subDays($days));
        $count = (clone $query)->count();

        if (! $this->option('dry-run')) {
            $query->delete();
        }

        $verb = $this->option('dry-run') ? 'would be deleted' : 'deleted';
        $this->info("{$count} terminal email delivery record(s) {$verb}; retention is {$days} day(s).");

        return self::SUCCESS;
    }
}
