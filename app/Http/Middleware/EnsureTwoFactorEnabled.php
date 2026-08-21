<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTwoFactorEnabled
{
    /** @var list<string> */
    private const EXEMPT_ROUTES = [
        'admin.logout',
        'admin.two-factor.show',
        'admin.two-factor.enable',
        'admin.two-factor.recovery-codes',
        'admin.two-factor.disable',
    ];

    public function handle(Request $request, Closure $next): Response|RedirectResponse
    {
        if (! config('security.require_admin_two_factor', true)
            || $request->user()?->two_factor_confirmed_at
            || $request->routeIs(...self::EXEMPT_ROUTES)) {
            return $next($request);
        }

        return redirect()->route('admin.two-factor.show')->with(
            'error',
            'Set up two-factor authentication before accessing participant or webinar data.',
        );
    }
}
