<?php

namespace Tests\Feature;

use App\Http\Controllers\TwoFactorChallengeController;
use App\Models\User;
use App\Services\TwoFactorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class TwoFactorAuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_administrator_enables_two_factor_and_receives_recovery_codes(): void
    {
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)->get(route('admin.two-factor.show'))->assertOk()->assertSee('Scan the code');

        $secret = Crypt::decryptString(session('two_factor.pending_secret'));
        $this->assertNotEmpty($secret);
        $this->assertNotSame($secret, session('two_factor.pending_secret'));

        $this->actingAs($user)->post(route('admin.two-factor.enable'), [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
            'password' => 'password',
        ])->assertRedirect(route('admin.two-factor.show'))->assertSessionHas('two_factor.recovery_codes');

        $this->assertIsString(session('two_factor.recovery_codes'));

        $user->refresh();
        $this->assertNotNull($user->two_factor_confirmed_at);
        $this->assertSame($secret, $user->two_factor_secret);
        $this->assertCount(10, $user->two_factor_recovery_codes);

        // Recovery codes are stored hashed, never in the clear.
        foreach ($user->two_factor_recovery_codes as $stored) {
            $this->assertStringStartsWith('$2y$', $stored);
        }
    }

    public function test_a_wrong_setup_code_does_not_enable_two_factor(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $this->actingAs($user)->get(route('admin.two-factor.show'));

        $this->actingAs($user)->post(route('admin.two-factor.enable'), [
            'code' => '000000',
            'password' => 'password',
        ])
            ->assertSessionHasErrors('code');

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
    }

    public function test_enabling_two_factor_requires_the_current_password(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.two-factor.show'));
        $secret = Crypt::decryptString(session('two_factor.pending_secret'));

        $this->actingAs($user)->post(route('admin.two-factor.enable'), [
            'code' => app(Google2FA::class)->getCurrentOtp($secret),
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('password');

        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->assertArrayNotHasKey('code', session('_old_input', []));
    }

    public function test_the_pending_setup_qr_code_is_rendered_only_once_per_session(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $twoFactor = \Mockery::mock(TwoFactorService::class);
        $twoFactor->shouldReceive('generateSecret')->once()->andReturn('PENDINGSECRET');
        $twoFactor->shouldReceive('qrCodeDataUri')->once()->andReturn('data:image/png;base64,cached');
        $this->instance(TwoFactorService::class, $twoFactor);

        $this->actingAs($user)->get(route('admin.two-factor.show'))->assertOk();
        $this->actingAs($user)->get(route('admin.two-factor.show'))->assertOk();

        $this->assertSame(
            'data:image/png;base64,cached',
            Crypt::decryptString(session('two_factor.pending_qr_code')),
        );
    }

    public function test_a_password_alone_does_not_sign_in_an_account_with_two_factor(): void
    {
        $user = $this->userWithTwoFactor();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('two-factor.challenge'));

        $this->assertGuest();
        $this->assertSame($user->id, session(TwoFactorChallengeController::PENDING_USER));

        // The admin area stays closed while only the first factor has been cleared.
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_valid_totp_code_completes_the_challenge(): void
    {
        $user = $this->userWithTwoFactor();

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->get(route('two-factor.challenge'))->assertOk();

        $this->post(route('two-factor.challenge.store'), [
            'code' => app(Google2FA::class)->getCurrentOtp($user->two_factor_secret),
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertNull(session(TwoFactorChallengeController::PENDING_USER));
    }

    public function test_a_totp_code_cannot_be_replayed_within_its_time_window(): void
    {
        $user = $this->userWithTwoFactor();
        $code = app(Google2FA::class)->getCurrentOtp($user->two_factor_secret);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertNotNull($user->fresh()->two_factor_last_used_step);

        $this->post(route('admin.logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['code' => $code])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_an_invalid_code_is_rejected_and_audited(): void
    {
        $user = $this->userWithTwoFactor();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->post(route('two-factor.challenge.store'), ['code' => '123456'])
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertArrayNotHasKey('code', session('_old_input', []));
        $this->assertArrayNotHasKey('recovery_code', session('_old_input', []));
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.two_factor_failed', 'user_id' => null]);
    }

    public function test_a_recovery_code_signs_in_once_and_is_then_consumed(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $codes = $twoFactor->generateRecoveryCodes();
        $user = $this->userWithTwoFactor(['two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes)]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[0]])
            ->assertRedirect(route('admin.dashboard'));
        $this->assertAuthenticatedAs($user);
        $this->assertCount(9, $user->fresh()->two_factor_recovery_codes);

        // The same code cannot be replayed.
        $this->post(route('admin.logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[0]])
            ->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_the_challenge_screen_is_unreachable_without_a_pending_login(): void
    {
        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->post(route('two-factor.challenge.store'), ['code' => '123456'])->assertRedirect(route('login'));
    }

    public function test_two_factor_can_be_disabled_with_the_current_password(): void
    {
        $user = $this->userWithTwoFactor();

        $this->actingAs($user)->delete(route('admin.two-factor.disable'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);

        $this->actingAs($user)->delete(route('admin.two-factor.disable'), ['password' => 'password'])
            ->assertRedirect(route('admin.two-factor.show'));

        $user->refresh();
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_secret);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.two_factor_disabled']);
    }

    public function test_regenerating_recovery_codes_invalidates_the_previous_set(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $codes = $twoFactor->generateRecoveryCodes();
        $user = $this->userWithTwoFactor(['two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes)]);

        $this->actingAs($user)->post(route('admin.two-factor.recovery-codes'), ['password' => 'password'])
            ->assertSessionHas('two_factor.recovery_codes');

        $this->assertFalse($twoFactor->consumeRecoveryCode($user->fresh(), $codes[0]));
    }

    public function test_regenerating_recovery_codes_requires_the_current_password(): void
    {
        $twoFactor = app(TwoFactorService::class);
        $codes = $twoFactor->generateRecoveryCodes();
        $user = $this->userWithTwoFactor(['two_factor_recovery_codes' => $twoFactor->hashRecoveryCodes($codes)]);
        $before = $user->two_factor_recovery_codes;

        $this->actingAs($user)->post(route('admin.two-factor.recovery-codes'), [
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('password');

        $this->assertSame($before, $user->fresh()->two_factor_recovery_codes);
    }

    private function userWithTwoFactor(array $attributes = []): User
    {
        return User::factory()->create([
            'is_active' => true,
            'password' => bcrypt('password'),
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
            ...$attributes,
        ]);
    }
}
