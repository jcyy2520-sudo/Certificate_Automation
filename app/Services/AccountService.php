<?php

namespace App\Services;

use App\Models\EmailDelivery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountService
{
    public function request(string $name, string $email): void
    {
        DB::transaction(function () use ($name, $email): void {
            // The normalized unique constraint arbitrates simultaneous requests.
            $user = User::query()->firstOrCreate(['email' => $email], [
                'name' => $name, 'password' => Hash::make(Str::random(64)),
                'role' => User::USER_ROLE, 'is_active' => false,
            ]);
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            if ($user->approval_status !== 'pending_verification' || ! $this->mayResend($user)) {
                return;
            }
            $token = Str::random(64);
            $user->forceFill([
                'verification_token_hash' => hash('sha256', $token),
                'verification_expires_at' => now()->addMinutes(max(5, config('accounts.verification_minutes'))),
            ])->save();
            $this->cancelPending($user);
            $url = route('account.verify', ['user' => $user->id]).'#'.http_build_query(['token' => $token]);
            $this->mail($user, 'verification', 'Verify your account request', 'Confirm your email address to send your account request to the platform administrator for review.', $url, 'Verify email address', $user->verification_expires_at);
        }, attempts: 3);
    }

    public function verify(User $user, string $token): void
    {
        DB::transaction(function () use ($user, $token): void {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($this->validVerification($user, $token), 403, 'This verification link has expired or has already been used. Request a new link.');
            $user->forceFill([
                'email_verified_at' => now(), 'approval_status' => 'pending_approval',
                'verification_token_hash' => null, 'verification_expires_at' => null,
            ])->save();
            $this->cancelPending($user);
        });
    }

    public function validVerification(User $user, string $token): bool
    {
        return $user->approval_status === 'pending_verification'
            && $user->verification_expires_at?->isFuture()
            && is_string($user->verification_token_hash)
            && hash_equals($user->verification_token_hash, hash('sha256', $token));
    }

    public function transition(User $target, string $action): void
    {
        // Lock the administrator set in a stable order before the target. This
        // serializes simultaneous final-administrator suspension decisions.
        DB::transaction(function () use ($target, $action): void {
            $admins = User::query()->where('role', User::ADMINISTRATOR_ROLE)->orderBy('id')->lockForUpdate()->get();
            $user = User::query()->whereKey($target->id)->lockForUpdate()->firstOrFail();
            $status = $user->approval_status;
            $next = match ($action) {
                'approve' => $status === 'pending_approval' && $user->email_verified_at ? 'approved' : null,
                'reject' => $status === 'pending_approval' ? 'rejected' : null,
                'suspend' => $status === 'approved' ? 'suspended' : null,
                'reactivate' => $status === 'suspended' ? 'approved' : null,
                default => null,
            };
            if (! $next) {
                throw ValidationException::withMessages(['account' => 'The account changed. Refresh the list and review its current status.']);
            }
            if ($action === 'suspend' && $user->isAdministrator()
                && $admins->filter(fn ($admin) => $admin->isAdministrator() && $admin->password_setup_at)->count() <= 1) {
                throw ValidationException::withMessages(['account' => 'The final active administrator cannot be suspended.']);
            }
            $this->cancelPending($user);
            Password::deleteToken($user);
            $user->forceFill([
                'approval_status' => $next,
                'is_active' => $next === 'approved',
                'approved_at' => $next === 'approved' ? ($user->approved_at ?? now()) : $user->approved_at,
                'auth_version' => $user->auth_version + 1,
                'remember_token' => Str::random(60),
                // Changing the hash also revokes previously established auth.session sessions,
                // including if an account is reactivated before the old browser returns.
                'password' => $action === 'suspend' ? Hash::make(Str::random(64)) : $user->password,
            ])->save();
            if ($action === 'approve' || $action === 'reactivate') {
                $this->invite($user, true);
            } elseif ($action === 'reject') {
                $this->mail($user, 'rejection', 'Update on your account request', 'Your account request was not approved. Contact the platform administrator if you believe this decision should be reviewed.');
            }
        }, attempts: 3);
    }

    public function invite(User $user, bool $force = false): void
    {
        if ($user->approval_status !== 'approved' || ! $user->is_active || (! $force && ! $this->mayResend($user))) {
            return;
        }
        $this->cancelPending($user);
        $token = Password::createToken($user);
        $this->passwordInvitation($user, $token);
    }

    public function passwordInvitation(User $user, string $token): void
    {
        $setup = $user->password_setup_at === null;
        $this->mail($user, $setup ? 'setup' : 'reset', $setup ? 'Your account is approved — set your password' : 'Reset your password',
            $setup ? 'Your account request has been approved. Create your password to finish setting up your account.' : 'A password reset was requested for your account. If you did not request this, you can ignore this email.',
            route('password.reset', ['setup' => $setup ? 1 : 0]).'#'.http_build_query(['token' => $token, 'email' => $user->email]),
            $setup ? 'Set password' : 'Reset password', now()->addMinutes(config('auth.passwords.users.expire')));
    }

    public function cancelPending(User $user): void
    {
        EmailDelivery::query()->where('account_user_id', $user->id)->whereIn('status', ['pending', 'processing'])->update([
            'status' => 'cancelled', 'payload' => null, 'recipient_email' => null,
        ]);
    }

    private function mayResend(User $user): bool
    {
        return ! EmailDelivery::query()->where('account_user_id', $user->id)
            ->where('created_at', '>', now()->subSeconds(max(60, config('accounts.resend_seconds'))))->exists();
    }

    private function mail(User $user, string $kind, string $subject, string $message, ?string $url = null, ?string $label = null, mixed $expiresAt = null): void
    {
        app(NotificationService::class)->queue(null, null, 'account_'.$kind, $user->email, $subject,
            view('emails.account', compact('user', 'subject', 'message', 'url', 'label'))->render(),
            expiresAt: $expiresAt, account: $user);
    }
}
