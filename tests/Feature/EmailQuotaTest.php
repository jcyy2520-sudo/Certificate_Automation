<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\Email\EmailSendBudget;
use App\Services\EmailOutboxRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class EmailQuotaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        config(['webinar.email.provider' => 'brevo', 'accounts.email_per_day' => 1,
            'accounts.email_per_minute' => 100, 'accounts.verify_provider_quota' => false,
            'services.brevo.key' => 'synthetic-key', 'services.brevo.base_url' => 'https://api.brevo.com/v3']);
    }

    private function delivery(string $provider = 'brevo'): EmailDelivery
    {
        $user = User::factory()->create(['password_setup_at' => null]);

        return EmailDelivery::query()->create([
            'type' => 'account_setup', 'account_user_id' => $user->id, 'provider' => $provider,
            'recipient_email' => $user->email, 'subject' => 'Synthetic invitation', 'status' => 'pending',
            'payload' => ['html' => 'Synthetic message', 'account_version' => $user->auth_version],
            'expires_at' => now()->addDays(3),
        ]);
    }

    private function mailer(int $calls): TransactionalMailer
    {
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->times($calls)->andReturn('synthetic-accepted');

        return $mailer;
    }

    public function test_excess_work_remains_unattempted_and_resumes_through_the_outbox_on_the_next_day(): void
    {
        $this->travelTo(now('UTC')->setTime(10, 0));
        $first = $this->delivery();
        $second = $this->delivery();
        $mailer = $this->mailer(2);
        (new SendTransactionalEmail($first->id))->handle($mailer);
        (new SendTransactionalEmail($second->id))->handle($mailer);
        $this->assertSame('sent', $first->fresh()->status);
        $second->refresh();
        $this->assertSame('pending', $second->status);
        $this->assertSame(0, $second->attempts);
        $this->assertNull($second->first_attempted_at);
        $this->assertSame('Queued for later', $second->statusLabel());
        $this->assertTrue($second->scheduled_at->isFuture());
        (new SendTransactionalEmail($second->id))->handle($mailer);
        Queue::fake();
        $this->assertSame(0, app(EmailOutboxRelay::class)->relay(10, 120)['dispatched']);
        $this->travelTo($second->scheduled_at->copy()->addSecond());
        $this->assertSame(1, app(EmailOutboxRelay::class)->relay(10, 120)['dispatched']);
        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->deliveryId === $second->id && $job->retryDeadline > now()->timestamp);
        (new SendTransactionalEmail($second->id))->handle($mailer);
        $this->assertSame('sent', $second->fresh()->status);
        $this->assertDatabaseCount('email_deliveries', 2);
        $this->assertDatabaseCount('email_send_budgets', 2);
        $this->travelBack();
    }

    public function test_retry_and_cache_flush_cannot_spend_a_second_daily_reservation(): void
    {
        $delivery = $this->delivery();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new RuntimeException('Synthetic transient failure'));
        try {
            (new SendTransactionalEmail($delivery->id))->handle($mailer);
            $this->fail('A transient failure must propagate to the queue.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic transient failure', $exception->getMessage());
        }
        Cache::flush();
        (new SendTransactionalEmail($delivery->id))->handle($this->mailer(1));
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertSame(1, (int) DB::table('email_send_budgets')->sum('reserved'));
        (new SendTransactionalEmail($this->delivery()->id))->handle($this->mailer(0));
        $this->assertSame(1, app(EmailSendBudget::class)->summary()['reserved']);
    }

    public function test_local_capture_and_cancelled_account_do_not_consume_live_daily_capacity(): void
    {
        config(['webinar.email.provider' => 'log']);
        $log = $this->delivery('log');
        (new SendTransactionalEmail($log->id))->handle($this->mailer(1));
        $cancelled = $this->delivery('log');
        User::findOrFail($cancelled->account_user_id)->update(['is_active' => false, 'approval_status' => 'suspended']);
        (new SendTransactionalEmail($cancelled->id))->handle($this->mailer(0));
        $this->assertSame('cancelled', $cancelled->fresh()->status);
        $this->assertDatabaseCount('email_send_budgets', 0);
    }

    public function test_existing_attempts_before_the_new_ledger_are_included_conservatively(): void
    {
        $old = $this->delivery();
        $old->update(['attempts' => 1, 'first_attempted_at' => now(), 'status' => 'failed']);
        $new = $this->delivery();
        (new SendTransactionalEmail($new->id))->handle($this->mailer(0));
        $this->assertSame(0, $new->fresh()->attempts);
        $this->assertSame(1, app(EmailSendBudget::class)->summary()['reserved']);
    }

    public function test_provider_credit_exhaustion_defers_without_a_post_or_a_false_failure(): void
    {
        config(['accounts.verify_provider_quota' => true]);
        Http::fake(['https://api.brevo.com/v3/account' => Http::response(['plan' => [['type' => 'free', 'creditsType' => 'sendLimit', 'credits' => 0]]])]);
        $delivery = $this->delivery();
        (new SendTransactionalEmail($delivery->id))->handle($this->mailer(0));
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempts);
        $this->assertNull($delivery->fresh()->budget_reserved_on);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
    }

    public function test_legacy_acceptances_without_an_attempt_marker_still_consume_the_daily_allowance(): void
    {
        $old = $this->delivery();
        $old->update(['status' => 'sent', 'sent_at' => now(), 'first_attempted_at' => null]);
        $new = $this->delivery();
        (new SendTransactionalEmail($new->id))->handle($this->mailer(0));
        $this->assertSame(0, $new->fresh()->attempts);
        $this->assertSame(1, app(EmailSendBudget::class)->summary()['reserved']);
        $this->assertSame(1, app(EmailSendBudget::class)->summary()['accepted']);
    }

    public function test_quota_checks_fail_closed_on_unavailable_or_unrecognized_provider_plans(): void
    {
        config(['accounts.verify_provider_quota' => true]);
        Http::fakeSequence()->push([], 503)->push(['plan' => [['type' => 'sms', 'credits' => 500]]]);
        foreach ([1, 2] as $unused) {
            $delivery = $this->delivery();
            (new SendTransactionalEmail($delivery->id))->handle($this->mailer(0));
            $this->assertSame(0, $delivery->fresh()->attempts);
            $this->assertStringContainsString('Could not verify', $delivery->fresh()->last_error);
        }
        Http::assertSentCount(2);
    }

    public function test_successful_provider_check_records_credits_and_retries_do_not_recheck_or_recharge(): void
    {
        config(['accounts.verify_provider_quota' => true]);
        Http::fake(['https://api.brevo.com/v3/account' => Http::response(['plan' => [['type' => 'free', 'creditsType' => 'sendLimit', 'credits' => 300]]])]);
        $delivery = $this->delivery();
        $job = new SendTransactionalEmail($delivery->id);
        $mailer = $this->mailer(1);
        $job->handle($mailer);
        $job->handle($mailer);
        $this->assertSame(300, app(EmailSendBudget::class)->summary()['provider_remaining']);
        $this->assertSame(1, app(EmailSendBudget::class)->summary()['accepted']);
        Http::assertSentCount(1);
    }

    public function test_oversized_or_disabled_local_configuration_still_enforces_the_free_ceiling(): void
    {
        foreach ([0, -1, 301, 1000] as $limit) {
            config(['accounts.email_per_day' => $limit]);
            $this->assertSame(300, app(EmailSendBudget::class)->limit());
        }
    }
}
