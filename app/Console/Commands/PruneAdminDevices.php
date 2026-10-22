<?php

namespace App\Console\Commands;

use App\Models\AdminTrustedDevice;
use Illuminate\Console\Command;

class PruneAdminDevices extends Command
{
    protected $signature = 'security:prune-admin-devices';

    protected $description = 'Remove expired or revoked device digests after a 30-day audit grace period';

    public function handle(): int
    {
        $count = AdminTrustedDevice::query()->where('expires_at', '<', now()->subDays(30))
            ->orWhere('revoked_at', '<', now()->subDays(30))->delete();
        $this->info("Pruned {$count} expired device records.");

        return self::SUCCESS;
    }
}
