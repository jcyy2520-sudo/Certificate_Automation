<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdministratorCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_an_active_administrator(): void
    {
        $this->artisan('admin:create', ['--email' => 'owner@example.com', '--name' => 'Platform Owner'])
            ->expectsQuestion('Password', 'a-long-enough-passphrase')
            ->expectsQuestion('Confirm password', 'a-long-enough-passphrase')
            ->assertSuccessful();

        $user = User::query()->where('email', 'owner@example.com')->firstOrFail();
        $this->assertSame('Platform Owner', $user->name);
        $this->assertSame('administrator', $user->role);
        $this->assertTrue($user->is_active);
        $this->assertTrue(Hash::check('a-long-enough-passphrase', $user->password));
    }

    public function test_it_resets_the_password_of_an_existing_account(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com', 'password' => bcrypt('old-password')]);

        $this->artisan('admin:create', ['--email' => 'owner@example.com', '--name' => 'Platform Owner'])
            ->expectsQuestion('New password for owner@example.com', 'a-brand-new-passphrase')
            ->expectsQuestion('Confirm password', 'a-brand-new-passphrase')
            ->assertSuccessful();

        $this->assertSame(1, User::query()->count(), 'Resetting must not create a second account.');
        $this->assertTrue(Hash::check('a-brand-new-passphrase', $user->fresh()->password));
    }

    public function test_it_refuses_when_the_confirmation_does_not_match(): void
    {
        $this->artisan('admin:create', ['--email' => 'owner@example.com', '--name' => 'Owner'])
            ->expectsQuestion('Password', 'a-long-enough-passphrase')
            ->expectsQuestion('Confirm password', 'something-else-entirely')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
