<?php

use App\Http\Middleware\EnsureApprovedAccount;
use App\Http\Middleware\FrontendNavigation;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Contracts\Session\Middleware\AuthenticatesSessions;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Reject forged Host headers in non-local environments. Only the exact
        // APP_URL host and explicitly listed aliases are accepted; subdomains
        // must be opted into individually with APP_TRUSTED_HOSTS.
        $middleware->trustHosts(
            at: function (): array {
                $hosts = [
                    parse_url((string) config('app.url'), PHP_URL_HOST),
                    ...config('app.trusted_hosts', []),
                ];

                return array_values(array_map(
                    fn (string $host): string => '^'.preg_quote($host, '/').'$',
                    array_unique(array_filter($hosts)),
                ));
            },
            subdomains: false,
        );

        $middleware->append(SecurityHeaders::class);
        $middleware->append(FrontendNavigation::class);
        $middleware->prependToPriorityList(
            AuthenticatesSessions::class,
            EnsureApprovedAccount::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontFlash(['token', 'webinar_password', 'webinar_password_confirmation']);
    })->create();
