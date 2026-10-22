<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministratorBootstrapTest extends TestCase
{
    use RefreshDatabase;

    private array $files = [];

    protected function tearDown(): void
    {
        foreach ($this->files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        parent::tearDown();
    }

    public function test_bootstrap_creates_a_usable_approved_administrator_without_disclosing_the_hash(): void
    {
        $hash = Hash::make('synthetic bootstrap passphrase');
        $file = $this->bootstrapFile(['email' => 'ADMIN@EXAMPLE.TEST', 'password_hash' => $hash]);
        $this->artisan('admin:bootstrap', ['--file' => $file])
            ->expectsOutput('Initial administrator created. Remove the bootstrap secret and enroll two-factor authentication at sign-in.')
            ->doesntExpectOutput($hash)
            ->assertSuccessful();

        $user = User::query()->sole();
        $this->assertSame('admin@example.test', $user->email_normalized);
        $this->assertTrue($user->isAdministrator());
        $this->assertTrue($user->canAccessPlatform());
        $this->assertSame($hash, $user->password);
        $this->assertNotNull($user->approved_at);
        $this->assertNull($user->two_factor_confirmed_at);
    }

    public function test_restarting_cannot_reset_or_reactivate_an_existing_account_or_add_another_admin(): void
    {
        $user = User::factory()->create(['is_active' => false, 'approval_status' => 'suspended']);
        $before = $user->fresh()->getRawOriginal();
        $file = $this->bootstrapFile();

        $this->artisan('admin:bootstrap', ['--file' => $file])->assertSuccessful();

        $this->assertSame(1, User::query()->count());
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    public function test_plaintext_weak_hashes_and_invalid_files_cannot_create_accounts(): void
    {
        $this->artisan('admin:bootstrap', ['--file' => __DIR__.'/missing-bootstrap.json'])->assertFailed();
        foreach (['not-a-password-hash', password_hash('synthetic bootstrap passphrase', PASSWORD_BCRYPT, ['cost' => 4])] as $hash) {
            config(['hashing.bcrypt.rounds' => 12]);
            $this->artisan('admin:bootstrap', ['--file' => $this->bootstrapFile(['password_hash' => $hash])])->assertFailed();
        }

        $file = $this->bootstrapFile();
        file_put_contents($file, '{invalid json');
        $this->artisan('admin:bootstrap', ['--file' => $file])->assertFailed();
        file_put_contents($file, str_repeat(' ', 8193));
        $this->artisan('admin:bootstrap', ['--file' => $file])->assertFailed();
        $this->assertDatabaseCount('users', 0);
    }

    private function bootstrapFile(array $overrides = []): string
    {
        $file = storage_path('framework/testing/bootstrap-'.Str::uuid().'.json');
        file_put_contents($file, json_encode(array_merge([
            'email' => 'bootstrap@example.test',
            'name' => 'Bootstrap Administrator',
            'password_hash' => Hash::make('synthetic bootstrap passphrase'),
        ], $overrides), JSON_THROW_ON_ERROR));
        $this->files[] = $file;

        return $file;
    }
}
