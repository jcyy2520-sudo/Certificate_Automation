<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdministrator
{
    /**
     * Re-check mutable account authorization on every administrator request.
     *
     * The session guard proves who the user is, but an account may have been
     * disabled or demoted after that session was issued. Treat that change as
     * an immediate revocation rather than waiting for the session to expire.
     */
    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        $user = $request->user();

        if ($user?->isAdministrator()) {
            return $next($request);
        }

        if ($user) {
            Auth::logout();
        }

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login')
            ->withErrors(['email' => 'This account is not authorized for administrator access.']);
    }
}
