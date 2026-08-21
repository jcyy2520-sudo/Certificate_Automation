<?php

namespace App\Http\Controllers;

use App\Services\AuditService;
use App\Services\TwoFactorService;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use JsonException;

class TwoFactorController extends Controller
{
    /** Session key holding the pending secret between the setup screen and its confirmation. */
    private const PENDING_SECRET = 'two_factor.pending_secret';

    /** The administrator who owns the pending setup secret. */
    private const PENDING_OWNER = 'two_factor.pending_owner';

    /** Session key holding the relatively expensive QR render for that secret. */
    private const PENDING_QR_CODE = 'two_factor.pending_qr_code';

    /** Flash key holding an encrypted, one-time display of newly generated codes. */
    private const RECOVERY_CODES = 'two_factor.recovery_codes';

    public function __construct(private TwoFactorService $twoFactor) {}

    public function show(Request $request): View
    {
        $user = $request->user();
        $secret = null;
        $qrCode = null;

        if (! $user->two_factor_confirmed_at) {
            if ((int) $request->session()->get(self::PENDING_OWNER) !== (int) $user->getKey()) {
                $this->clearPendingSetup($request);
            }

            $secret = $this->pendingSecret($request);

            if (! $secret) {
                $secret = $this->twoFactor->generateSecret();
                $request->session()->put([
                    self::PENDING_SECRET => Crypt::encryptString($secret),
                    self::PENDING_OWNER => $user->getKey(),
                ]);
                $request->session()->forget(self::PENDING_QR_CODE);
            }

            // PNG generation is the expensive part of this otherwise tiny page.
            // The secret is stable until confirmation, so its QR code is too. Both
            // contain the TOTP secret and are encrypted in server-side sessions.
            $qrCode = $this->pendingQrCode($request);

            if (! $request->session()->has(self::PENDING_QR_CODE)) {
                $qrCode = $this->twoFactor->qrCodeDataUri($user, $secret) ?? false;
                $request->session()->put(
                    self::PENDING_QR_CODE,
                    $qrCode ? Crypt::encryptString($qrCode) : false,
                );
            }
        }

        return view('auth.two-factor.settings', [
            'user' => $user,
            'secret' => $secret,
            'qrCode' => $qrCode,
            'recoveryCodes' => $this->recoveryCodes($request),
            'remainingRecoveryCodes' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }

    public function enable(Request $request, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        abort_if($user->two_factor_confirmed_at, 409, 'Two-factor authentication is already enabled.');

        try {
            $request->validate([
                'code' => ['required', 'string', 'regex:/^\d{6}$/'],
                'password' => ['required', 'string', 'max:1024', 'current_password'],
            ]);
        } catch (ValidationException $exception) {
            $request->request->remove('code');

            throw $exception;
        }
        $secret = $this->pendingSecret($request);

        $matchedStep = $secret
            ? $this->twoFactor->matchingStep($secret, $request->string('code')->toString())
            : null;

        if (! $secret || $matchedStep === null) {
            $audit->record($request, 'administrator.two_factor_enable_failed', $user);
            $request->request->remove('code');

            throw ValidationException::withMessages(['code' => 'That code did not match. Check your authenticator app and try again.']);
        }

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $this->twoFactor->hashRecoveryCodes($codes),
            'two_factor_confirmed_at' => now(),
            'two_factor_last_used_step' => $matchedStep,
        ])->save();

        // Newly enabled MFA must protect immediately, including against older
        // authenticated sessions and remember-me cookies on other devices.
        Auth::logoutOtherDevices($request->string('password')->toString());
        $request->session()->regenerate();
        $this->clearPendingSetup($request);
        $audit->record($request, 'administrator.two_factor_enabled', $user);

        return redirect()->route('admin.two-factor.show')
            ->with(self::RECOVERY_CODES, $this->encryptRecoveryCodes($codes))
            ->with('success', 'Two-factor authentication is on. Save your recovery codes now - they are not shown again.');
    }

    public function regenerateRecoveryCodes(Request $request, AuditService $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user->two_factor_confirmed_at, 403);
        $request->validate([
            'password' => ['required', 'string', 'max:1024', 'current_password'],
        ]);

        $codes = $this->twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $this->twoFactor->hashRecoveryCodes($codes)])->save();
        Auth::logoutOtherDevices($request->string('password')->toString());
        $request->session()->regenerate();
        $audit->record($request, 'administrator.two_factor_recovery_codes_regenerated', $user);

        return redirect()->route('admin.two-factor.show')
            ->with(self::RECOVERY_CODES, $this->encryptRecoveryCodes($codes))
            ->with('success', 'New recovery codes generated. The previous set no longer works.');
    }

    public function disable(Request $request, AuditService $audit): RedirectResponse
    {
        $request->validate(['password' => ['required', 'string', 'max:1024', 'current_password']]);
        $user = $request->user();

        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_used_step' => null,
        ])->save();
        Auth::logoutOtherDevices($request->string('password')->toString());
        $request->session()->regenerate();
        $this->clearPendingSetup($request);
        $request->session()->forget(self::RECOVERY_CODES);
        $audit->record($request, 'administrator.two_factor_disabled', $user);

        return redirect()->route('admin.two-factor.show')->with('success', 'Two-factor authentication is off.');
    }

    private function pendingSecret(Request $request): ?string
    {
        $encrypted = $request->session()->get(self::PENDING_SECRET);

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            $this->clearPendingSetup($request);

            return null;
        }
    }

    private function pendingQrCode(Request $request): string|false|null
    {
        $encrypted = $request->session()->get(self::PENDING_QR_CODE);

        if ($encrypted === false) {
            return false;
        }

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            return Crypt::decryptString($encrypted);
        } catch (DecryptException) {
            $request->session()->forget(self::PENDING_QR_CODE);

            return null;
        }
    }

    private function recoveryCodes(Request $request): ?array
    {
        $encrypted = $request->session()->get(self::RECOVERY_CODES);

        if (! is_string($encrypted)) {
            return null;
        }

        try {
            $codes = json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR);

            return is_array($codes) ? $codes : null;
        } catch (DecryptException|JsonException) {
            $request->session()->forget(self::RECOVERY_CODES);

            return null;
        }
    }

    /** @throws JsonException */
    private function encryptRecoveryCodes(array $codes): string
    {
        return Crypt::encryptString(json_encode(array_values($codes), JSON_THROW_ON_ERROR));
    }

    private function clearPendingSetup(Request $request): void
    {
        $request->session()->forget([
            self::PENDING_SECRET,
            self::PENDING_OWNER,
            self::PENDING_QR_CODE,
        ]);
    }
}
