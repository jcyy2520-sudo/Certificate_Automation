<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailDeliveryPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_dashboard_does_not_hydrate_delivery_payloads(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::query()->create([
            'title' => 'Fast dashboard',
            'slug' => 'fast-dashboard',
            'created_by' => $administrator->id,
        ]);
        EmailDelivery::query()->create([
            'webinar_id' => $webinar->id,
            'type' => 'certificate',
            'provider' => 'test',
            'recipient_email' => 'participant@example.com',
            'subject' => 'Large delivery',
            'payload' => ['attachments' => [['content' => str_repeat('A', 100_000)]]],
        ]);

        $this->actingAs($administrator)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertViewHas('stats', [
                'webinars' => 1,
                'participants' => 0,
                'certificates' => 0,
                'emails' => 0,
            ])
            ->assertViewHas('deliveries', fn ($deliveries): bool => ! array_key_exists('payload', $deliveries->first()->getAttributes()))
            ->assertSee('p••••••••••@example.com')
            ->assertDontSee('participant@example.com');
    }

    public function test_a_successful_delivery_discards_its_large_retry_payload(): void
    {
        $webinar = Webinar::factory()->create();
        $participant = Participant::query()->create([
            'webinar_id' => $webinar->id,
            'email' => 'participant@example.com',
        ]);
        $delivery = EmailDelivery::query()->create([
            'webinar_id' => $webinar->id,
            'participant_id' => $participant->id,
            'type' => 'certificate',
            'provider' => 'test',
            'recipient_email' => 'participant@example.com',
            'subject' => 'Your certificate',
            'payload' => [
                'html' => '<p>Your certificate is attached.</p>',
                'attachments' => [['name' => 'certificate.pdf', 'content' => str_repeat('A', 100_000)]],
            ],
        ]);

        $mailer = new class implements TransactionalMailer
        {
            public function send(
                string $recipient,
                string $subject,
                string $html,
                array $attachments = [],
                ?string $idempotencyKey = null,
            ): ?string {
                return 'provider-message-123';
            }
        };

        (new SendTransactionalEmail($delivery->id))->handle($mailer);

        $delivery->refresh();

        $this->assertSame('sent', $delivery->status);
        $this->assertSame('provider-message-123', $delivery->provider_message_id);
        $this->assertNull($delivery->payload);
        $this->assertNotNull($delivery->sent_at);
    }
}
