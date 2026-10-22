<?php

namespace App\Services\Email;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;

class EmailSendBudget
{
    public function reserve(): void
    {
        Cache::lock('transactional-email-budget', 5)->block(3, function (): void {
            if (Cache::has('transactional-email-cooldown')) {
                throw new \OverflowException('Provider cooldown is active.');
            }
            if (Cache::has('transactional-email-paused')) {
                throw new PermanentDeliveryException('Email sending is paused after provider quota exhaustion. Check provider credits, then retry failed deliveries after the pause ends.');
            }
            $minute = max(1, (int) config('accounts.email_per_minute', 30));
            $day = max(0, (int) config('accounts.email_per_day', 0));
            if ($day > 0 && RateLimiter::tooManyAttempts('email-budget-day', $day)) {
                throw new PermanentDeliveryException('The configured daily email budget is exhausted. Retry after the daily budget resets.');
            }
            // Queue workers wait outside any provider call; callers can defer
            // unattempted work without consuming its transport retry budget.
            if (RateLimiter::tooManyAttempts('email-budget-minute', $minute)) {
                throw new \OverflowException('Email minute budget reached.');
            }
            RateLimiter::hit('email-budget-minute', 60);
            if ($day > 0) {
                RateLimiter::hit('email-budget-day', 86400);
            }
        });
    }
}
