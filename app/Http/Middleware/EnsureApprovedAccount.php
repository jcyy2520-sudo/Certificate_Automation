<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureApprovedAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();
        if (! $user?->canAccessPlatform()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'This account is not available for sign in. Contact the platform administrator.']);
        }
        Auth::setUser($user);

        return $next($request);
    }
}
