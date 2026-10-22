<?php

namespace App\Services\Email;

use App\Models\EmailDelivery;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class EmailSendBudget
{
    /** Called after lifecycle validation, in the durable attempt transaction. */
    public function reserve(EmailDelivery $delivery): void
    {
        if (Cache::has('transactional-email-cooldown')) {
            throw new EmailBudgetDeferredException('Provider cooldown; waiting before sending.', 60);
        }
        if (Cache::has('transactional-email-paused')) {
            throw new EmailBudgetDeferredException('Provider credits exhausted; waiting for the next quota check.', 3600);
        }
        // Local captures consume no provider credits. Each real delivery holds
        // one reservation per UTC day, including account and participant mail.
        if ($delivery->provider === 'brevo') {
            $day = now('UTC')->toDateString();
            if ($delivery->budget_reserved_on !== $day) {
                DB::table('email_send_budgets')->insertOrIgnore(['day' => $day, 'reserved' => 0]);
                $budget = DB::table('email_send_budgets')->where('day', $day)->lockForUpdate()->first();
                $legacy = $this->legacyUsage();
                if ((int) $budget->reserved + $legacy >= $this->limit()) {
                    throw new EmailBudgetDeferredException('Daily sending allowance reached; queued until the next UTC day.', $this->untilTomorrow());
                }
                if (config('accounts.verify_provider_quota')) {
                    $remaining = $this->providerRemaining();
                    // Unacknowledged reservations may still consume credits.
                    // Parallel workers cannot spend that space twice.
                    $outstanding = EmailDelivery::query()->where('provider', 'brevo')
                        ->where('budget_reserved_on', $day)->whereNull('sent_at')->count();
                    if ($remaining <= $outstanding) {
                        throw new EmailBudgetDeferredException('Provider daily allowance is unavailable; queued for another quota check.', 3600);
                    }
                    DB::table('email_send_budgets')->where('day', $day)->update([
                        'provider_remaining' => $remaining, 'provider_checked_at' => now(),
                    ]);
                }
                DB::table('email_send_budgets')->where('day', $day)->increment('reserved');
                $delivery->budget_reserved_on = $day;
            }
        }
        try {
            Cache::lock('transactional-email-budget', 5)->block(3, function (): void {
                $minute = max(1, (int) config('accounts.email_per_minute', 30));
                if (RateLimiter::tooManyAttempts('email-budget-minute', $minute)) {
                    throw new EmailBudgetDeferredException('Minute sending allowance reached; queued for later.', 60);
                }
                RateLimiter::hit('email-budget-minute', 60);
            });
        } catch (LockTimeoutException) {
            throw new EmailBudgetDeferredException('Another worker is reserving sending capacity; queued for later.', 60);
        }
    }

    public function limit(): int
    {
        $configured = (int) config('accounts.email_per_day', 300);

        return min(300, $configured > 0 ? $configured : 300);
    }

    public function summary(): array
    {
        $day = now('UTC')->toDateString();
        $budget = DB::table('email_send_budgets')->where('day', $day)->first();
        $legacy = $this->legacyUsage();
        $reserved = (int) ($budget?->reserved ?? 0) + $legacy;

        return [
            'day' => $day, 'limit' => $this->limit(), 'reserved' => $reserved,
            'remaining' => max(0, $this->limit() - $reserved),
            'accepted' => EmailDelivery::query()->where('provider', 'brevo')->where('status', 'sent')
                ->where('sent_at', '>=', now('UTC')->startOfDay())->count(),
            'provider_remaining' => $budget?->provider_remaining,
            'provider_checked_at' => $budget?->provider_checked_at,
        ];
    }

    private function legacyUsage(): int
    {
        $start = now('UTC')->startOfDay();
        $end = $start->copy()->addDay();

        // Older accepted deliveries may predate the durable attempt marker.
        // Their acceptance timestamp still consumes today's shared allowance.
        return EmailDelivery::query()->where('provider', 'brevo')->whereNull('budget_reserved_on')
            ->where(fn ($query) => $query
                ->where(fn ($query) => $query->where('first_attempted_at', '>=', $start)->where('first_attempted_at', '<', $end))
                ->orWhere(fn ($query) => $query->where('sent_at', '>=', $start)->where('sent_at', '<', $end)))
            ->count();
    }

    private function untilTomorrow(): int
    {
        return max(60, (int) now('UTC')->diffInSeconds(now('UTC')->addDay()->startOfDay()) + 5);
    }

    private function providerRemaining(): int
    {
        $base = rtrim((string) config('services.brevo.base_url'), '/');
        if (! BrevoTransactionalMailer::isApprovedBaseUrl($base) || blank(config('services.brevo.key'))) {
            throw new EmailBudgetDeferredException('Provider quota configuration needs attention; no email attempted.', 300);
        }
        try {
            $response = Http::acceptJson()->withHeader('api-key', config('services.brevo.key'))
                ->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(5)->get($base.'/account');
            $plan = collect($response->json('plan', []))->first(fn ($plan) => ($plan['type'] ?? '') === 'free'
                && ($plan['creditsType'] ?? '') === 'sendLimit');
            if ($response->successful() && is_array($plan) && isset($plan['credits']) && is_numeric($plan['credits'])
                && (float) $plan['credits'] >= 0) {
                return (int) $plan['credits'];
            }
        } catch (Throwable) {
            // Never expose provider responses or credentials in queued errors.
        }
        throw new EmailBudgetDeferredException('Could not verify the free provider allowance; no email attempted.', 300);
    }
}
