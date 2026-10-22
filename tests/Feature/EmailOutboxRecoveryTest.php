<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
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

    /**
     * A provider that refuses the message must leave a trace on the delivery.
     *
     * The failure used to roll back with the transaction, so the row still
     * looked undispatched: the relay re-queued it every minute, resetting the
     * attempt budget, and the delivery never settled on "failed" - it sat in
     * "pending" for ever while the administrator was told nothing.
     */
    public function test_a_provider_failure_is_recorded_on_the_delivery_and_stops_the_relay_re_queueing_it(): void
    {
        $webinar = Webinar::factory()->create();
        $participant = Participant::query()->create([
            'webinar_id' => $webinar->id,
            'email' => 'participant@example.com',
        ]);
        $delivery = EmailDelivery::query()->create([
            'webinar_id' => $webinar->id,
            'participant_id' => $participant->id,
            'type' => 'certificate',
            'provider' => 'test',
            'recipient_email' => 'participant@example.com',
            'subject' => 'Your certificate',
            'payload' => ['html' => '<p>Your certificate is attached.</p>', 'attachments' => []],
            'status' => 'pending',
            'scheduled_at' => now()->subMinutes(10),
        ]);

        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->andThrow(new RuntimeException('Brevo request failed with HTTP 401 (unauthorized).'));

        try {
            (new SendTransactionalEmail($delivery->id))->handle($mailer);
            $this->fail('The provider failure should have been rethrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('401', $exception->getMessage());
        }

        $delivery->refresh();
        $this->assertSame(1, (int) $delivery->attempts);
        $this->assertStringContainsString('401', (string) $delivery->last_error);
        // Still pending: the job owns the retry, and the claim that stops a
        // second copy of the same certificate from being sent stays held.
        $this->assertSame('pending', $delivery->status);

        // The relay must leave an attempted delivery alone.
        Queue::fake();
        $this->artisan('security:relay-email-outbox')->assertSuccessful();
        Queue::assertNothingPushed();
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
