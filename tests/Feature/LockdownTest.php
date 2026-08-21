<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Guards the promise that the only thing a stranger can open is a form whose
 * exact share link they were given, plus a certificate whose exact code they hold.
 */
class LockdownTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_forms_verification_and_sign_in_are_reachable_without_logging_in(): void
    {
        $unauthenticated = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => in_array('GET', $route->methods(), true))
            ->reject(fn ($route) => in_array('auth', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->uri())
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing([
            '/',
            'admin/login',
            'admin/two-factor-challenge',
            'certificates/verify/{code}',
            'f/{token}',
            'f/{token}/access/confirm',
            'f/{token}/submitted',
            'up',
        ], $unauthenticated, 'A new route escaped the admin middleware group.');
    }

    public function test_private_disk_registers_no_generic_http_routes(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));

        $this->get('/storage/anything')->assertNotFound();
        $this->put('/storage/anything', ['payload' => 'probe'])->assertNotFound();
    }

    public function test_the_root_url_goes_straight_to_a_bare_sign_in_page(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        Webinar::query()->create([
            'title' => 'Confidential Internal Briefing', 'slug' => 'confidential-internal-briefing',
            'status' => 'published', 'timezone' => 'UTC', 'created_by' => $administrator->id,
        ]);

        $this->get('/')->assertRedirect(route('login'));

        $response = $this->followingRedirects()->get('/');

        $response->assertOk()
            ->assertSee('Sign in')
            ->assertDontSee('Confidential Internal Briefing');

        // No landing page: no marketing copy and no navigation off the form.
        $response->assertDontSee('calm control room')
            ->assertDontSee('Dashboard')
            ->assertDontSee('Webinars');

        $this->assertStringNotContainsString('<a ', $response->getContent(), 'The sign-in page must contain no links.');
    }

    public function test_the_retired_public_event_urls_are_gone(): void
    {
        foreach (['/events', '/events/confidential-internal-briefing', '/events/confidential-internal-briefing/register', '/participant'] as $path) {
            $this->get($path)->assertNotFound();
        }
    }

    public function test_every_admin_page_redirects_a_stranger_to_sign_in(): void
    {
        $administrator = User::factory()->create(['is_active' => true]);
        $webinar = Webinar::query()->create([
            'title' => 'Internal', 'slug' => 'internal', 'status' => 'published',
            'timezone' => 'UTC', 'created_by' => $administrator->id,
        ]);
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Registration']);
        $participant = Participant::query()->create(['webinar_id' => $webinar->id, 'email' => 'p@example.com']);

        $guarded = [
            route('admin.dashboard'),
            route('admin.webinars.index'),
            route('admin.webinars.create'),
            route('admin.webinars.show', $webinar),
            route('admin.forms.edit', [$webinar, $form]),
            route('admin.certification.edit', $webinar),
            route('admin.certification.preview', $webinar),
            route('admin.participants.index', $webinar),
            route('admin.participants.export', $webinar),
            route('admin.participants.show', [$webinar, $participant]),
            route('admin.two-factor.show'),
        ];

        foreach ($guarded as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
    }

    public function test_stored_certificates_are_not_reachable_over_http(): void
    {
        Storage::disk(config('webinar.certificate_disk'))
            ->put('certificates/leak-probe.pdf', 'CERTIFICATE-BYTES');

        foreach (['/storage/certificates/leak-probe.pdf', '/certificates/leak-probe.pdf'] as $path) {
            $response = $this->get($path);

            $this->assertNotSame(200, $response->getStatusCode(), $path.' served a stored certificate.');
            $this->assertStringNotContainsString('CERTIFICATE-BYTES', $response->getContent());
        }

        Storage::disk(config('webinar.certificate_disk'))->delete('certificates/leak-probe.pdf');
    }

    public function test_search_engines_are_told_to_index_nothing(): void
    {
        $this->assertSame(
            "User-agent: *\nDisallow: /\n",
            str_replace("\r\n", "\n", file_get_contents(public_path('robots.txt'))),
        );
    }
}
