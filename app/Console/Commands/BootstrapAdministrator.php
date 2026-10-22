<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;

class BootstrapAdministrator extends Command
{
    protected $signature = 'admin:bootstrap {--file= : Private JSON file containing email, name and password_hash}';

    protected $description = 'Create an initial administrator from a private password hash without resetting existing accounts';

    public function handle(): int
    {
        $file = $this->option('file');
        if (! is_string($file) || ! is_file($file) || ! is_readable($file) || filesize($file) > 8192) {
            $this->error('Provide a readable private bootstrap JSON file of at most 8 KiB.');

            return self::FAILURE;
        }

        try {
            $data = json_decode(file_get_contents($file), true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->error('The bootstrap file must contain a valid JSON object.');

            return self::FAILURE;
        }

        if (! is_array($data) || Validator::make($data, [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'password_hash' => ['required', 'string', 'max:255'],
        ])->fails()) {
            $this->error('The bootstrap file requires email, name and password_hash.');

            return self::FAILURE;
        }

        // Only a hash generated with the configured application hasher is
        // accepted. Plaintext passwords never belong in this file or Git.
        if ((Hash::info($data['password_hash'])['algoName'] ?? 'unknown') === 'unknown'
            || Hash::needsRehash($data['password_hash'])) {
            $this->error('Generate password_hash with the configured application hasher and work factor.');

            return self::FAILURE;
        }

        $email = Str::lower(trim($data['email']));
        $created = DB::transaction(function () use ($data, $email): bool {
            // This command is deliberately create-only. Leaving the secret file
            // mounted across restarts must never reset a password or restore a
            // suspended account. A fresh database is required for bootstrap.
            if (User::query()->exists()) {
                return false;
            }

            User::query()->forceCreate([
                'email' => $email,
                'name' => $data['name'],
                'password' => $data['password_hash'],
                'email_verified_at' => now(),
                'role' => User::ADMINISTRATOR_ROLE,
                'is_active' => true,
                'approval_status' => 'approved',
                'approved_at' => now(),
                'password_setup_at' => now(),
                'remember_token' => Str::random(60),
            ]);

            return true;
        });

        $this->info($created
            ? 'Initial administrator created. Remove the bootstrap secret and enroll two-factor authentication at sign-in.'
            : 'Accounts already exist. Bootstrap skipped without changing credentials or permissions.');

        return self::SUCCESS;
    }
}
