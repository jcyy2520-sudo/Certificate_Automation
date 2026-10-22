<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Email\BrevoTransactionalMailer;
use App\Services\Email\PermanentDeliveryException;
use App\Services\EmailOutboxRelay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AccountEmailReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function setupInvitation(): EmailDelivery
    {
        $user = User::factory()->create(['role' => 'user', 'password_setup_at' => null]);
        app(AccountService::class)->invite($user, true);

        return EmailDelivery::query()->where('account_user_id', $user->id)->firstOrFail();
    }

    public function test_account_email_is_recipient_bound_idempotent_and_only_reports_provider_acceptance(): void
    {
        $delivery = $this->setupInvitation();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->with($delivery->recipient_email, $delivery->subject, $delivery->payload['html'], [], $delivery->idempotency_key)->andReturn('synthetic-provider-id');
        $job = new SendTransactionalEmail($delivery->id);
        $job->handle($mailer);
        $job->handle($mailer);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->assertNull($delivery->fresh()->payload);
        $this->assertSame('Captured locally', $delivery->fresh()->statusLabel());
        $delivery->update(['provider' => 'brevo']);
        $this->assertSame('Provider accepted', $delivery->fresh()->statusLabel());
    }

    public function test_permanent_failure_preserves_approval_and_can_be_retried_with_a_new_invitation(): void
    {
        $delivery = $this->setupInvitation();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new PermanentDeliveryException('Provider sender configuration needs attention.'));
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $user = User::query()->findOrFail($delivery->account_user_id);
        $this->assertSame('approved', $user->approval_status);
        $this->assertNull($user->password_setup_at);
        $this->travel(3)->minutes();
        app(AccountService::class)->invite($user);
        $this->assertDatabaseCount('email_deliveries', 2);
        $this->assertSame('pending', EmailDelivery::query()->latest('id')->first()->status);
    }

    public function test_stale_or_suspended_account_emails_are_cancelled_before_transport(): void
    {
        $delivery = $this->setupInvitation();
        User::query()->findOrFail($delivery->account_user_id)->forceFill(['approval_status' => 'suspended', 'is_active' => false])->save();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldNotReceive('send');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('cancelled', $delivery->fresh()->status);
    }

    public function test_provider_429_releases_work_without_sleeping_and_resumes_with_the_same_idempotency_key(): void
    {
        $delivery = $this->setupInvitation();
        config(['services.brevo.key' => 'synthetic-key', 'services.brevo.base_url' => 'https://api.brevo.com/v3']);
        Http::fakeSequence()->push(['code' => 'too_many_requests'], 429, ['Retry-After' => '30'])->push(['messageId' => 'synthetic-accepted'], 201);
        $job = new SendTransactionalEmail($delivery->id);
        $job->handle(app(BrevoTransactionalMailer::class));
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
        $this->travel(31)->seconds();
        $job->handle(app(BrevoTransactionalMailer::class));
        $this->assertSame('sent', $delivery->fresh()->status);
        Http::assertSentCount(2);
        Http::assertSent(fn ($request) => $request['headers']['idempotencyKey'] === $delivery->idempotency_key);
    }

    public function test_minute_budget_defers_unattempted_messages_without_losing_them(): void
    {
        config(['accounts.email_per_minute' => 1]);
        $first = $this->setupInvitation();
        $second = $this->setupInvitation();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->twice()->andReturn('synthetic-accepted');
        (new SendTransactionalEmail($first->id))->handle($mailer);
        $job = new SendTransactionalEmail($second->id);
        $job->handle($mailer);
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertSame(0, $second->fresh()->attempts);
        $this->travel(61)->seconds();
        $job->handle($mailer);
        $this->assertSame('sent', $second->fresh()->status);
        $this->assertSame(1, $second->fresh()->attempts);
    }

    public function test_unknown_outcomes_are_not_retried_past_the_provider_idempotency_window(): void
    {
        $delivery = $this->setupInvitation();
        $delivery->update(['attempts' => 1, 'first_attempted_at' => now()->subMinutes(26)]);
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldNotReceive('send');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringContainsString('Provider outcome unknown', $delivery->fresh()->last_error);
    }

    public function test_the_outbox_relay_recovers_account_mail_on_the_priority_queue(): void
    {
        $delivery = $this->setupInvitation();
        Queue::fake();
        app(EmailOutboxRelay::class)->relay(10, 120);
        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->deliveryId === $delivery->id && $job->queue === 'account-emails');
    }
}
