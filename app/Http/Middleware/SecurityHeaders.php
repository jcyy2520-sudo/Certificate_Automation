<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // A fresh nonce is shared only with the Blade response for this request.
        // Inline event attributes are forbidden, and the few intentional script
        // blocks must present this value before a browser will execute them.
        $nonce = base64_encode(random_bytes(18));
        View::share('cspNonce', $nonce);

        $response = $next($request);
        // Dynamic pages include administrator and participant information. Do
        // not let browsers, proxies, or shared machines retain those responses.
        $response->headers->remove('ETag');
        $response->headers->remove('Last-Modified');
        $response->headers->set('Cache-Control', 'no-store, private, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', 'Thu, 01 Jan 1970 00:00:00 GMT');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('X-XSS-Protection', '0');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive, nosnippet');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');
        $response->headers->set('Cross-Origin-Resource-Policy', 'same-origin');
        $response->headers->set('Origin-Agent-Cluster', '?1');
        $response->headers->set(
            'Permissions-Policy',
            'accelerometer=(), autoplay=(), camera=(), clipboard-write=(self), display-capture=(), encrypted-media=(), fullscreen=(self), geolocation=(), gyroscope=(), magnetometer=(), microphone=(), midi=(), payment=(), picture-in-picture=(), publickey-credentials-get=(self), screen-wake-lock=(), usb=(), web-share=()',
        );

        if (config('security.content_security_policy.enabled', true)) {
            $policy = [
                "default-src 'none'",
                "base-uri 'none'",
                "connect-src 'self'",
                "font-src 'self'",
                "form-action 'self'",
                "frame-ancestors 'none'",
                "img-src 'self' data:",
                "manifest-src 'self'",
                "media-src 'self'",
                "object-src 'none'",
                "script-src 'self' 'nonce-{$nonce}'",
                "script-src-attr 'none'",
                "style-src 'self' 'unsafe-inline'",
                "frame-src 'none'",
                "trusted-types 'none'",
                "require-trusted-types-for 'script'",
                "worker-src 'self'",
            ];

            if ($request->isSecure()) {
                $policy[] = 'upgrade-insecure-requests';
            }

            $header = config('security.content_security_policy.report_only', false)
                ? 'Content-Security-Policy-Report-Only'
                : 'Content-Security-Policy';

            $response->headers->set($header, implode('; ', $policy));
        }

        if ($request->isSecure()) {
            $hsts = ['max-age='.max(0, (int) config('security.hsts.max_age', 31536000))];

            if (config('security.hsts.include_subdomains', false)) {
                $hsts[] = 'includeSubDomains';

                if (config('security.hsts.preload', false)) {
                    $hsts[] = 'preload';
                }
            }

            $response->headers->set('Strict-Transport-Security', implode('; ', $hsts));
        }

        return $response;
    }
}
