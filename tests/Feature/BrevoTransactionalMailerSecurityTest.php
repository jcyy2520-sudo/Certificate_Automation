<?php

namespace Tests\Feature;

use App\Services\Email\BrevoTransactionalMailer;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class BrevoTransactionalMailerSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.brevo', [
            'key' => 'test-only-key',
            'base_url' => 'https://api.brevo.com/v3',
            'from_address' => 'sender@example.test',
            'from_name' => 'Security Test',
        ]);
    }

    public function test_it_sends_a_stable_uuid_in_the_documented_idempotency_field(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response(['messageId' => 'provider-message-id']),
        ]);
        $key = (string) Str::uuid();

        $result = app(BrevoTransactionalMailer::class)->send(
            'participant@example.test',
            'Secure access',
            '<p>Body</p>',
            idempotencyKey: $key,
        );

        $this->assertSame('provider-message-id', $result);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.brevo.com/v3/smtp/email'
            && data_get($request->data(), 'headers.idempotencyKey') === $key
            && $request->hasHeader('api-key', 'test-only-key'));
    }

    public function test_a_duplicate_idempotency_response_is_terminal_success(): void
    {
        Http::fake([
            'https://api.brevo.com/v3/smtp/email' => Http::response(['code' => 'duplicate_parameter'], 400),
        ]);
        $key = (string) Str::uuid();

        $result = app(BrevoTransactionalMailer::class)->send(
            'participant@example.test',
            'Secure access',
            '<p>Body</p>',
            idempotencyKey: $key,
        );

        $this->assertSame('idempotent-duplicate:'.$key, $result);
    }

    public function test_an_unapproved_api_origin_is_rejected_before_credentials_are_sent(): void
    {
        Http::fake();
        config()->set('services.brevo.base_url', 'https://api.brevo.com.attacker.example/v3');

        $this->expectException(RuntimeException::class);

        try {
            app(BrevoTransactionalMailer::class)->send(
                'participant@example.test',
                'Secure access',
                '<p>Body</p>',
                idempotencyKey: (string) Str::uuid(),
            );
        } finally {
            Http::assertNothingSent();
        }
    }
}
