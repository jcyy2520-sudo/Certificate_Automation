<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MobileNavigationLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_primary_navigation_is_available_as_an_accessible_mobile_drawer(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);

        $this->actingAs($administrator)
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('data-mobile-rail-toggle', false)
            ->assertSee('aria-controls="mobile-primary-navigation"', false)
            ->assertSee('id="mobile-primary-navigation"', false)
            ->assertSee('data-mobile-rail-close', false);
    }

    public function test_the_webinar_workspace_navigation_has_its_own_mobile_drawer(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::query()->create([
            'title' => 'Mobile Workspace',
            'slug' => 'mobile-workspace',
            'status' => 'published',
            'timezone' => 'UTC',
            'created_by' => $administrator->id,
        ]);

        $this->actingAs($administrator)
            ->get(route('admin.webinars.show', $webinar))
            ->assertOk()
            ->assertSee('data-mobile-webinar-nav-toggle', false)
            ->assertSee('aria-controls="mobile-webinar-navigation"', false)
            ->assertSee('id="mobile-webinar-navigation"', false)
            ->assertSee('data-mobile-webinar-nav-close', false)
            ->assertDontSee('data-mobile-rail-toggle', false)
            ->assertDontSee('id="mobile-primary-navigation"', false);
    }

    public function test_all_webinar_subpages_exclude_primary_navigation_and_use_workspace_drawer(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::query()->create([
            'title' => 'Workspace Subpages',
            'slug' => 'workspace-subpages',
            'status' => 'published',
            'timezone' => 'UTC',
            'created_by' => $administrator->id,
        ]);

        $subpageRoutes = [
            route('admin.participants.index', $webinar),
            route('admin.certification.edit', $webinar),
            route('admin.webinars.reports', $webinar),
            route('admin.webinars.edit', $webinar),
        ];

        foreach ($subpageRoutes as $url) {
            $this->actingAs($administrator)
                ->get($url)
                ->assertOk()
                ->assertSee('data-mobile-webinar-nav-toggle', false)
                ->assertSee('id="mobile-webinar-navigation"', false)
                ->assertDontSee('data-mobile-rail-toggle', false)
                ->assertDontSee('id="mobile-primary-navigation"', false);
        }
    }
}
