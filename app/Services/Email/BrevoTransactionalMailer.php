<?php

namespace App\Services\Email;

use App\Contracts\TransactionalMailer;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class BrevoTransactionalMailer implements TransactionalMailer
{
    public function send(
        string $recipient,
        string $subject,
        string $html,
        array $attachments = [],
        ?string $idempotencyKey = null,
    ): ?string {
        $configuration = config('services.brevo');

        if (blank($configuration['key'] ?? null)) {
            throw new RuntimeException('BREVO_API_KEY is not configured.');
        }

        $baseUrl = rtrim((string) ($configuration['base_url'] ?? ''), '/');

        if (! self::isApprovedBaseUrl($baseUrl)) {
            throw new RuntimeException('BREVO_API_URL is not the approved Brevo API origin.');
        }

        $payload = [
            'sender' => ['email' => $configuration['from_address'], 'name' => $configuration['from_name']],
            'to' => [['email' => $recipient]],
            'subject' => $subject,
            'htmlContent' => $html,
        ];

        if ($attachments !== []) {
            $payload['attachment'] = $attachments;
        }

        // Brevo suppresses duplicate transactional requests carrying the same
        // key. The durable delivery UUID is reused for HTTP and queue retries.
        if (filled($idempotencyKey)) {
            $payload['headers'] = ['idempotencyKey' => $idempotencyKey];
        }

        $response = Http::acceptJson()
            ->withHeader('api-key', $configuration['key'])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(5)
            ->timeout(10)
            // Retry only transient failures. A 4xx (notably the 400
            // duplicate_parameter idempotency response) is terminal and must
            // reach the handling below, so `throw: false` returns the final
            // response instead of raising Laravel's RequestException — whose
            // message would echo the recipient and body into failed_jobs.
            ->retry(2, 500, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && (bool) $exception->response?->serverError());
            }, throw: false)
            ->post($baseUrl.'/smtp/email', $payload);

        // Brevo returns duplicate_parameter when an earlier ambiguous request
        // with this UUID was already accepted. Treat that response as terminal
        // success so the queue does not retry beyond the idempotency TTL.
        if ($response->status() === 400 && $response->json('code') === 'duplicate_parameter') {
            return 'idempotent-duplicate:'.$idempotencyKey;
        }

        if (! $response->successful()) {
            $providerCode = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $response->json('code'));
            $suffix = filled($providerCode) ? " ({$providerCode})" : '';

            // Do not include the provider body or default HTTP exception text;
            // either may echo a recipient or message content into failed_jobs.
            throw new RuntimeException("Brevo request failed with HTTP {$response->status()}{$suffix}.");
        }

        return $response->json('messageId');
    }

    public static function isApprovedBaseUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && ($parts['scheme'] ?? null) === 'https'
            && strtolower((string) ($parts['host'] ?? '')) === 'api.brevo.com'
            && ! isset($parts['user'])
            && ! isset($parts['pass'])
            && ! isset($parts['query'])
            && ! isset($parts['fragment'])
            && ! isset($parts['port'])
            && rtrim((string) ($parts['path'] ?? ''), '/') === '/v3';
    }
}
