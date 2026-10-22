<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmail;
use App\Models\AdminTrustedDevice;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AdminDeviceService;
use App\Services\AuditService;
use App\Services\Email\LogTransactionalMailer;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AccountManagementSecurityTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        return User::factory()->create([
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(), ...$attributes,
        ]);
    }

    private function context(string $agent = 'ExampleBrowser/100.1 Windows/10', string $ip = '8.8.8.8'): Request
    {
        return Request::create('https://localhost/admin', server: ['HTTP_USER_AGENT' => $agent, 'REMOTE_ADDR' => $ip]);
    }

    private function remember(User $user): string
    {
        app(AdminDeviceService::class)->remember($this->context(), $user);
        $cookie = Cookie::getQueuedCookies()[0];
        $this->assertTrue($cookie->isSecure());
        $this->assertTrue($cookie->isHttpOnly());
        $this->assertNull($cookie->getDomain());
        $this->assertSame('lax', $cookie->getSameSite());

        return $cookie->getValue();
    }

    private function trusted(User $user, string $token, ?Request $request = null): bool
    {
        $request ??= $this->context();
        $request->cookies->set(AdminDeviceService::COOKIE, $token);

        return app(AdminDeviceService::class)->trusted($request, $user);
    }

    public function test_public_request_is_saved_without_claiming_an_email_was_sent(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $this->post(route('account.store'), ['name' => 'Awaiting person', 'email' => 'awaiting@example.test'])->assertSessionHas('success');
        $pending = User::query()->where('email', 'awaiting@example.test')->firstOrFail();
        $this->assertFalse($pending->canAccessPlatform());
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertSee('Awaiting person')->assertSee('Pending request');
        $this->post(route('admin.users.update', $pending), ['action' => 'reject'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $pending->fresh()->approval_status);
    }

    public function test_admin_resend_is_throttled_and_does_not_replace_an_invitation_account(): void
    {
        Queue::fake();
        $admin = $this->admin();
        $user = app(AccountService::class)->request('Original requester', 'awaiting@example.test');
        $this->actingAs($admin)->post(route('admin.users.update', $user), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->post(route('admin.users.update', $user), ['action' => 'resend'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->travel(3)->minutes();
        $this->post(route('admin.users.update', $user), ['action' => 'resend'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('email_deliveries', 2);
        $this->assertSame('cancelled', EmailDelivery::query()->oldest('id')->first()->status);
        $this->assertSame('Original requester', $user->fresh()->name);
    }

    public function test_unavailable_account_mail_keeps_requests_and_does_not_fabricate_delivery(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->withoutMiddleware(ValidateCsrfToken::class);
        config(['accounts.email_provider' => 'log']);
        $this->post(route('account.store'), ['name' => 'Saved pending', 'email' => 'saved@example.test'])->assertSessionHas('success');
        $this->assertDatabaseHas('users', ['email' => 'saved@example.test', 'approval_status' => 'pending_approval']);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->get(route('account.status'))->assertSee('currently unavailable')->assertDontSee('will arrive shortly');
        $this->post(route('password.email'), ['email' => 'missing@example.test'])->assertSessionHas('warning');
    }

    public function test_account_transport_is_separate_from_certificate_transport(): void
    {
        Queue::fake();
        config(['accounts.email_provider' => 'brevo', 'webinar.email.provider' => 'log', 'services.brevo.key' => 'synthetic', 'services.brevo.from_address' => 'sender@example.test']);
        Http::fake(['api.brevo.com/*' => Http::response(['messageId' => 'synthetic-account-message'], 201)]);
        $user = app(AccountService::class)->request('Requester', 'request@example.test');
        app(AccountService::class)->transition($user, 'approve');
        $delivery = EmailDelivery::query()->sole();
        $this->assertSame('brevo', $delivery->provider);
        $job = new SendTransactionalEmail($delivery->id);
        $job->handle(app(LogTransactionalMailer::class));
        $job->handle(app(LogTransactionalMailer::class));
        Http::assertSentCount(1);
        $this->assertSame('Provider accepted', $delivery->fresh()->statusLabel());
        $this->assertSame('log', config('webinar.email.provider'));
    }

    public function test_trusted_cookie_is_hashed_user_bound_and_patch_versions_do_not_break_it(): void
    {
        $user = $this->admin();
        $token = $this->remember($user);
        $this->assertNotSame($token, AdminTrustedDevice::query()->sole()->token_hash);
        $this->assertFalse($this->trusted($this->admin(), $token));
        $this->assertTrue($this->trusted($user, $token, $this->context('ExampleBrowser/100.2 Windows/10', '8.8.8.9')));
    }

    public function test_meaningful_network_or_browser_changes_require_mfa_again(): void
    {
        $user = $this->admin();
        $token = $this->remember($user);
        $this->assertFalse($this->trusted($user, $token, $this->context(ip: '1.1.1.1')));
        $this->assertNotNull(AdminTrustedDevice::query()->sole()->revoked_at);
        $token = $this->remember($user);
        $this->assertFalse($this->trusted($user, $token, $this->context('OtherBrowser/100 Windows/10')));
    }

    public function test_untrusted_forwarded_headers_are_ignored(): void
    {
        $request = $this->context(ip: '127.0.0.1');
        $request->headers->set('X-Forwarded-For', '1.1.1.1');
        $request->headers->set('True-Client-IP', '8.8.8.8');
        $this->assertNull(app(AdminDeviceService::class)->network($request));
    }

    public function test_password_auth_version_and_mfa_changes_invalidate_trust(): void
    {
        foreach (['password' => bcrypt('new password'), 'auth_version' => 2, 'two_factor_secret' => app(Google2FA::class)->generateSecretKey()] as $field => $value) {
            Cookie::flushQueuedCookies();
            $user = $this->admin();
            $token = $this->remember($user);
            $user->forceFill([$field => $value])->save();
            $this->assertFalse($this->trusted($user->fresh(), $token));
        }
    }

    public function test_expired_and_revoked_devices_do_not_bypass_mfa(): void
    {
        $user = $this->admin();
        $token = $this->remember($user);
        AdminTrustedDevice::query()->update(['expires_at' => now()->subSecond()]);
        $this->assertFalse($this->trusted($user, $token));
        Cookie::flushQueuedCookies();
        $token = $this->remember($user);
        app(AdminDeviceService::class)->revokeAll($user);
        $this->assertFalse($this->trusted($user, $token));
    }

    public function test_device_fingerprint_retains_browser_and_operating_system_major_versions(): void
    {
        $devices = app(AdminDeviceService::class);
        $original = $devices->browser($this->context());
        $this->assertSame($original, $devices->browser($this->context('ExampleBrowser/100.9 Windows/10')));
        $this->assertNotSame($original, $devices->browser($this->context('ExampleBrowser/101.1 Windows/10')));
        $this->assertNotSame($original, $devices->browser($this->context('ExampleBrowser/100.1 Windows/11')));
    }

    public function test_trust_never_establishes_a_session_without_a_correct_password(): void
    {
        $user = $this->admin();
        $token = $this->remember($user);
        $this->withUnencryptedCookie(AdminDeviceService::COOKIE, $token)->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_password_with_a_valid_trusted_device_skips_only_the_second_factor(): void
    {
        config(['security.require_admin_two_factor' => true]);
        $user = $this->admin();
        $token = $this->remember($user);
        $this->withServerVariables(['REMOTE_ADDR' => '8.8.8.8'])
            ->withHeader('User-Agent', 'ExampleBrowser/100.1 Windows/10')
            ->withCookie(AdminDeviceService::COOKIE, $token)
            ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->get(route('admin.dashboard'))->assertOk();
    }

    public function test_five_recent_failures_revoke_trust_and_require_a_fresh_factor(): void
    {
        $user = $this->admin();
        $token = $this->remember($user);
        $this->travel(1)->seconds();
        for ($i = 0; $i < 5; $i++) {
            app(AuditService::class)->record($this->context(), 'administrator.login_failed', $user);
        }
        $this->assertFalse($this->trusted($user, $token));
    }

    public function test_only_a_successful_totp_challenge_can_issue_a_trusted_cookie(): void
    {
        $user = $this->admin();
        $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['code' => 'not-six-digits', 'trust_device' => '1'])->assertSessionHasErrors('code');
        $this->assertDatabaseCount('admin_trusted_devices', 0);
        $this->post(str_replace('http://', 'https://', route('two-factor.challenge.store')), ['code' => app(Google2FA::class)->getCurrentOtp($user->two_factor_secret), 'trust_device' => '1'])
            ->assertRedirect(route('admin.dashboard'))->assertCookie(AdminDeviceService::COOKIE);
        $this->assertDatabaseCount('admin_trusted_devices', 1);
        $this->assertArrayNotHasKey('code', session('_old_input', []));
    }

    public function test_http_never_issues_a_persistent_device_credential(): void
    {
        app(AdminDeviceService::class)->remember(Request::create('http://localhost'), $this->admin());
        $this->assertDatabaseCount('admin_trusted_devices', 0);
    }

    public function test_verified_session_detects_context_changes_and_old_sessions_need_reverification(): void
    {
        config(['security.require_admin_two_factor' => true]);
        $user = $this->admin();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['code' => app(Google2FA::class)->getCurrentOtp($user->two_factor_secret)])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->withHeader('User-Agent', 'DifferentBrowser/10')->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_recovery_login_revokes_trust_and_cannot_remember_a_new_device(): void
    {
        $factors = app(TwoFactorService::class);
        $codes = $factors->generateRecoveryCodes();
        $user = $this->admin(['two_factor_recovery_codes' => $factors->hashRecoveryCodes($codes)]);
        $this->remember($user);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[0], 'trust_device' => '1'])->assertRedirect(route('admin.dashboard'));
        $this->assertSame(0, AdminTrustedDevice::query()->whereNull('revoked_at')->count());
        $this->assertCount(9, $user->fresh()->two_factor_recovery_codes);
    }

    public function test_password_alone_cannot_remove_or_replace_a_factor(): void
    {
        $user = $this->admin();
        $before = $user->two_factor_secret;
        $this->actingAs($user)->delete(route('admin.two-factor.disable'), ['password' => 'password'])->assertSessionHasErrors('code');
        $this->post(route('admin.two-factor.recovery-codes'), ['password' => 'password'])->assertSessionHasErrors('code');
        $this->assertSame($before, $user->fresh()->two_factor_secret);
        $this->get(route('admin.two-factor.show'))->assertDontSee('Two-factor setup QR code')->assertDontSee($before);
    }

    public function test_regular_users_cannot_manage_accounts_or_promote_themselves(): void
    {
        $member = User::factory()->create(['role' => 'user']);
        $admin = $this->admin();
        $this->actingAs($member)->get(route('admin.users.index'))->assertForbidden();
        $this->put(route('admin.users.access', $member), ['role' => 'administrator', 'password' => 'password', 'code' => '123456'])->assertForbidden();
        $this->post(route('admin.users.update', $admin), ['action' => 'suspend'])->assertForbidden();
        $this->assertSame('user', $member->fresh()->role);
    }
}
