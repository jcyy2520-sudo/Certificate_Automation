<?php

namespace App\Http\Middleware;

use App\Services\PendingSensitiveAction;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecentPassword
{
    public function __construct(private readonly PendingSensitiveAction $pending) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! config('security.require_sensitive_action_password_confirmation', true)) {
            return $next($request);
        }

        $confirmedAt = (int) $request->session()->get('auth.password_confirmed_at', 0);
        $timeout = max(60, min(3600, (int) config('security.password_confirmation_timeout', 900)));

        if (now()->timestamp - $confirmedAt <= $timeout) {
            return $next($request);
        }

        // Remember what the administrator was trying to do. A GET can be
        // resumed exactly through the intended URL; a mutating request is
        // remembered so that proving the password completes the action instead
        // of silently discarding it. The administrator then submits the action
        // again only when it genuinely cannot be replayed.
        $this->pending->remember($request);

        $request->session()->put(
            'url.intended',
            $request->isMethodSafe() ? $request->fullUrl() : $this->safeReturnUrl($request),
        );

        return redirect()->route('admin.password.confirm');
    }

    private function safeReturnUrl(Request $request): string
    {
        $referer = (string) $request->headers->get('referer');
        $expectedHost = parse_url((string) config('app.url'), PHP_URL_HOST);
        $host = parse_url($referer, PHP_URL_HOST);
        $path = parse_url($referer, PHP_URL_PATH);

        if (filled($referer)
            && is_string($host)
            && is_string($path)
            && hash_equals((string) $expectedHost, $host)
            && str_starts_with($path, '/admin')) {
            return $referer;
        }

        return route('admin.dashboard');
    }
}
