<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Webinar;
use App\Services\Email\BrevoDeliveryStatus;
use App\Services\Email\BrevoTransactionalMailer;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class EmailDeliveryStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['services.brevo.key' => 'synthetic-key']);
    }

    private function delivery(array $values = []): EmailDelivery
    {
        return EmailDelivery::query()->create($values + [
            'type' => 'certificate', 'provider' => 'brevo', 'status' => 'sent',
            'recipient_email' => 'recipient@example.test', 'subject' => 'Synthetic', 'provider_message_id' => '<synthetic@example.test>',
        ]);
    }

    public function test_status_check_reads_only_matching_provider_events_without_sending(): void
    {
        $delivery = $this->delivery();
        Http::fake(['api.brevo.com/*' => Http::response(['events' => [
            ['messageId' => 'unrelated', 'email' => 'recipient@example.test', 'event' => 'hardBounces'],
            ['messageId' => '<synthetic@example.test>', 'email' => 'someone-else@example.test', 'event' => 'blocked'],
            ['messageId' => '<synthetic@example.test>', 'email' => 'recipient@example.test', 'event' => 'delivered', 'reason' => 'Do not retain raw response'],
        ]])]);
        app(BrevoDeliveryStatus::class)->refresh($delivery);
        $this->assertSame('Delivered to mail server', $delivery->fresh()->statusLabel());
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->last_error);
        Http::assertSent(fn ($request) => $request->method() === 'GET' && $request['messageId'] === '<synthetic@example.test>');
        app(BrevoDeliveryStatus::class)->refresh($delivery->fresh());
        Http::assertSentCount(1);
        Queue::assertNothingPushed();
    }

    public function test_bounced_and_delayed_events_are_distinct_from_provider_acceptance(): void
    {
        $sequence = Http::fakeSequence();
        foreach (['hardBounces', 'blocked', 'softBounces', 'deferred'] as $event) {
            $sequence->push(['events' => [['messageId' => '<synthetic@example.test>', 'email' => 'recipient@example.test', 'event' => $event]]]);
        }
        foreach (['hardBounces' => 'Delivery failed', 'blocked' => 'Delivery failed', 'softBounces' => 'Delivery delayed', 'deferred' => 'Delivery delayed'] as $event => $label) {
            $delivery = $this->delivery();
            app(BrevoDeliveryStatus::class)->refresh($delivery);
            $this->assertSame($label, $delivery->fresh()->statusLabel());
            $this->assertSame('sent', $delivery->fresh()->status);
        }
        $this->assertSame('Provider accepted', $this->delivery()->statusLabel());
    }

    public function test_unknown_outcomes_and_provider_errors_never_claim_delivery_or_send(): void
    {
        $delivery = $this->delivery();
        Http::fakeSequence()->push(['events' => []], 200)->push([], 503);
        app(BrevoDeliveryStatus::class)->refresh($delivery);
        $this->assertSame('Provider accepted', $delivery->fresh()->statusLabel());
        $this->travel(31)->seconds();
        try {
            app(BrevoDeliveryStatus::class)->refresh($delivery->fresh());
            $this->fail('Unavailable provider must report a status-check error.');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('No email was sent or retried', $exception->errors()['delivery'][0]);
        }
        $this->assertNull($delivery->fresh()->delivery_outcome);
        Http::assertSentCount(2);
        Queue::assertNothingPushed();
    }

    public function test_log_transport_is_not_converted_when_brevo_is_enabled(): void
    {
        config(['webinar.email.provider' => 'brevo', 'accounts.email_provider' => 'log']);
        $account = User::factory()->create();
        $delivery = $this->delivery(['type' => 'account_delivery_test', 'account_user_id' => $account->id, 'status' => 'pending', 'provider' => 'log', 'recipient_email' => $account->email,
            'payload' => ['account_version' => $account->auth_version, 'html' => '<p>Synthetic test</p>']]);
        Http::fake();
        (new SendTransactionalEmail($delivery->id))->handle(app(BrevoTransactionalMailer::class));
        $this->assertSame('Captured locally', $delivery->fresh()->statusLabel());
        Http::assertNothingSent();
    }

    public function test_certificate_provider_can_be_enabled_without_enabling_participant_messages(): void
    {
        config(['webinar.email.provider' => 'log', 'webinar.email.certificate_provider' => 'brevo']);
        $notifications = app(NotificationService::class);
        $certificate = $notifications->queue(null, null, 'certificate_test', 'synthetic@example.test', 'Synthetic', '<p>Synthetic</p>');
        $participant = $notifications->queue(null, null, 'participant_message', 'synthetic@example.test', 'Synthetic', '<p>Synthetic</p>');
        $this->assertSame('brevo', $certificate->provider);
        $this->assertSame('log', $participant->provider);
    }

    public function test_status_routes_are_authorized_and_cannot_cross_webinars(): void
    {
        $admin = User::factory()->create();
        $manager = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $webinar = Webinar::factory()->create(['created_by' => $admin->id]);
        $other = Webinar::factory()->create(['created_by' => $admin->id]);
        $manager->assignedWebinars()->attach($webinar, ['assigned_by' => $admin->id, 'send_emails' => true]);
        $delivery = $this->delivery(['webinar_id' => $webinar->id]);
        $foreign = $this->delivery(['webinar_id' => $other->id]);
        Http::fake(['api.brevo.com/*' => Http::response(['events' => []])]);
        $this->actingAs($manager)->post(route('admin.email-logs.status', $delivery->public_id))->assertForbidden();
        $this->post(route('admin.webinars.email-logs.status', [$webinar, $foreign->public_id]))->assertNotFound();
        Http::assertNothingSent();
        $this->post(route('admin.webinars.email-logs.status', [$webinar, $delivery->public_id]))->assertRedirect()->assertSessionHasNoErrors();
        Http::assertSentCount(1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'email.status_checked', 'actor_user_id' => $manager->id]);
        $manager->assignedWebinars()->updateExistingPivot($webinar, ['send_emails' => false]);
        $this->post(route('admin.webinars.email-logs.status', [$webinar, $delivery->public_id]))->assertForbidden();
    }
}
