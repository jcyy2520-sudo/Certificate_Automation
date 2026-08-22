<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WebinarVerificationModeTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_defaults_on_and_fails_closed_when_the_attribute_is_missing(): void
    {
        $webinar = Webinar::factory()->create();

        $this->assertTrue($webinar->requiresVerification());
        $this->assertTrue((new Webinar)->requiresVerification());
        $this->assertDatabaseHas('webinars', [
            'id' => $webinar->id,
            'requires_verification' => true,
        ]);
    }

    public function test_an_administrator_can_turn_verification_off_for_one_webinar(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::factory()->create(['created_by' => $administrator->id]);

        $this->actingAs($administrator)
            ->put(route('admin.webinars.update', $webinar), [
                'title' => $webinar->title,
                'status' => 'published',
                'timezone' => 'UTC',
                'data_retention_days' => $webinar->data_retention_days,
                'starts_at' => $webinar->starts_at->format('Y-m-d\TH:i'),
                'ends_at' => $webinar->ends_at->format('Y-m-d\TH:i'),
                'requires_verification' => '0',
            ])
            ->assertRedirect(route('admin.webinars.show', $webinar));

        $this->assertFalse($webinar->fresh()->requiresVerification());
        $this->actingAs($administrator)
            ->get(route('admin.webinars.edit', $webinar))
            ->assertOk()
            ->assertSee('Secure mode off')
            ->assertSee('impersonation is possible');
    }
}
