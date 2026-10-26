<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticatedGuestRedirectTest extends TestCase
{
    use RefreshDatabase;

    public static function platformRoles(): array
    {
        return [
            'administrator' => [User::ADMINISTRATOR_ROLE],
            'user' => [User::USER_ROLE],
            'manager' => [User::MANAGER_ROLE],
        ];
    }

    #[DataProvider('platformRoles')]
    public function test_returning_signed_in_users_leave_the_login_page_for_the_dashboard(string $role): void
    {
        $user = User::factory()->create(['role' => $role]);

        $this->actingAs($user)->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));

        $this->followingRedirects()->get(route('home'))
            ->assertOk()->assertSee('Dashboard');
        $this->assertAuthenticatedAs($user);
    }

    public function test_a_revoked_session_reaching_login_terminates_at_sign_in(): void
    {
        $user = User::factory()->create(['is_active' => false]);

        $this->actingAs($user)->get(route('login'))
            ->assertRedirect(route('admin.dashboard'));

        $this->followingRedirects()->get(route('admin.dashboard'))
            ->assertOk()->assertSee('Sign in');
        $this->assertGuest();
    }

    public function test_guests_can_still_reach_the_sign_in_page_from_home(): void
    {
        $this->followingRedirects()->get(route('home'))
            ->assertOk()->assertSee('Sign in');
        $this->assertGuest();
    }
}
