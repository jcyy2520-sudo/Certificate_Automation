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
    public function send(string $recipient, string $subject, string $html, array $attachments = [], ?string $idempotencyKey = null): ?string
    {
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
        if (filled($idempotencyKey)) {
            $payload['headers'] = ['idempotencyKey' => $idempotencyKey];
        }

        $response = Http::acceptJson()
            ->withHeader('api-key', $configuration['key'])
            ->withOptions(['allow_redirects' => false])
            ->connectTimeout(5)
            ->timeout(10)
            ->retry(2, 500, function (Throwable $exception): bool {
                return $exception instanceof ConnectionException
                    || ($exception instanceof RequestException && (bool) $exception->response?->serverError());
            }, throw: false)
            ->post($baseUrl.'/smtp/email', $payload);

        if ($response->status() === 400 && $response->json('code') === 'duplicate_parameter') {
            return 'idempotent-duplicate:'.$idempotencyKey;
        }

        if (! $response->successful()) {
            $providerCode = preg_replace('/[^A-Za-z0-9_.-]/', '', (string) $response->json('code'));
            $suffix = filled($providerCode) ? " ({$providerCode})" : '';
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
