<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\text;

final class DisableAdministrator extends Command
{
    protected $signature = 'admin:disable
                            {--email= : Email of the administrator to disable}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Disable an administrator and immediately revoke authorization on every session';

    public function handle(): int
    {
        $email = $this->option('email') ?: text(
            label: 'Administrator email address',
            required: true,
        );
        $email = Str::lower(trim((string) $email));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email', 'max:254']])->fails()) {
            $this->error('Enter a valid administrator email address. Nothing was changed.');

            return self::INVALID;
        }

        $target = User::query()->where('email_normalized', $email)->first();

        if (! $target || ! $target->isAdministrator()) {
            $this->error('No active administrator matches that address. Nothing was changed.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! confirm(
            "Disable administrator account ID {$target->getKey()} and revoke its access?",
            default: false,
        )) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        $disabled = DB::transaction(function () use ($target): bool {
            $locked = User::query()->whereKey($target->getKey())->lockForUpdate()->first();

            if (! $locked || ! $locked->isAdministrator()) {
                return false;
            }

            $replacementExists = User::query()
                ->whereKeyNot($locked->getKey())
                ->where('role', User::ADMINISTRATOR_ROLE)
                ->where('is_active', true)
                ->whereNotNull('two_factor_confirmed_at')
                ->exists();

            if (! $replacementExists) {
                return false;
            }

            $locked->forceFill([
                'is_active' => false,
                'remember_token' => null,
            ])->save();

            AuditLog::query()->create([
                'user_id' => null,
                'action' => 'administrator.disabled',
                'auditable_type' => $locked->getMorphClass(),
                'auditable_id' => $locked->getKey(),
                'metadata' => ['source' => 'console'],
            ]);

            return true;
        }, attempts: 3);

        if (! $disabled) {
            $this->error('The account changed concurrently or no other active MFA-enrolled administrator exists. Nothing was changed.');

            return self::FAILURE;
        }

        $this->info("Administrator account ID {$target->getKey()} disabled. Existing and pending sessions no longer authorize administrator access.");

        return self::SUCCESS;
    }
}
