<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureRecentPassword
{
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

        // A GET can be resumed exactly. Mutating request bodies are deliberately
        // not stored or replayed; after confirmation the administrator returns
        // to a safe internal page and must submit the action again.
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
