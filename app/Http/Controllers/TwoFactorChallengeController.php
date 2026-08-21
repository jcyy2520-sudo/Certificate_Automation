<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    /** Session keys carrying the half-authenticated login between password and second factor. */
    public const PENDING_USER = 'two_factor.login_id';

    public const PENDING_REMEMBER = 'two_factor.login_remember';

    public const PENDING_AT = 'two_factor.login_started_at';

    private const PENDING_LIFETIME_SECONDS = 300;

    public function __construct(private TwoFactorService $twoFactor) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (! $this->pendingUser($request)) {
            return redirect()->route('login');
        }

        return view('auth.two-factor.challenge');
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        $user = $this->pendingUser($request);

        if (! $user) {
            return redirect()->route('login')->withErrors(['email' => 'Your sign-in attempt expired. Please start again.']);
        }

        $validator = Validator::make($request->all(), [
            'code' => ['nullable', 'string', 'regex:/^\d{6}$/', 'required_without:recovery_code', 'prohibits:recovery_code'],
            'recovery_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{5}-[A-Za-z0-9]{5}$/', 'required_without:code', 'prohibits:code'],
        ]);

        if ($validator->fails()) {
            $audit->record($request, 'administrator.two_factor_failed', $user);
            $this->forgetSubmittedFactors($request);

            throw new ValidationException($validator);
        }

        $code = $request->string('code')->toString();
        $recoveryCode = $request->string('recovery_code')->toString();

        $passed = match (true) {
            filled($code) => $this->twoFactor->consumeTotp($user, $code),
            filled($recoveryCode) => $this->twoFactor->consumeRecoveryCode($user, $recoveryCode),
            default => false,
        };

        if (! $passed) {
            $audit->record($request, 'administrator.two_factor_failed', $user);
            $this->forgetSubmittedFactors($request);

            throw ValidationException::withMessages([
                'code' => 'That authentication code is not valid. Try again or use a recovery code.',
            ]);
        }

        $remember = (bool) $request->session()->pull(self::PENDING_REMEMBER, false);
        self::clearPending($request);

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record($request, 'administrator.login', $user);

        return redirect()->intended(route('admin.dashboard'));
    }

    private function pendingUser(Request $request): ?User
    {
        $id = $request->session()->get(self::PENDING_USER);
        $startedAt = $request->session()->get(self::PENDING_AT);
        $now = now()->timestamp;

        if (! is_numeric($id)
            || ! is_numeric($startedAt)
            || (int) $startedAt > $now
            || $now - (int) $startedAt > self::PENDING_LIFETIME_SECONDS) {
            self::clearPending($request);

            return null;
        }

        $user = User::query()
            ->where('role', User::ADMINISTRATOR_ROLE)
            ->where('is_active', true)
            ->whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_confirmed_at')
            ->find((int) $id);

        if (! $user) {
            self::clearPending($request);
        }

        return $user;
    }

    public static function clearPending(Request $request): void
    {
        $request->session()->forget([
            self::PENDING_USER,
            self::PENDING_REMEMBER,
            self::PENDING_AT,
        ]);
    }

    private function forgetSubmittedFactors(Request $request): void
    {
        // Authentication factors must never be flashed back into server-side
        // session storage as "old input" after a validation failure.
        $request->request->remove('code');
        $request->request->remove('recovery_code');
    }
}
