<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureTwoFactorEnabled;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class RequiredAdministratorMfaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('security.require_admin_two_factor', true);
    }

    public function test_an_administrator_must_enroll_before_accessing_protected_data(): void
    {
        $user = User::factory()->create(['two_factor_confirmed_at' => null]);

        $this->actingAs($user)->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.two-factor.show'));

        $this->get(route('admin.two-factor.show'))->assertOk();
    }

    public function test_an_enrolled_administrator_can_access_the_dashboard(): void
    {
        $user = User::factory()->create([
            'two_factor_secret' => 'test-totp-secret',
            'two_factor_confirmed_at' => now(),
        ]);

        $this->actingAs($user)->get(route('admin.dashboard'))->assertOk();
    }

    public function test_every_private_admin_route_carries_the_mfa_gate(): void
    {
        $routes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_starts_with($route->uri(), 'admin'))
            ->reject(fn ($route) => in_array($route->getName(), [
                'login', 'login.store', 'two-factor.challenge', 'two-factor.challenge.store',
            ], true));

        foreach ($routes as $route) {
            $this->assertContains(EnsureTwoFactorEnabled::class, $route->gatherMiddleware(), $route->uri());
        }
    }

    public function test_a_remember_request_cannot_create_a_persistent_login_by_default(): void
    {
        config()->set('security.require_admin_two_factor', false);
        config()->set('security.allow_admin_remember_me', false);
        $user = User::factory()->create([
            'email' => 'owner@example.com',
            'remember_token' => null,
        ]);

        $response = $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
            'remember' => '1',
        ]);

        $this->assertAuthenticatedAs($user);
        $this->assertNull($user->fresh()->remember_token);
        $response->assertCookieMissing(Auth::guard()->getRecallerName());
    }
}
