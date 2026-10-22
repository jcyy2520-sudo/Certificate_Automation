<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use Illuminate\Contracts\Queue\Queue as QueueContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CapacityReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'queue.default' => 'redis',
            'queue.connections.redis.queue' => 'certificates',
            'webinar.email.queue_connection' => 'redis-emails',
            'webinar.email.queue' => 'participant-mail',
            'accounts.email_queue' => 'account-mail',
        ]);
    }

    public function test_capacity_reports_each_configured_backend_and_labels_storage_as_configuration_only(): void
    {
        $this->mockQueues(17, 3, 2);

        $this->assertSame(0, Artisan::call('ops:capacity'));
        $output = Artisan::output();
        $this->assertStringContainsString('redis/certificates: 17', $output);
        $this->assertStringContainsString('redis-emails/participant-mail: 3', $output);
        $this->assertStringContainsString('redis-emails/account-mail: 2', $output);
        $this->assertStringContainsString('(configuration only; storage access not checked)', $output);
        $this->assertStringNotContainsString('(empty)', $output);
    }

    public function test_queue_backend_failure_is_visible_and_does_not_disclose_driver_credentials(): void
    {
        $this->mockQueues(new RuntimeException('redis://secret-password@example.test'), 0, 0);

        $this->assertSame(1, Artisan::call('ops:capacity'));
        $output = Artisan::output();
        $this->assertStringContainsString('redis/certificates: unavailable', $output);
        $this->assertStringNotContainsString('secret-password', $output);
        $this->assertStringContainsString('redis-emails/participant-mail: 0', $output);
    }

    public function test_a_missing_backend_metric_is_reported_as_unknown_instead_of_zero(): void
    {
        $this->mockQueues(null, 0, 0);

        $this->assertSame(1, Artisan::call('ops:capacity'));
        $output = Artisan::output();
        $this->assertStringContainsString('redis/certificates: unavailable', $output);
        $this->assertStringNotContainsString('redis/certificates: 0', $output);
    }

    public function test_uncertain_encrypted_delivery_errors_are_counted_across_multiple_pages(): void
    {
        $this->mockQueues(0, 0, 0);
        for ($i = 0; $i < 201; $i++) {
            EmailDelivery::query()->create([
                'type' => 'synthetic', 'status' => 'failed', 'failed_at' => now(),
                'last_error' => 'Provider outcome unknown: synthetic transport interruption',
            ]);
        }
        EmailDelivery::query()->create(['type' => 'synthetic', 'status' => 'failed', 'last_error' => 'Ordinary failure']);

        $this->assertSame(1, Artisan::call('ops:capacity'));
        $this->assertStringContainsString('Uncertain provider outcomes needing reconciliation: 201', Artisan::output());
    }

    private function mockQueues(mixed $defaultDepth, int $emailDepth, int $accountDepth): void
    {
        $default = Mockery::mock(QueueContract::class);
        $defaultExpectation = $default->shouldReceive('size')->once()->with('certificates');
        if ($defaultDepth instanceof RuntimeException) {
            $defaultExpectation->andThrow($defaultDepth);
        } else {
            $defaultExpectation->andReturn($defaultDepth);
        }
        $emails = Mockery::mock(QueueContract::class);
        $emails->shouldReceive('size')->once()->with('participant-mail')->andReturn($emailDepth);
        $emails->shouldReceive('size')->once()->with('account-mail')->andReturn($accountDepth);
        Queue::shouldReceive('connection')->once()->with('redis')->andReturn($default);
        Queue::shouldReceive('connection')->twice()->with('redis-emails')->andReturn($emails);
    }
}
