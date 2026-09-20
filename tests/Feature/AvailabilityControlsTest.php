<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityControlsTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_webinar_uses_an_open_closed_switch_instead_of_status(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);

        $this->actingAs($administrator)
            ->get(route('admin.webinars.create'))
            ->assertOk()
            ->assertSee('Webinar availability')
            ->assertSee('name="is_open"', false)
            ->assertDontSee('name="status"', false);

        $this->actingAs($administrator)->post(route('admin.webinars.store'), [
            'title' => 'Closed Until Ready',
            'is_open' => '0',
            'data_retention_days' => 14,
        ])->assertRedirect();

        $webinar = Webinar::query()->where('slug', 'closed-until-ready')->sole();
        $this->assertSame('draft', $webinar->status);
        $this->assertFalse($webinar->isOpen());
    }

    public function test_a_webinar_can_be_closed_and_reopened_without_exposing_lifecycle_status(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::factory()->create([
            'created_by' => $administrator->id,
            'status' => 'published',
            'timezone' => 'UTC',
            'ends_at' => now()->addDay(),
        ]);

        $this->actingAs($administrator)
            ->put(route('admin.webinars.update', $webinar), $this->payload($webinar, false))
            ->assertRedirect(route('admin.webinars.show', $webinar));

        $this->assertSame('completed', $webinar->fresh()->status);
        $this->assertFalse($webinar->fresh()->isOpen());

        $this->actingAs($administrator)
            ->put(route('admin.webinars.update', $webinar), $this->payload($webinar->fresh(), true))
            ->assertRedirect(route('admin.webinars.show', $webinar));

        $this->assertSame('published', $webinar->fresh()->status);
        $this->assertTrue($webinar->fresh()->isOpen());
    }

    public function test_every_form_uses_the_same_manual_switch_and_deadlines_still_apply(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::factory()->create([
            'created_by' => $administrator->id,
            'status' => 'published',
            'ends_at' => now()->addDay(),
        ]);

        foreach (['registration', 'pretest', 'posttest', 'evaluation'] as $type) {
            $form = $webinar->forms()->create([
                'type' => $type,
                'title' => ucfirst($type),
                'status' => 'closed',
            ]);

            $this->actingAs($administrator)
                ->get(route('admin.forms.edit', [$webinar, $form]))
                ->assertOk()
                ->assertSee('name="is_open"', false)
                ->assertDontSee('name="status"', false);

            $this->actingAs($administrator)
                ->post(route('admin.forms.toggle', [$webinar, $form]), ['is_open' => '1'])
                ->assertRedirect();

            $this->assertTrue($form->fresh()->isOpen());

            $form->update(['closes_at' => now()->subMinute()]);
            $this->assertFalse($form->fresh()->acceptsResponses());

            $this->actingAs($administrator)
                ->post(route('admin.forms.toggle', [$webinar, $form]), ['is_open' => '0'])
                ->assertRedirect();

            $this->assertFalse($form->fresh()->isOpen());
        }
    }

    public function test_opening_with_ajax_takes_effect_now_without_discarding_a_future_deadline(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::factory()->create([
            'created_by' => $administrator->id,
            'status' => 'published',
            'ends_at' => now()->addDays(2),
            'registration_opens_at' => now()->addHour(),
            'registration_closes_at' => now()->addDay(),
        ]);
        $form = $webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Registration',
            'status' => 'closed',
            'opens_at' => now()->addHours(2),
            'closes_at' => now()->addHours(12),
        ]);

        $response = $this->actingAs($administrator)
            ->postJson(route('admin.forms.toggle', [$webinar, $form]), ['is_open' => true]);

        $response->assertOk()->assertJson([
            'open' => true,
            'accepts_responses' => true,
            'state' => 'Open — accepting responses now',
        ]);

        $form->refresh();
        $webinar->refresh();
        $this->assertNull($form->opens_at);
        $this->assertNotNull($form->closes_at);
        $this->assertNull($webinar->registration_opens_at);
        $this->assertNotNull($webinar->registration_closes_at);
        $this->assertTrue($form->setRelation('webinar', $webinar)->acceptsResponses());

        $this->actingAs($administrator)
            ->postJson(route('admin.forms.toggle', [$webinar, $form]), ['is_open' => false])
            ->assertOk()
            ->assertJson(['open' => false, 'accepts_responses' => false]);
    }

    public function test_an_already_open_registration_is_not_blocked_by_old_opening_dates(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::factory()->create([
            'created_by' => $administrator->id,
            'status' => 'published',
            'ends_at' => now()->addDays(2),
            'registration_opens_at' => now()->addHour(),
            'registration_closes_at' => now()->addDay(),
        ]);
        $form = $webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Registration',
            'status' => 'published',
            'opens_at' => now()->addHours(2),
            'closes_at' => now()->addHours(12),
        ]);

        $form->setRelation('webinar', $webinar);
        $this->assertTrue($form->acceptsResponses());

        $this->actingAs($administrator)
            ->get(route('admin.forms.edit', [$webinar, $form]))
            ->assertOk()
            ->assertSee('Open — accepting responses now')
            ->assertDontSee('Registration is not open yet');
    }

    public function test_an_expired_registration_is_shown_closed_and_reopens_with_one_on_action(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::factory()->create([
            'created_by' => $administrator->id,
            'status' => 'published',
            'ends_at' => now()->addDays(2),
            'registration_closes_at' => now()->subHour(),
        ]);
        $form = $webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Registration',
            'status' => 'published',
            'closes_at' => now()->subHour(),
        ]);

        $form->setRelation('webinar', $webinar);
        $this->assertTrue($form->isOpen());
        $this->assertFalse($form->acceptsResponses());
        $this->assertTrue($form->canReopenByClearingExpiredDeadline());

        $this->actingAs($administrator)
            ->get(route('admin.forms.edit', [$webinar, $form]))
            ->assertOk()
            ->assertSee('Closed — deadline passed')
            ->assertSee('data-availability-switch', false)
            ->assertDontSee('data-availability-switch checked', false);

        $this->actingAs($administrator)
            ->postJson(route('admin.forms.toggle', [$webinar, $form]), ['is_open' => true])
            ->assertOk()
            ->assertJson([
                'open' => true,
                'accepts_responses' => true,
                'state' => 'Open — accepting responses now',
            ]);

        $form->refresh();
        $webinar->refresh();
        $this->assertNull($form->closes_at);
        $this->assertNull($webinar->registration_closes_at);
        $this->assertTrue($form->setRelation('webinar', $webinar)->acceptsResponses());
    }

    private function payload(Webinar $webinar, bool $open): array
    {
        return [
            'title' => $webinar->title,
            'description' => $webinar->description,
            'is_open' => $open ? '1' : '0',
            'starts_at' => $webinar->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $webinar->ends_at?->format('Y-m-d\TH:i'),
            'registration_opens_at' => $webinar->registration_opens_at?->format('Y-m-d\TH:i'),
            'registration_closes_at' => $webinar->registration_closes_at?->format('Y-m-d\TH:i'),
            'registration_capacity' => $webinar->registration_capacity,
            'timezone' => $webinar->timezone,
            'data_retention_days' => $webinar->data_retention_days,
            'requires_verification' => $webinar->requiresVerification() ? '1' : '0',
        ];
    }
}
