<?php

namespace App\Http\Controllers;

use App\Http\Middleware\EnsureApprovedAccount;
use App\Models\User;
use App\Services\AdminDeviceService;
use App\Services\AuditService;
use App\Services\TwoFactorService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TwoFactorChallengeController extends Controller
{
    /** Session keys carrying the half-authenticated login between password and second factor. */
    public const PENDING_USER = 'two_factor.login_id';

    public const PENDING_REMEMBER = 'two_factor.login_remember';

    public const PENDING_AT = 'two_factor.login_started_at';

    public const PENDING_CREDENTIAL = 'two_factor.login_credential';

    public static function credentialBinding(User $user): string
    {
        return hash_hmac('sha256', 'pending-login'."\0".$user->getAuthPassword()."\0".$user->auth_version, (string) config('app.key'));
    }

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
        // Hold the credential row through factor consumption and authentication.
        // A password reset or suspension cannot race the first-factor check.
        $result = DB::transaction(function () use ($request, $audit): RedirectResponse|ValidationException {
            $user = $this->pendingUser($request, lock: true);

            if (! $user) {
                return redirect()->route('login')->withErrors(['email' => 'Your sign-in attempt expired. Please start again.']);
            }

            $validator = Validator::make($request->all(), [
                'code' => ['nullable', 'string', 'regex:/^\d{6}$/', 'required_without:recovery_code', 'prohibits:recovery_code'],
                'recovery_code' => ['nullable', 'string', 'regex:/^[A-Za-z0-9]{5}-[A-Za-z0-9]{5}$/', 'required_without:code', 'prohibits:code'],
                'trust_device' => ['nullable', 'boolean'],
            ]);

            if ($validator->fails()) {
                $audit->record($request, 'administrator.two_factor_failed', $user);
                $this->forgetSubmittedFactors($request);

                return new ValidationException($validator);
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

                return ValidationException::withMessages([
                    'code' => 'That authentication code is not valid. Try again or use a recovery code.',
                ]);
            }

            $remember = (bool) $request->session()->pull(self::PENDING_REMEMBER, false);
            self::clearPending($request);

            Auth::login($user, $remember);
            $request->session()->regenerate();
            $request->session()->put(EnsureApprovedAccount::SESSION_VERSION, $user->auth_version);
            // Seed auth.session from the exact credential just accepted, rather
            // than adopting a changed password on the first protected request.
            $request->session()->put('password_hash_'.Auth::getDefaultDriver(), Auth::guard()->hashPasswordForCookie($user->getAuthPassword()));
            $request->session()->passwordConfirmed();
            $devices = app(AdminDeviceService::class);
            $devices->acceptSession($request, $user);
            if (filled($recoveryCode)) {
                $devices->revokeAll($user);
            } elseif ($request->boolean('trust_device')) {
                $devices->remember($request, $user);
            }
            $user->forceFill(['last_login_at' => now()])->save();
            $audit->record($request, 'administrator.login', $user);

            return redirect()->intended(route('admin.dashboard'));
        });
        // Commit the failed-attempt audit before Laravel handles validation.
        if ($result instanceof ValidationException) {
            throw $result;
        }

        return $result;
    }

    private function pendingUser(Request $request, bool $lock = false): ?User
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
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->where('is_active', true)
            ->whereNotNull('two_factor_secret')
            ->whereNotNull('two_factor_confirmed_at')
            ->find((int) $id);

        $binding = $request->session()->get(self::PENDING_CREDENTIAL);
        if ($user && (! $user->canAccessPlatform()
            || ! is_string($binding)
            || ! hash_equals(self::credentialBinding($user), $binding))) {
            $user = null;
        }

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
            self::PENDING_CREDENTIAL,
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
