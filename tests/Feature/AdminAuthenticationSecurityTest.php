<?php

namespace Tests\Feature;

use App\Http\Controllers\TwoFactorChallengeController;
use App\Http\Middleware\EnsureAdministrator;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class AdminAuthenticationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_active_administrator_accounts_can_pass_the_password_login(): void
    {
        $nonAdministrator = User::factory()->create([
            'email' => 'member@example.com',
            'role' => 'participant',
            'is_active' => true,
        ]);
        $inactiveAdministrator = User::factory()->create([
            'email' => 'inactive@example.com',
            'is_active' => false,
        ]);

        foreach ([$nonAdministrator, $inactiveAdministrator] as $user) {
            $this->post(route('login.store'), [
                'email' => $user->email,
                'password' => 'password',
            ])->assertSessionHasErrors('email');

            $this->assertGuest();
        }

        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.login_failed']);
    }

    public function test_an_inactive_account_is_revoked_on_its_next_admin_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        $user->forceFill(['is_active' => false])->save();

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_demoted_account_is_revoked_on_its_next_admin_request(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        $user->forceFill(['role' => 'participant'])->save();

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_password_change_revokes_an_existing_admin_session(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();

        $user->update(['password' => Hash::make('a-different-password')]);

        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_every_admin_route_rechecks_account_authorization_and_session_password(): void
    {
        $adminRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin'))
            ->reject(fn ($route) => in_array($route->getName(), [
                'login',
                'login.store',
                'two-factor.challenge',
                'two-factor.challenge.store',
            ], true));

        $this->assertNotEmpty($adminRoutes);

        foreach ($adminRoutes as $route) {
            $middleware = $route->gatherMiddleware();
            $this->assertContains('auth', $middleware, $route->uri().' lacks authentication.');
            $this->assertContains(EnsureAdministrator::class, $middleware, $route->uri().' lacks administrator authorization.');
            $this->assertContains('auth.session', $middleware, $route->uri().' does not detect password/session revocation.');
            $this->assertTrue($route->enforcesScopedBindings(), $route->uri().' does not use scoped route bindings.');
        }
    }

    public function test_password_acceptance_rotates_the_session_before_the_two_factor_challenge(): void
    {
        $user = $this->userWithTwoFactor();
        $this->get(route('login'));
        $before = session()->getId();

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('two-factor.challenge'));

        $this->assertNotSame($before, session()->getId());
        $this->assertSame($user->id, session(TwoFactorChallengeController::PENDING_USER));
        $this->assertIsInt(session(TwoFactorChallengeController::PENDING_AT));
    }

    public function test_a_pending_two_factor_login_expires_and_is_fully_cleared(): void
    {
        $user = $this->userWithTwoFactor();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);

        $this->travel(301)->seconds();

        $this->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->assertNull(session(TwoFactorChallengeController::PENDING_USER));
        $this->assertNull(session(TwoFactorChallengeController::PENDING_REMEMBER));
        $this->assertNull(session(TwoFactorChallengeController::PENDING_AT));
    }

    public function test_deactivation_during_the_two_factor_challenge_cancels_the_login(): void
    {
        $user = $this->userWithTwoFactor();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $user->forceFill(['is_active' => false])->save();

        $this->post(route('two-factor.challenge.store'), ['code' => '123456'])
            ->assertRedirect(route('login'));

        $this->assertGuest();
        $this->assertNull(session(TwoFactorChallengeController::PENDING_USER));
    }

    public function test_login_guessing_is_throttled_by_normalized_email_and_ip(): void
    {
        RateLimiter::clear('admin-login');

        foreach (range(1, 5) as $attempt) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
                ->post(route('login.store'), [
                    'email' => $attempt % 2 ? 'UNKNOWN@EXAMPLE.COM' : 'unknown@example.com',
                    'password' => 'incorrect',
                ])->assertStatus(302);
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->post(route('login.store'), [
                'email' => 'unknown@example.com',
                'password' => 'incorrect',
            ])->assertTooManyRequests();
    }

    public function test_a_valid_login_rehashes_an_outdated_password_hash(): void
    {
        $user = User::factory()->create([
            'email' => 'rehash@example.com',
            'password' => password_hash('password', PASSWORD_BCRYPT, ['cost' => 4]),
        ]);
        Hash::driver('bcrypt')->setRounds(5);
        $this->assertTrue(Hash::needsRehash($user->password));

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertFalse(Hash::needsRehash($user->fresh()->password));
    }

    private function userWithTwoFactor(): User
    {
        return User::factory()->create([
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
