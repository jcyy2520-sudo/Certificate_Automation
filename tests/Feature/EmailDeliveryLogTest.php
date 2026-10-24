<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class EmailDeliveryLogTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->webinar = Webinar::query()->create([
            'title' => 'Log Fixture '.uniqid(),
            'slug' => 'log-fixture-'.uniqid(),
            'status' => 'published',
            'timezone' => 'UTC',
            'ends_at' => now()->addDays(3),
            'created_by' => $this->administrator->id,
            'data_retention_days' => 30,
        ]);
    }

    private function deliver(array $overrides = []): EmailDelivery
    {
        return $this->webinar->emailDeliveries()->create(array_merge([
            'type' => 'certificate',
            'recipient_email' => 'maria.delacruz@example.com',
            'subject' => 'Your certificate',
            'status' => 'sent',
            'attempts' => 1,
            'provider_message_id' => 'brevo-123',
            'scheduled_at' => now(),
            'sent_at' => now(),
        ], $overrides));
    }

    public function test_global_and_webinar_logs_render_with_masked_recipients(): void
    {
        $this->deliver();

        $global = $this->actingAs($this->administrator)->get(route('admin.email-logs.index'));
        $global->assertOk();
        $global->assertSee('m•••@example.com');
        $global->assertSee('Your certificate');
        $global->assertDontSee('maria.delacruz@example.com');

        $scoped = $this->actingAs($this->administrator)
            ->get(route('admin.webinars.email-logs.index', $this->webinar));
        $scoped->assertOk();
        $scoped->assertSee('m•••@example.com');
    }

    public function test_the_status_filter_narrows_the_log(): void
    {
        $this->deliver();
        $this->deliver(['recipient_email' => 'other@example.com', 'status' => 'failed', 'failed_at' => now(), 'last_error' => 'Provider rejected.']);

        $failedOnly = $this->actingAs($this->administrator)
            ->get(route('admin.email-logs.index', ['status' => 'failed']));

        $failedOnly->assertOk();
        $failedOnly->assertSee('o•••@example.com');
        $failedOnly->assertSee('Provider rejected.');
        $failedOnly->assertDontSee('m•••@example.com');
    }

    public function test_the_log_identifies_an_activation_that_expired_unattempted_instead_of_promising_queue_progress(): void
    {
        Queue::fake();
        Http::preventStrayRequests();
        $account = User::factory()->create(['role' => User::USER_ROLE, 'is_active' => false, 'password_setup_at' => null]);
        $delivery = EmailDelivery::query()->create([
            'account_user_id' => $account->id, 'type' => 'account_setup', 'provider' => 'brevo',
            'recipient_email' => $account->email, 'subject' => 'Synthetic expired activation',
            'status' => 'pending', 'attempts' => 0, 'payload' => ['html' => 'Synthetic expired invitation'],
            'expires_at' => now()->subMinute(), 'scheduled_at' => now()->addHour(),
        ]);

        $this->actingAs($this->administrator)->get(route('admin.email-logs.index'))
            ->assertOk()->assertSee('Expired before sending')
            ->assertViewHas('deliveries', fn ($rows) => $rows->sole()->expires_at?->isPast());

        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertSame(0, $delivery->fresh()->attempts);
        Http::assertNothingSent();
        Queue::assertNothingPushed();
    }

    public static function preservedDeliveryLabels(): array
    {
        return [
            'current activation waiting for a worker' => [['type' => 'account_setup', 'status' => 'pending', 'attempts' => 0], 5, false, 'Queued'],
            'current activation deferred by budget' => [['type' => 'account_setup', 'status' => 'pending', 'attempts' => 0], 5, true, 'Queued for later'],
            'certificate without expiry' => [['type' => 'certificate', 'status' => 'pending', 'attempts' => 0], null, false, 'Queued'],
            'participant message without expiry' => [['type' => 'participant_message', 'status' => 'pending', 'attempts' => 0], null, false, 'Queued'],
            'accepted activation stays accepted after link expiry' => [['type' => 'account_setup', 'status' => 'sent', 'attempts' => 1, 'provider' => 'brevo'], -5, false, 'Provider accepted'],
            'delivered activation stays delivered after link expiry' => [['type' => 'account_setup', 'status' => 'sent', 'attempts' => 1, 'provider' => 'brevo', 'delivery_outcome' => 'delivered'], -5, false, 'Delivered to mail server'],
            'local capture stays distinct after link expiry' => [['type' => 'account_setup', 'status' => 'sent', 'attempts' => 1, 'provider' => 'log'], -5, false, 'Captured locally'],
            'failed activation stays failed' => [['type' => 'account_setup', 'status' => 'failed', 'attempts' => 1], -5, false, 'Failed'],
            'cancelled activation stays cancelled' => [['type' => 'account_setup', 'status' => 'cancelled', 'attempts' => 0], -5, false, 'Cancelled'],
        ];
    }

    #[DataProvider('preservedDeliveryLabels')]
    public function test_expiry_does_not_override_current_or_terminal_delivery_meanings(array $state, ?int $expiresInMinutes, bool $scheduledLater, string $label): void
    {
        $delivery = new EmailDelivery($state + [
            'expires_at' => $expiresInMinutes === null ? null : now()->addMinutes($expiresInMinutes),
            'scheduled_at' => $scheduledLater ? now()->addMinute() : now(),
        ]);

        $this->assertSame($label, $delivery->statusLabel());
    }

    public function test_a_certificate_delivery_is_reachable_from_its_webinar_only_through_scoping(): void
    {
        $delivery = $this->deliver();
        $other = Webinar::query()->create([
            'title' => 'Elsewhere '.uniqid(),
            'slug' => 'elsewhere-'.uniqid(),
            'status' => 'published',
            'timezone' => 'UTC',
            'ends_at' => now()->addDay(),
            'created_by' => $this->administrator->id,
        ]);

        $scoped = $this->actingAs($this->administrator)
            ->get(route('admin.webinars.email-logs.index', $other));

        $scoped->assertOk();
        $scoped->assertDontSee($delivery->subject);
    }
}
