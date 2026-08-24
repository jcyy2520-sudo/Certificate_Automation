<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class EmailOutboxRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_relay_recovers_a_committed_pending_delivery(): void
    {
        Queue::fake();
        $delivery = $this->pendingDelivery([
            'scheduled_at' => now()->subMinutes(10),
        ]);

        $this->artisan('security:relay-email-outbox', ['--stale-seconds' => 300])
            ->expectsOutput('1 delivery record(s) dispatched; 0 dispatch failure(s).')
            ->assertSuccessful();

        Queue::assertPushed(
            SendTransactionalEmail::class,
            fn (SendTransactionalEmail $job): bool => $job->deliveryId === $delivery->id,
        );
        $this->assertNotNull($delivery->fresh()->processing_at);
    }

    public function test_the_relay_uses_a_lease_and_only_reclaims_stale_pending_rows(): void
    {
        Queue::fake();
        $freshLease = $this->pendingDelivery([
            'scheduled_at' => now()->subMinutes(10),
            'processing_at' => now()->subMinute(),
        ]);
        $staleLease = $this->pendingDelivery([
            'scheduled_at' => now()->subMinutes(10),
            'processing_at' => now()->subMinutes(10),
        ]);

        $this->artisan('security:relay-email-outbox', ['--stale-seconds' => 300])
            ->assertSuccessful();

        Queue::assertNotPushed(
            SendTransactionalEmail::class,
            fn (SendTransactionalEmail $job): bool => $job->deliveryId === $freshLease->id,
        );
        Queue::assertPushed(
            SendTransactionalEmail::class,
            fn (SendTransactionalEmail $job): bool => $job->deliveryId === $staleLease->id,
        );
    }

    public function test_the_relay_skips_terminal_future_expired_and_scrubbed_rows(): void
    {
        Queue::fake();
        $this->pendingDelivery(['status' => 'sent', 'sent_at' => now()]);
        $this->pendingDelivery(['scheduled_at' => now()->addMinute()]);
        $this->pendingDelivery(['expires_at' => now()->subMinute()]);
        $this->pendingDelivery(['recipient_email' => null, 'payload' => null]);

        $this->artisan('security:relay-email-outbox')->assertSuccessful();

        Queue::assertNothingPushed();
    }

    public function test_the_relay_rejects_unsafe_bounds_and_supports_dry_run(): void
    {
        Queue::fake();
        $this->pendingDelivery(['scheduled_at' => now()->subMinutes(10)]);

        $this->artisan('security:relay-email-outbox', ['--limit' => '0'])->assertFailed();
        $this->artisan('security:relay-email-outbox', ['--stale-seconds' => 'not-a-number'])->assertFailed();
        $this->artisan('security:relay-email-outbox', ['--dry-run' => true])
            ->expectsOutput('1 pending delivery record(s) would be dispatched.')
            ->assertSuccessful();

        Queue::assertNothingPushed();
        $this->assertNull(EmailDelivery::query()->firstOrFail()->processing_at);
    }

    private function pendingDelivery(array $overrides = []): EmailDelivery
    {
        return EmailDelivery::query()->create([
            'type' => 'participant_form_access',
            'provider' => 'log',
            'recipient_email' => 'participant@example.com',
            'subject' => 'Secure access',
            'payload' => ['html' => '<p>Access</p>', 'attachments' => []],
            'status' => 'pending',
            'max_attempts' => 3,
            'scheduled_at' => now()->subMinutes(10),
            ...$overrides,
        ]);
    }
}
