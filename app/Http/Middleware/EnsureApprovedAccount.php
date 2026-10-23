<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedAccount
{
    public const SESSION_VERSION = 'auth.account_version';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();
        // Version 1 is the historical session baseline. A changed credential
        // invalidates older sessions without needing the plaintext password.
        if (! $user?->canAccessPlatform() || (int) $request->session()->get(self::SESSION_VERSION, 1) !== $user->auth_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is not available for sign in. Contact the platform administrator.']);
        }
        Auth::setUser($user);
        $request->session()->put(self::SESSION_VERSION, $user->auth_version);

        return $next($request);
    }
}
