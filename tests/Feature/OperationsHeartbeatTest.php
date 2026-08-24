<?php

namespace Tests\Feature;

use App\Jobs\RecordOperationsHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OperationsHeartbeatTest extends TestCase
{
    public function test_scheduler_heartbeat_probes_both_required_workers(): void
    {
        Queue::fake();
        Cache::forget('operations:heartbeat:scheduler');

        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'default',
            'webinar.email.queue_connection' => 'redis-emails',
            'webinar.email.queue' => 'emails',
        ]);

        $this->artisan('operations:heartbeat')->assertSuccessful();

        $this->assertNotNull(Cache::get('operations:heartbeat:scheduler'));
        Queue::assertPushed(RecordOperationsHeartbeat::class, fn (RecordOperationsHeartbeat $job): bool => $job->worker === 'default'
            && $job->connection === 'redis'
            && $job->queue === 'default');
        Queue::assertPushed(RecordOperationsHeartbeat::class, fn (RecordOperationsHeartbeat $job): bool => $job->worker === 'emails'
            && $job->connection === 'redis-emails'
            && $job->queue === 'emails');
    }

    public function test_queue_probe_records_a_worker_heartbeat(): void
    {
        Cache::forget('operations:heartbeat:queue:default');

        (new RecordOperationsHeartbeat('default'))->handle();

        $this->assertNotNull(Cache::get('operations:heartbeat:queue:default'));
    }
}
