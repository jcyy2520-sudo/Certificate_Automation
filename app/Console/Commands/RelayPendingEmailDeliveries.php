<?php

namespace App\Console\Commands;

use App\Services\EmailOutboxRelay;
use Illuminate\Console\Command;

final class RelayPendingEmailDeliveries extends Command
{
    protected $signature = 'security:relay-email-outbox
                            {--limit=100 : Maximum deliveries to claim in one run}
                            {--stale-seconds=300 : Reclaim a pending relay lease after this many seconds}
                            {--dry-run : Count eligible deliveries without dispatching them}';

    protected $description = 'Recover committed transactional email deliveries that were not dispatched';

    public function handle(EmailOutboxRelay $relay): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT);
        $staleSeconds = filter_var($this->option('stale-seconds'), FILTER_VALIDATE_INT);

        if ($limit === false || $limit < 1 || $limit > 1000) {
            $this->error('--limit must be an integer between 1 and 1000.');

            return self::FAILURE;
        }

        if ($staleSeconds === false || $staleSeconds < 60 || $staleSeconds > 3600) {
            $this->error('--stale-seconds must be an integer between 60 and 3600.');

            return self::FAILURE;
        }

        $result = $relay->relay($limit, $staleSeconds, (bool) $this->option('dry-run'));

        if ($this->option('dry-run')) {
            $this->info("{$result['eligible']} pending delivery record(s) would be dispatched.");

            return self::SUCCESS;
        }

        $this->info("{$result['dispatched']} delivery record(s) dispatched; {$result['failed']} dispatch failure(s).");

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
