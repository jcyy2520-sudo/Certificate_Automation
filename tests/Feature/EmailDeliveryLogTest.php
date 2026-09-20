<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
