<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

class RecordOperationsHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public string $worker) {}

    public function handle(): void
    {
        $ttl = max(10, (int) config('operations.service_heartbeat_max_age_minutes') * 3);

        Cache::put(
            'operations:heartbeat:queue:'.$this->worker,
            now()->utc()->toIso8601String(),
            now()->addMinutes($ttl),
        );
    }
}
