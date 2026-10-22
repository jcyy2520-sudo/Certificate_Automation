<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FrontendNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_navigation_is_an_opt_in_representation_with_existing_security_headers(): void
    {
        $owner = User::factory()->create();
        $webinar = Webinar::factory()->create(['created_by' => $owner->id, 'title' => 'José <script>alert(1)</script>']);
        $url = route('admin.webinars.show', $webinar);
        $this->actingAs($owner)->get($url)->assertOk()->assertHeader('Content-Type', 'text/html; charset=utf-8');
        $response = $this->withHeader('X-Frontend-Navigation', '1')->get($url)->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.webinar.navigation+json')
            ->assertJsonPath('boundary', false)->assertJsonPath('body.tag', 'body');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertStringContainsString("trusted-types 'none'", $response->headers->get('Content-Security-Policy'));
        $nodes = json_decode($response->getContent(), true)['body'];
        $encoded = json_encode($nodes);
        $this->assertStringContainsString('José', json_encode($nodes, JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('"tag":"script"', $encoded);
        $this->assertStringNotContainsString('"nonce":', $encoded);
        $this->assertStringContainsString('alert(1)', $encoded); // Escaped title remains text, never executable markup.
    }

    public function test_navigation_header_never_bypasses_ownership_or_authentication(): void
    {
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $other = User::factory()->create(['role' => User::USER_ROLE]);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $url = route('admin.webinars.show', $webinar);
        $this->withHeader('X-Frontend-Navigation', '1')->get($url)->assertRedirect(route('login'));
        $this->actingAs($other)->get($url)->assertNotFound()->assertDontSee($webinar->title);
    }

    public function test_validation_redirects_and_authentication_boundaries_keep_existing_behavior(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner)->withHeader('X-Frontend-Navigation', '1')
            ->from(route('admin.webinars.create'))->post(route('admin.webinars.store'), ['title' => ''])
            ->assertRedirect(route('admin.webinars.create'))->assertSessionHasErrors('title');
        $this->assertDatabaseCount('webinars', 0);
        auth()->logout();
        $this->get(route('login'))->assertOk()->assertJsonPath('boundary', true);
    }
}
