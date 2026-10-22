<?php

namespace App\Services\Email;

use App\Models\EmailDelivery;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class BrevoDeliveryStatus
{
    /** Read provider events only. This method never sends or retries an email. */
    public function refresh(EmailDelivery $delivery): void
    {
        if ($delivery->provider !== 'brevo' || $delivery->status !== 'sent' || blank($delivery->provider_message_id)
            || str_starts_with($delivery->provider_message_id, 'idempotent-duplicate:')) {
            throw ValidationException::withMessages(['delivery' => 'This message does not have a provider message ID available for a delivery check.']);
        }
        if ($delivery->delivery_checked_at?->gt(now()->subSeconds(30))) {
            return;
        }
        $base = rtrim((string) config('services.brevo.base_url'), '/');
        if (! BrevoTransactionalMailer::isApprovedBaseUrl($base) || blank(config('services.brevo.key'))) {
            throw ValidationException::withMessages(['delivery' => 'The email provider is not configured for delivery checks.']);
        }
        try {
            $response = Http::acceptJson()->withHeader('api-key', config('services.brevo.key'))
                ->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(10)
                ->get($base.'/smtp/statistics/events', ['messageId' => $delivery->provider_message_id, 'limit' => 50, 'sort' => 'desc', 'days' => 30]);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['delivery' => 'The provider status check is temporarily unavailable. No email was sent or retried.']);
        }
        if (! $response->successful()) {
            throw ValidationException::withMessages(['delivery' => 'The provider status check was not accepted. Try later or review Brevo transactional logs. No email was sent or retried.']);
        }
        $events = $response->json('events', []);
        if (! is_array($events)) {
            throw ValidationException::withMessages(['delivery' => 'The provider returned an unexpected status response.']);
        }
        $outcome = null;
        foreach (array_slice($events, 0, 50) as $event) {
            // Bind BOTH message and mailbox. Never retain provider response
            // bodies, recipient details, tracking URLs, raw errors or content.
            if (! is_array($event) || ($event['messageId'] ?? null) !== $delivery->provider_message_id
                || strcasecmp((string) ($event['email'] ?? ''), (string) $delivery->recipient_email) !== 0) {
                continue;
            }
            $mapped = match ($event['event'] ?? '') {
                'delivered' => 'delivered', 'hardBounce', 'hardBounces', 'hard_bounce' => 'hard_bounce',
                'blocked' => 'blocked', 'invalid' => 'invalid', 'error' => 'error',
                'softBounce', 'softBounces', 'soft_bounce' => 'soft_bounce', 'deferred' => 'deferred',
                default => null,
            };
            if ($mapped) {
                $outcome = $mapped;
                break;
            }
        }
        $delivery->update(['delivery_outcome' => $outcome ?? $delivery->delivery_outcome, 'delivery_checked_at' => now()]);
    }
}
