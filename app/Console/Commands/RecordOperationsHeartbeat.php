<?php

namespace App\Console\Commands;

use App\Jobs\RecordOperationsHeartbeat as RecordOperationsHeartbeatJob;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RecordOperationsHeartbeat extends Command
{
    protected $signature = 'operations:heartbeat';

    protected $description = 'Record scheduler health and probe every required queue worker';

    public function handle(): int
    {
        $ttl = max(10, (int) config('operations.service_heartbeat_max_age_minutes') * 3);

        Cache::put(
            'operations:heartbeat:scheduler',
            now()->utc()->toIso8601String(),
            now()->addMinutes($ttl),
        );

        $defaultConnection = (string) config('queue.default');
        $defaultQueue = (string) config("queue.connections.{$defaultConnection}.queue", 'default');

        RecordOperationsHeartbeatJob::dispatch('default')
            ->onConnection($defaultConnection)
            ->onQueue($defaultQueue);

        RecordOperationsHeartbeatJob::dispatch('emails')
            ->onConnection((string) config('webinar.email.queue_connection'))
            ->onQueue((string) config('webinar.email.queue'));

        return self::SUCCESS;
    }
}
