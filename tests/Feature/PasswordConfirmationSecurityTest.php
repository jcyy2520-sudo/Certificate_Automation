<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class PasswordConfirmationSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create([
            'password' => bcrypt('CorrectPassword123!'),
            'two_factor_confirmed_at' => now(),
        ]);
    }

    #[Test]
    public function a_valid_password_confirms_the_session_and_records_audit(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.password.confirm.store'), [
            'password' => 'CorrectPassword123!',
        ]);

        $response->assertRedirect(route('admin.dashboard'));
        $this->assertNotNull(session('auth.password_confirmed_at'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'administrator.password_confirmed',
            'auditable_id' => $this->admin->id,
            'auditable_type' => User::class,
        ]);
    }

    #[Test]
    public function an_invalid_password_is_rejected_and_audited(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.password.confirm.store'), [
            'password' => 'WrongPassword!',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'administrator.password_confirmation_failed',
            'auditable_id' => $this->admin->id,
            'auditable_type' => User::class,
        ]);
    }

    #[Test]
    public function empty_password_submission_is_audited_as_failure(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.password.confirm.store'), [
            'password' => '',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertNull(session('auth.password_confirmed_at'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'administrator.password_confirmation_failed',
            'auditable_id' => $this->admin->id,
            'auditable_type' => User::class,
        ]);
    }
}
