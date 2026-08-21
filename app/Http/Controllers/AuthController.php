<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    /**
     * A real bcrypt hash keeps an unknown email on the same password-check path
     * as a known account, reducing account enumeration through response timing.
     */
    private const DUMMY_PASSWORD_HASH = '$2y$12$xkz9cytSCY1ZVbDVmIG8SuVG2GUAiz8.2vkCOffCpeChJCfhE.iNu';

    public function create(): View
    {
        return view('auth.login');
    }

    public function store(Request $request, AuditService $audit): RedirectResponse
    {
        // A new password attempt always cancels any older half-authenticated flow.
        TwoFactorChallengeController::clearPending($request);

        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:254'],
            'password' => ['required', 'string', 'max:1024'],
            'remember' => ['nullable', 'boolean'],
        ]);

        $email = Str::lower(trim($credentials['email']));
        $remember = config('security.allow_admin_remember_me', false) && $request->boolean('remember');
        $user = User::query()->where('email_normalized', $email)->first();
        $passwordMatches = Hash::check(
            $credentials['password'],
            $user?->password ?? self::DUMMY_PASSWORD_HASH,
        );

        if (! $user || ! $passwordMatches || ! $user->isAdministrator()) {
            $audit->record($request, 'administrator.login_failed', $user);

            return back()->withErrors(['email' => 'The supplied credentials are incorrect or the account is inactive.'])->onlyInput('email');
        }

        if (Hash::needsRehash($user->password)) {
            $user->forceFill(['password' => Hash::make($credentials['password'])])->save();
        }

        // The password alone never establishes the session when a second factor is confirmed.
        if ($user->two_factor_confirmed_at) {
            // Rotate before storing the half-authenticated identity so a fixed or
            // previously observed session ID cannot be used to submit factor two.
            $request->session()->regenerate();
            $request->session()->put(TwoFactorChallengeController::PENDING_USER, $user->id);
            $request->session()->put(TwoFactorChallengeController::PENDING_REMEMBER, $remember);
            $request->session()->put(TwoFactorChallengeController::PENDING_AT, now()->timestamp);

            return redirect()->route('two-factor.challenge');
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();
        $request->session()->passwordConfirmed();
        $user->forceFill(['last_login_at' => now()])->save();
        $audit->record($request, 'administrator.login', $user);

        return redirect()->intended(route('admin.dashboard'));
    }

    public function destroy(Request $request, AuditService $audit): RedirectResponse
    {
        $audit->record($request, 'administrator.logout', $request->user());
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
