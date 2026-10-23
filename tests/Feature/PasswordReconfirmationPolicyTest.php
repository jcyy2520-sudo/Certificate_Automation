<?php

namespace Tests\Feature;

use App\Http\Controllers\TwoFactorController;
use App\Models\User;
use App\Models\Webinar;
use App\Services\SensitiveAccountVerification;
use App\Services\TwoFactorService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class PasswordReconfirmationPolicyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['security.require_sensitive_action_password_confirmation' => false, 'security.require_admin_two_factor' => false,
            'operations.local_immediate_delivery' => false]);
    }

    private function enrolledUser(): User
    {
        return User::factory()->create(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(), 'two_factor_recovery_codes' => app(TwoFactorService::class)->hashRecoveryCodes(['AAAAA-BBBBB'])]);
    }

    public function test_administrators_can_invite_and_edit_access_without_password_reentry_when_disabled(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertDontSee('autocomplete="current-password"', false);
        $this->post(route('admin.users.store'), ['name' => 'Synthetic invitee', 'email' => 'invitee@example.test',
            'role' => User::USER_ROLE, 'webinar_ids' => []])->assertSessionHasNoErrors();
        $invitee = User::query()->where('email', 'invitee@example.test')->sole();
        $this->put(route('admin.users.access', $invitee), ['role' => User::USER_ROLE, 'webinar_ids' => []])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.invited', 'actor_user_id' => $admin->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.access_updated', 'actor_user_id' => $admin->id]);
        $this->assertFalse($invitee->fresh()->is_active);
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_password_change_needs_only_a_valid_matching_new_password_when_reentry_is_disabled(): void
    {
        $user = User::factory()->create(['role' => User::USER_ROLE]);
        $reset = Password::createToken($user);
        $this->actingAs($user)->get(route('admin.profile'))->assertOk()->assertDontSee('name="current_password"', false)
            ->assertSee('name="password_confirmation"', false);
        $this->put(route('admin.profile.password'), ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->put(route('admin.profile.password'), ['password' => 'A new synthetic passphrase', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $this->put(route('admin.profile.password'), ['password' => 'A new synthetic passphrase', 'password_confirmation' => 'A new synthetic passphrase'])
            ->assertRedirect(route('login'))->assertSessionHasNoErrors();
        $this->assertGuest();
        $this->assertSame(2, $user->fresh()->auth_version);
        $this->assertTrue(Hash::check('A new synthetic passphrase', $user->fresh()->password));
        $this->assertFalse(Password::tokenExists($user->fresh(), $reset));
    }

    public function test_mfa_enrollment_without_password_reentry_keeps_pairing_and_rotates_session_credentials(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;
        $this->actingAs($user)->get(route('admin.two-factor.show'))->assertOk()->assertDontSee('autocomplete="current-password"', false);
        $secret = Crypt::decryptString(session('two_factor.pending_secret'));
        $this->post(route('admin.two-factor.enable'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertRedirect(route('admin.two-factor.show'))->assertSessionHasNoErrors();
        $this->assertSame($oldHash, $user->fresh()->password);
        $this->assertSame(2, $user->fresh()->auth_version);
        $this->assertSame(2, session('auth.account_version'));
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->get(route('admin.two-factor.show'))->assertOk();
    }

    public function test_mfa_removal_without_password_reentry_preserves_the_current_session_and_revokes_an_old_one(): void
    {
        $user = $this->enrolledUser();
        $this->actingAs($user)->get(route('admin.two-factor.show'))->assertOk()->assertDontSee('autocomplete="current-password"', false);
        $this->delete(route('admin.two-factor.disable'), ['code' => app(Google2FA::class)->getCurrentOtp($user->two_factor_secret)])
            ->assertRedirect(route('admin.two-factor.show'))->assertSessionHasNoErrors();
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->assertSame(2, session('auth.account_version'));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($user->fresh())->withSession(['auth.account_version' => 1])->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_recovery_code_regeneration_without_password_reentry_still_requires_a_fresh_factor(): void
    {
        $user = $this->enrolledUser();
        $previous = $user->two_factor_recovery_codes;
        $this->actingAs($user)->post(route('admin.two-factor.recovery-codes'), [])->assertSessionHasErrors('code');
        $this->assertSame($previous, $user->fresh()->two_factor_recovery_codes);
        $this->post(route('admin.two-factor.recovery-codes'), ['recovery_code' => 'AAAAA-BBBBB'])
            ->assertRedirect(route('admin.two-factor.show'))->assertSessionHasNoErrors();
        $this->assertNotSame($previous, $user->fresh()->two_factor_recovery_codes);
        $this->assertSame(2, $user->fresh()->auth_version);
        $this->assertSame(2, session('auth.account_version'));
        $this->get(route('admin.two-factor.show'))->assertOk();
    }

    public function test_policy_enabled_retains_current_password_validation_and_prompts(): void
    {
        config(['security.require_sensitive_action_password_confirmation' => true]);
        $admin = User::factory()->create();
        $this->actingAs($admin)->withSession(['auth.password_confirmed_at' => now()->timestamp])
            ->get(route('admin.users.index'))->assertOk()->assertSee('autocomplete="current-password"', false);
        $data = ['name' => 'Synthetic invitee', 'email' => 'invitee@example.test', 'role' => User::USER_ROLE, 'webinar_ids' => []];
        $this->post(route('admin.users.store'), $data)->assertSessionHasErrors('password');
        $this->post(route('admin.users.store'), $data + ['password' => 'wrong'])->assertSessionHasErrors('password');
        $this->post(route('admin.users.store'), $data + ['password' => 'password'])->assertSessionHasNoErrors();
        $this->get(route('admin.profile'))->assertOk()->assertSee('name="current_password"', false);
        $this->put(route('admin.profile.password'), ['password' => 'A new synthetic passphrase', 'password_confirmation' => 'A new synthetic passphrase'])
            ->assertSessionHasErrors('current_password');
    }

    public function test_owner_and_assigned_manager_certificate_settings_and_sends_do_not_ask_for_a_password(): void
    {
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $webinar->certificateTemplates()->create(['name' => 'Synthetic design', 'is_active' => true, 'background_path' => 'synthetic-unused.png', 'layout' => []]);
        $manager = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $manager->assignedWebinars()->attach($webinar, ['assigned_by' => $owner->id, 'manage_certificates' => true, 'send_emails' => true]);
        foreach ([$owner, $manager] as $user) {
            $this->actingAs($user)->withSession(['auth.password_confirmed_at' => 0])
                ->post(route('admin.certification.email', $webinar), ['certificate_email_subject' => 'Synthetic certificate', 'certificate_email_message' => 'Synthetic message'])
                ->assertSessionHasNoErrors()->assertSessionHas('success');
            $webinar->certificateBatches()->delete();
            $this->post(route('admin.certificates.batch', $webinar))->assertSessionHasNoErrors()->assertSessionHas('success');
            $this->assertDatabaseHas('certificate_batches', ['webinar_id' => $webinar->id, 'created_by' => $user->id]);
            $this->assertSame(0, session('auth.password_confirmed_at'));
        }
        $outsider = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $this->actingAs($outsider)->post(route('admin.certificates.batch', $webinar))->assertNotFound();
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_login_passwords_remain_required_when_reconfirmation_is_disabled(): void
    {
        $user = User::factory()->create();
        $this->post(route('login.store'), ['email' => $user->email])->assertSessionHasErrors('password');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong'])->assertSessionHasErrors('email');
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->assertSame(1, session('auth.account_version'));
    }

    public function test_a_stale_confirmation_page_does_not_prompt_or_replay_when_policy_is_disabled(): void
    {
        $this->actingAs(User::factory()->create())->get(route('admin.password.confirm'))->assertRedirect(route('admin.dashboard'));
        $this->post(route('admin.password.confirm.store'))->assertRedirect(route('admin.dashboard'))->assertSessionHasNoErrors();
        $this->assertNull(session('auth.password_confirmed_at'));
    }

    public function test_account_changes_recheck_the_session_credential_under_the_user_lock_without_password_reentry(): void
    {
        $user = User::factory()->create(['auth_version' => 2]);
        $request = Request::create('/admin/users', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->session()->put('auth.account_version', 1);
        $this->expectException(ValidationException::class);
        app(SensitiveAccountVerification::class)->verify($request, $user);
    }

    public static function passwordPolicies(): array
    {
        return ['reentry disabled' => [false], 'reentry enabled' => [true]];
    }

    #[DataProvider('passwordPolicies')]
    public function test_a_concurrent_password_reset_cannot_be_adopted_when_refreshing_an_mfa_session(bool $requirePassword): void
    {
        config(['security.require_sensitive_action_password_confirmation' => $requirePassword]);
        $user = User::factory()->create(['auth_version' => 2]);
        $verifiedMutation = $user->fresh();
        $this->actingAs($verifiedMutation)->withSession(['auth.account_version' => 2]);
        $request = Request::create('/admin/security/two-factor', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => auth()->user());
        // Another request resets credentials after the verified MFA transaction
        // commits, before its controller refreshes the current browser session.
        $user->forceFill(['password' => Hash::make('Concurrent reset passphrase'), 'auth_version' => 3])->save();
        try {
            (new \ReflectionMethod(TwoFactorController::class, 'refreshSession'))
                ->invoke(app(TwoFactorController::class), $request, $verifiedMutation);
            $this->fail('An MFA refresh must not adopt credentials changed by another request.');
        } catch (AuthenticationException) {
            $this->assertGuest();
            $this->assertNull($request->session()->get('auth.account_version'));
            $this->assertTrue(Hash::check('Concurrent reset passphrase', $user->fresh()->password));
        }
    }
}
