<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRecentPassword;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Webinar;
use App\Services\AuditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class OptionalAdministratorMfaTest extends TestCase
{
    use RefreshDatabase;

    public function test_optional_mfa_can_be_turned_off_and_password_login_still_works(): void
    {
        $user = $this->enrolledAdministrator();
        $other = $this->enrolledAdministrator();
        $version = $user->auth_version;
        $this->actingAs($user)->get(route('admin.two-factor.show'))->assertSee('Turn off MFA');
        $this->delete(route('admin.two-factor.disable'), [
            'password' => 'password', 'code' => app(Google2FA::class)->getCurrentOtp($user->two_factor_secret),
            'user_id' => $other->id,
        ])->assertRedirect(route('admin.two-factor.show'))->assertSessionHas('success', fn ($message) => str_contains($message, 'authentication is off'));
        $user->refresh();
        $this->assertNull($user->two_factor_secret);
        $this->assertNull($user->two_factor_recovery_codes);
        $this->assertNull($user->two_factor_confirmed_at);
        $this->assertNull($user->two_factor_last_used_step);
        $this->assertSame($version + 1, $user->auth_version);
        $this->assertNotNull($other->fresh()->two_factor_confirmed_at);
        $event = AuditLog::query()->where('action', 'administrator.two_factor_disabled')->sole();
        $this->assertSame($user->id, $event->actor_user_id);
        $this->assertSame($user->email, $event->actor_email);
        $this->post(route('admin.logout'));
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.two-factor.show'))->assertSee('MFA is off.')->assertSee('Enrollment below is optional.');
    }

    public function test_required_mfa_still_requires_replacement_enrollment(): void
    {
        config(['security.require_admin_two_factor' => true]);
        $user = $this->enrolledAdministrator();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password']);
        $this->post(route('two-factor.challenge.store'), ['code' => app(Google2FA::class)->getCurrentOtp($user->two_factor_secret)]);
        $this->travel(31)->seconds();
        $this->get(route('admin.two-factor.show'))->assertSee('Replace your authenticator')->assertDontSee('Turn off MFA');
        $this->delete(route('admin.two-factor.disable'), [
            'password' => 'password', 'code' => app(Google2FA::class)->oathTotp($user->two_factor_secret, app(Google2FA::class)->getTimestamp() + 1),
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.two-factor.show'));
        $this->assertNull($user->fresh()->two_factor_confirmed_at);
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.two-factor.show'));
    }

    public function test_an_optional_unenrolled_admin_can_invite_with_password_confirmation(): void
    {
        Queue::fake();
        $admin = User::factory()->create();
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertDontSee('name="code"', false);
        $this->post(route('admin.users.store'), $this->invitation())
            ->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index', ['status' => 'invited']));
        $this->assertDatabaseHas('users', ['email' => 'groupmate@example.test', 'role' => User::MANAGER_ROLE, 'is_active' => false]);
    }

    public function test_optional_mfa_does_not_allow_a_wrong_password_for_account_changes(): void
    {
        config(['security.require_sensitive_action_password_confirmation' => true]);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        Queue::fake();
        $admin = User::factory()->create();
        $this->actingAs($admin)->post(route('admin.users.store'), array_replace($this->invitation(), ['password' => 'wrong']))
            ->assertSessionHasErrors('password');
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_an_enrolled_admin_still_needs_the_factor_for_account_changes(): void
    {
        Queue::fake();
        $admin = $this->enrolledAdministrator();
        $this->actingAs($admin)->post(route('admin.users.store'), $this->invitation())->assertSessionHasErrors('code');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_optional_mfa_does_not_give_managers_access_to_account_management(): void
    {
        $manager = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $this->actingAs($manager)->post(route('admin.users.store'), $this->invitation())->assertForbidden();
        $this->assertDatabaseCount('users', 1);
    }

    public function test_audit_failure_rolls_back_mfa_removal_and_factor_consumption(): void
    {
        $user = $this->enrolledAdministrator();
        $version = $user->auth_version;
        $this->partialMock(AuditService::class)->shouldReceive('record')
            ->withArgs(fn ($request, $action) => $action === 'administrator.two_factor_disabled')
            ->once()->andThrow(new \RuntimeException('Isolated audit failure'));
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($user)->delete(route('admin.two-factor.disable'), [
                'password' => 'password', 'code' => app(Google2FA::class)->getCurrentOtp($user->two_factor_secret),
            ]);
            $this->fail('Expected isolated audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Isolated audit failure', $exception->getMessage());
        }
        $this->assertNotNull($user->fresh()->two_factor_confirmed_at);
        $this->assertNull($user->fresh()->two_factor_last_used_step);
        $this->assertSame($version, $user->fresh()->auth_version);
    }

    public function test_mfa_removal_keeps_the_recent_password_gate(): void
    {
        $this->assertContains(EnsureRecentPassword::class, Route::getRoutes()->getByName('admin.two-factor.disable')->gatherMiddleware());
        $this->delete(route('admin.two-factor.disable'))->assertRedirect(route('login'));
    }

    private function enrolledAdministrator(): User
    {
        return User::factory()->create([
            'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    private function invitation(): array
    {
        $webinar = Webinar::query()->create(['title' => 'Assigned event', 'slug' => Str::uuid(), 'status' => 'published', 'created_by' => auth()->id(), 'ends_at' => now()->addDay(), 'data_retention_days' => 30]);

        return ['name' => 'Groupmate', 'email' => 'groupmate@example.test', 'role' => User::MANAGER_ROLE, 'webinar_ids' => [$webinar->id], 'password' => 'password'];
    }
}
