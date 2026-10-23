<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\AccountService;
use App\Services\Email\LogTransactionalMailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AccountActivationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    private function invitation(): array
    {
        $user = app(AccountService::class)->request('Synthetic requester', 'requester@example.test');
        $admin = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.update', $user), ['action' => 'approve'])
            ->assertSessionHasNoErrors();

        return [$user->fresh(), EmailDelivery::query()->where('account_user_id', $user->id)->sole()];
    }

    private function link(EmailDelivery $delivery): array
    {
        preg_match('/href="([^"]+)"/', $delivery->payload['html'], $match);
        parse_str(parse_url(html_entity_decode($match[1]), PHP_URL_FRAGMENT), $values);

        return $values;
    }

    public function test_approved_request_can_activate_after_transport_acceptance_without_an_existing_password(): void
    {
        config(['accounts.email_provider' => 'brevo', 'services.brevo.key' => 'synthetic-key',
            'services.brevo.from_address' => 'sender@example.test', 'accounts.verify_provider_quota' => false]);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'synthetic-activation-id'], 201)]);
        $this->post(route('account.store'), ['name' => 'Synthetic requester', 'email' => ' REQUESTER@example.test '])
            ->assertRedirect(route('account.status'));
        $user = User::query()->where('email', 'requester@example.test')->sole();
        $this->assertSame('pending_approval', $user->approval_status);
        $this->assertFalse($user->canAccessPlatform());
        $this->assertDatabaseCount('email_deliveries', 0);

        $admin = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.update', $user), ['action' => 'approve'])->assertSessionHasNoErrors();
        $delivery = EmailDelivery::query()->where('account_user_id', $user->id)->sole();
        $link = $this->link($delivery);
        $this->assertSame('account_setup', $delivery->type);
        $this->assertSame('requester@example.test', $delivery->recipient_email);
        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->deliveryId === $delivery->id
            && $job->connection === 'database-emails' && $job->queue === 'account-emails');

        $job = new SendTransactionalEmail($delivery->id);
        $job->handle(app(LogTransactionalMailer::class));
        $job->handle(app(LogTransactionalMailer::class));
        Http::assertSentCount(1);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame('synthetic-activation-id', $delivery->fresh()->provider_message_id);
        $this->assertNull($delivery->fresh()->payload);
        $this->assertFalse($user->fresh()->canAccessPlatform());

        auth()->logout();
        $this->get(route('password.reset', ['setup' => 1]))->assertOk()->assertSee('Set your password');
        $this->assertTrue(Password::tokenExists($user, $link['token']));
        $password = 'My synthetic activation passphrase';
        $this->post(route('password.update'), $link + ['password' => $password, 'password_confirmation' => $password])
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->canAccessPlatform());
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNotNull($user->fresh()->password_setup_at);
        $this->assertTrue(Hash::check($password, $user->fresh()->password));
        $this->assertFalse(Password::tokenExists($user, $link['token']));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => $password])->assertRedirect(route('admin.dashboard'));
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.activated', 'actor_user_id' => $user->id]);
    }

    public static function deliveryStates(): array
    {
        return [
            'provider refused after acceptance' => [['status' => 'sent', 'provider' => 'brevo', 'delivery_outcome' => 'hard_bounce'], 'Delivery failed'],
            'retry after an attempt' => [['status' => 'pending', 'attempts' => 1], 'Retry pending'],
            'future budget deferral' => [['status' => 'pending', 'attempts' => 0, 'scheduled_at' => '+5 minutes'], 'Queued for later'],
        ];
    }

    #[DataProvider('deliveryStates')]
    public function test_account_list_shows_the_actual_delivery_outcome(array $state, string $label): void
    {
        [$user, $delivery] = $this->invitation();
        if (isset($state['scheduled_at'])) {
            $state['scheduled_at'] = now()->addMinutes(5);
        }
        $delivery->update($state);
        $this->get(route('admin.users.index', ['status' => 'invited']))->assertOk()->assertSee($label);
        Http::assertNothingSent();
    }

    public function test_an_unprocessed_activation_is_visibly_distinct_from_a_sent_email(): void
    {
        [$user, $delivery] = $this->invitation();
        $this->travel(3)->minutes();
        $this->get(route('admin.users.index', ['status' => 'invited']))->assertOk()
            ->assertSee('Activation email has not been attempted')
            ->assertSee('account-emails')
            ->assertSee('Resend Invitation');
        $this->assertSame(0, $delivery->fresh()->attempts);
        $this->assertNull($delivery->fresh()->sent_at);
        Http::assertNothingSent();
    }

    public function test_an_approved_account_without_a_setup_delivery_shows_the_recovery_action(): void
    {
        $admin = User::factory()->create();
        User::factory()->create(['role' => User::USER_ROLE, 'is_active' => false, 'password_setup_at' => null]);
        $this->actingAs($admin)->get(route('admin.users.index', ['status' => 'invited']))->assertOk()
            ->assertSee('Activation email has not been queued')
            ->assertSee('Resend Invitation');
        $this->assertDatabaseCount('email_deliveries', 0);
        Http::assertNothingSent();
    }

    public function test_a_quota_check_failure_can_expire_unsent_and_recover_only_with_a_fresh_setup_link(): void
    {
        config(['accounts.email_provider' => 'brevo', 'services.brevo.key' => 'synthetic-key',
            'services.brevo.from_address' => 'sender@example.test', 'accounts.verify_provider_quota' => true]);
        Http::fake([
            'https://api.brevo.com/v3/account' => Http::sequence()->push([], 503)
                ->push(['plan' => [['type' => 'free', 'creditsType' => 'sendLimit', 'credits' => 300]]]),
            'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'synthetic-recovered-activation'], 201),
        ]);
        [$user, $delivery] = $this->invitation();
        $oldLink = $this->link($delivery);
        (new SendTransactionalEmail($delivery->id))->handle(app(LogTransactionalMailer::class));
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempts);
        $this->assertNull($delivery->fresh()->first_attempted_at);
        $this->assertNull($delivery->fresh()->sent_at);
        $this->assertSame('Could not verify the free provider allowance; no email attempted.', $delivery->fresh()->last_error);
        Http::assertSent(fn ($request) => $request->method() === 'GET');
        Http::assertSentCount(1);

        $this->travel(61)->minutes();
        $this->get(route('admin.users.index', ['status' => 'invited']))->assertOk()
            ->assertSee('Expired before sending')->assertSee('0 attempts')
            ->assertSee('Invitation expired')->assertSee('Resend Invitation')
            ->assertDontSee('Email: Queued');
        $this->assertFalse($user->fresh()->canAccessPlatform());

        $this->post(route('admin.users.update', $user), ['action' => 'resend'])->assertSessionHasNoErrors();
        $replacement = EmailDelivery::query()->where('account_user_id', $user->id)->latest('id')->firstOrFail();
        $newLink = $this->link($replacement);
        $this->assertNotSame($oldLink['token'], $newLink['token']);
        $this->assertFalse(Password::tokenExists($user, $oldLink['token']));
        $this->assertTrue(Password::tokenExists($user, $newLink['token']));
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->payload);
        $this->assertDatabaseCount('users', 2);
        (new SendTransactionalEmail($replacement->id))->handle(app(LogTransactionalMailer::class));
        $this->assertSame('sent', $replacement->fresh()->status);
        $this->assertSame(1, $replacement->fresh()->attempts);
        $this->assertSame('synthetic-recovered-activation', $replacement->fresh()->provider_message_id);

        auth()->logout();
        $password = 'Recovered synthetic activation password';
        $this->post(route('password.update'), $oldLink + ['password' => $password, 'password_confirmation' => $password])
            ->assertSessionHasErrors('email');
        $this->assertFalse($user->fresh()->canAccessPlatform());
        $this->post(route('password.update'), $newLink + ['password' => $password, 'password_confirmation' => $password])
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertTrue($user->fresh()->canAccessPlatform());
        $this->assertSame('user', $user->fresh()->role);
    }
}
