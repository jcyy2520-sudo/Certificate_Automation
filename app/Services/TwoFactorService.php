<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorService
{
    public function __construct(
        private Google2FA $google2fa,
        private QrCodeService $qrCodes,
    ) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey();
    }

    /** The otpauth:// URI an authenticator app scans, rendered as an inline PNG. */
    public function qrCodeDataUri(User $user, string $secret): ?string
    {
        return $this->qrCodes->dataUri(
            $this->google2fa->getQRCodeUrl(config('app.name'), $user->email, $secret),
            200,
        );
    }

    public function verify(string $secret, string $code): bool
    {
        return $this->google2fa->verifyKey($secret, preg_replace('/\D/', '', $code) ?? '');
    }

    /** Return the matched TOTP time step so it can be made single-use. */
    public function matchingStep(string $secret, string $code, ?int $afterStep = null): ?int
    {
        $step = $this->google2fa->verifyKeyNewer(
            $secret,
            preg_replace('/\D/', '', $code) ?? '',
            $afterStep ?? 0,
        );

        return $step === false ? null : (int) $step;
    }

    /**
     * Verify and atomically mark a TOTP time step as used.
     *
     * This prevents a captured code being replayed during the same 30-second
     * window, including when two challenge requests arrive concurrently.
     */
    public function consumeTotp(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            $lockedUser = User::query()->lockForUpdate()->find($user->getKey());

            if (! $lockedUser?->two_factor_secret || ! $lockedUser->two_factor_confirmed_at) {
                return false;
            }

            $step = $this->matchingStep(
                $lockedUser->two_factor_secret,
                $code,
                $lockedUser->two_factor_last_used_step,
            );

            if ($step === null) {
                return false;
            }

            $lockedUser->forceFill(['two_factor_last_used_step' => $step])->save();

            return true;
        });
    }

    /** Ten single-use codes, stored hashed so a database copy cannot be replayed. */
    public function generateRecoveryCodes(): array
    {
        return collect(range(1, 10))
            ->map(fn () => Str::upper(Str::random(5).'-'.Str::random(5)))
            ->all();
    }

    public function hashRecoveryCodes(array $codes): array
    {
        return array_map(fn (string $code) => Hash::make($code), $codes);
    }

    /**
     * Consume a recovery code, removing it from the user's stored set.
     * Returns false when the code does not match any remaining code.
     */
    public function consumeRecoveryCode(User $user, string $code): bool
    {
        $code = Str::upper(trim($code));

        return DB::transaction(function () use ($user, $code): bool {
            // Serialize consumers of the same set so two simultaneous requests
            // cannot both authenticate with one single-use recovery code.
            $lockedUser = User::query()->lockForUpdate()->find($user->getKey());

            if (! $lockedUser) {
                return false;
            }

            $stored = $lockedUser->two_factor_recovery_codes ?? [];

            foreach ($stored as $index => $hash) {
                if (Hash::check($code, $hash)) {
                    unset($stored[$index]);
                    $lockedUser->forceFill(['two_factor_recovery_codes' => array_values($stored)])->save();

                    return true;
                }
            }

            return false;
        });
    }
}
