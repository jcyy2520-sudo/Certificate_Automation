<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

class CreateAdministrator extends Command
{
    protected $signature = 'admin:create
                            {--email= : The administrator email address}
                            {--name= : The display name}';

    protected $description = 'Create an administrator account, or reset the password of an existing one';

    public function handle(): int
    {
        $email = $this->option('email') ?: text(
            label: 'Email address',
            required: true,
            validate: fn (string $value) => Validator::make(['email' => $value], ['email' => ['required', 'email']])->fails()
                ? 'Enter a valid email address.'
                : null,
        );
        $email = Str::lower(trim($email));

        $existing = User::query()->where('email_normalized', $email)->first();

        $name = $this->option('name') ?: text(
            label: 'Display name',
            default: $existing->name ?? '',
            required: true,
        );

        // Prompted rather than passed as an argument so it never reaches the shell history.
        $secret = password(
            label: $existing ? 'New password for '.$email : 'Password',
            required: true,
            validate: fn (string $value) => strlen($value) < 15
                ? 'Use at least 15 characters. A passphrase of several words works well.'
                : null,
        );

        if (password(label: 'Confirm password', required: true) !== $secret) {
            $this->error('The passwords did not match. Nothing was changed.');

            return self::FAILURE;
        }

        $user = $existing ?? new User;
        $user->forceFill([
            'email' => $email,
            'name' => $name,
            'password' => Hash::make($secret),
            'email_verified_at' => now(),
            'role' => User::ADMINISTRATOR_ROLE,
            'is_active' => true,
        ])->save();

        $this->newLine();
        $this->info($existing ? "Password reset for {$user->email}." : "Administrator {$user->email} created.");

        if (! $user->two_factor_confirmed_at) {
            $this->line('  Next: sign in, then open Security to scan a two-factor QR code.');
        }

        return self::SUCCESS;
    }
}
