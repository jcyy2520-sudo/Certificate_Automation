<?php

namespace App\Console\Commands;

use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\AccountEmailReadiness;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TestAccountEmail extends Command
{
    protected $signature = 'accounts:test-email {user : Active administrator ID} {request : Stable UUID for this authorized delivery test}';

    protected $description = 'Queue one idempotent account transport test to an existing administrator; never changes account credentials';

    public function handle(AccountEmailReadiness $readiness, NotificationService $notifications): int
    {
        if (! $readiness->ready() || ! Str::isUuid($this->argument('request'))) {
            $this->error('Account email must be ready and the request identifier must be a UUID.');

            return self::FAILURE;
        }
        $delivery = DB::transaction(function () use ($notifications) {
            $user = User::query()->lockForUpdate()->findOrFail($this->argument('user'));
            abort_unless($user->isAdministrator() && $user->canAccessPlatform(), 403);
            $key = $this->argument('request');
            if ($existing = EmailDelivery::query()->where('idempotency_key', $key)->first()) {
                abort_unless($existing->account_user_id === $user->id && $existing->type === 'account_delivery_test', 409);

                return $existing;
            }
            $subject = config('branding.name', 'Certimate').' — account email delivery test';
            $message = 'This is the account-email delivery test you authorized. Account verification, approval and recovery emails now use the account email service. This message does not approve an account, reset a password, or send a certificate. No action is required.';
            $url = null;
            $label = null;

            return $notifications->queue(null, null, 'account_delivery_test', $user->email, $subject,
                view('emails.account', compact('user', 'subject', 'message', 'url', 'label'))->render(),
                expiresAt: now()->addHour(), idempotencyKey: $key, account: $user);
        });
        $this->info('Account delivery test recorded: '.$delivery->id.' ('.$delivery->statusLabel().').');

        return self::SUCCESS;
    }
}
