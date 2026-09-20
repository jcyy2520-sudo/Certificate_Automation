<?php

namespace App\Providers;

use App\Contracts\TransactionalMailer;
use App\Services\Email\BrevoTransactionalMailer;
use App\Services\Email\LogTransactionalMailer;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use LogicException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(TransactionalMailer::class, function ($app) {
            return match (config('webinar.email.provider')) {
                'brevo' => $app->make(BrevoTransactionalMailer::class),
                'log' => $app->make(LogTransactionalMailer::class),
                default => throw new LogicException('Unsupported transactional email provider.'),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Resolve this from cached configuration, not directly from .env. The
        // middleware is part of Laravel's default global stack; this sets its
        // exact IP/CIDR allowlist before the first request is handled.
        if (config('security.behind_proxy') && config('app.trusted_proxies') !== []) {
            TrustProxies::at(config('app.trusted_proxies'));
        }

        RateLimiter::for('public-form-view', fn (Request $request): Limit => Limit::perMinute(
            (int) config('security.rate_limits.public_form_view_ip_per_minute'),
        )->by('public-form-view:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))));

        RateLimiter::for('public-form-submit', function (Request $request): array {
            $key = (string) config('app.key');
            $formKey = hash_hmac('sha256', (string) $request->route('token'), $key);
            $emailKey = hash_hmac('sha256', Str::lower(trim((string) $request->input('email'))), $key);
            $ipKey = hash_hmac('sha256', (string) $request->ip(), $key);

            return [
                Limit::perMinute((int) config('security.rate_limits.public_form_submit_identity_per_minute'))->by("public-form-submit:identity:{$formKey}:{$emailKey}"),
                Limit::perMinute((int) config('security.rate_limits.public_form_submit_ip_per_minute'))->by("public-form-submit:ip:{$ipKey}"),
                Limit::perMinute((int) config('security.rate_limits.public_form_submit_form_per_minute'))->by("public-form-submit:form:{$formKey}"),
            ];
        });

        RateLimiter::for('public-form-thanks', fn (Request $request): Limit => Limit::perMinute(
            (int) config('security.rate_limits.public_form_thanks_ip_per_minute'),
        )->by('public-form-thanks:'.hash_hmac('sha256', (string) $request->ip(), (string) config('app.key'))));

        RateLimiter::for('admin-login', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));
            $emailKey = hash('sha256', $email);
            $ipKey = hash('sha256', (string) $request->ip());

            return [
                Limit::perMinute(5)->by("admin-login:pair:{$emailKey}:{$ipKey}"),
                Limit::perMinute(20)->by("admin-login:email:{$emailKey}"),
                Limit::perMinute(50)->by("admin-login:ip:{$ipKey}"),
            ];
        });

        RateLimiter::for('admin-two-factor-challenge', function (Request $request): array {
            $pendingUserKey = hash('sha256', (string) $request->session()->get('two_factor.login_id', 'missing'));
            $ipKey = hash('sha256', (string) $request->ip());

            return [
                Limit::perMinute(5)->by("admin-2fa:pair:{$pendingUserKey}:{$ipKey}"),
                Limit::perMinute(15)->by("admin-2fa:user:{$pendingUserKey}"),
                Limit::perMinute(50)->by("admin-2fa:ip:{$ipKey}"),
            ];
        });

        RateLimiter::for('admin-sensitive', function (Request $request): array {
            $userKey = hash('sha256', (string) ($request->user()?->getAuthIdentifier() ?? 'guest'));
            $ipKey = hash('sha256', (string) $request->ip());

            return [
                Limit::perMinute(5)->by("admin-sensitive:pair:{$userKey}:{$ipKey}"),
                Limit::perMinute(15)->by("admin-sensitive:user:{$userKey}"),
            ];
        });

        RateLimiter::for('participant-access-request', function (Request $request): array {
            $email = Str::lower(trim((string) $request->input('email')));
            $key = (string) config('app.key');
            $emailKey = hash_hmac('sha256', $email, $key);
            $ipKey = hash_hmac('sha256', (string) $request->ip(), $key);
            $formKey = hash_hmac('sha256', (string) $request->route('token'), $key);

            return [
                Limit::perMinute(3)->by("participant-access:pair:{$emailKey}:{$ipKey}"),
                Limit::perMinute(10)->by("participant-access:email:{$emailKey}"),
                Limit::perHour((int) config('security.rate_limits.participant_access_email_per_hour'))->by("participant-access:email-hour:{$emailKey}"),
                Limit::perDay((int) config('security.rate_limits.participant_access_email_per_day'))->by("participant-access:email-day:{$emailKey}"),
                Limit::perMinute((int) config('security.rate_limits.participant_access_ip_per_minute'))->by("participant-access:ip:{$ipKey}"),
                Limit::perMinute((int) config('security.rate_limits.participant_access_global_per_minute'))->by('participant-access:global-minute'),
                Limit::perHour((int) config('security.rate_limits.participant_access_global_per_hour'))->by('participant-access:global-hour'),
                Limit::perDay((int) config('security.rate_limits.participant_access_global_per_day'))->by('participant-access:global-day'),
                Limit::perHour((int) config('security.rate_limits.participant_access_form_per_hour'))->by("participant-access:form-hour:{$formKey}"),
            ];
        });

        RateLimiter::for('participant-access-confirm', function (Request $request): array {
            $key = (string) config('app.key');
            $ipKey = hash_hmac('sha256', (string) $request->ip(), $key);

            return [
                Limit::perMinute(10)->by('participant-confirm:session:'.$request->session()->getId()),
                Limit::perMinute((int) config('security.rate_limits.participant_access_confirm_ip_per_minute'))->by("participant-confirm:ip:{$ipKey}"),
            ];
        });
    }
}
