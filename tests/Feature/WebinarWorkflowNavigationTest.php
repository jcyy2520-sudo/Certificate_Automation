<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class WebinarWorkflowNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_overview_guides_the_existing_workflow_in_order(): void
    {
        $administrator = User::factory()->create();
        $webinar = $this->webinar($administrator);
        $form = $webinar->forms->first();
        $response = $this->actingAs($administrator)->get(route('admin.webinars.show', $webinar))->assertOk();
        $dom = $this->dom($response->getContent());

        $this->assertSame([
            route('admin.forms.edit', [$webinar, $form]),
            route('admin.responses.index', [$webinar, 'type' => 'registration']),
            route('admin.webinars.eligibility.index', $webinar),
            route('admin.certification.edit', $webinar),
            route('admin.certificates.studio', $webinar),
        ], $this->hrefs($dom, '//section[@aria-labelledby="webinar-workflow-heading"]//ol/li/a'));

        $settings = route('admin.responses.index', [$webinar, 'type' => 'registration', 'view' => 'settings']);
        $this->assertSame([$settings], $this->hrefs($dom, '//section[@aria-labelledby="webinar-workflow-heading"]//a[normalize-space(.)="Eligibility Settings"]'));
    }

    public function test_requirement_review_opens_eligibility_settings_instead_of_certificate_artwork(): void
    {
        $administrator = User::factory()->create();
        $webinar = $this->webinar($administrator);
        $response = $this->actingAs($administrator)->get(route('admin.webinars.show', $webinar))->assertOk();

        $this->assertSame([
            route('admin.responses.index', [$webinar, 'type' => 'registration', 'view' => 'settings']),
        ], $this->hrefs($this->dom($response->getContent()), '//a[normalize-space(.)="Review requirements"]'));
    }

    public function test_common_phone_navigation_marks_the_current_page_and_keeps_the_full_drawer_available(): void
    {
        $administrator = User::factory()->create();
        $webinar = $this->webinar($administrator);
        $response = $this->actingAs($administrator)->get(route('admin.responses.index', $webinar))->assertOk();
        $dom = $this->dom($response->getContent());

        $this->assertSame([
            route('admin.webinars.show', $webinar),
            route('admin.forms.edit', [$webinar, $webinar->forms->first()]),
            route('admin.responses.index', [$webinar, 'type' => 'registration']),
        ], $this->hrefs($dom, '//nav[@aria-label="Common webinar actions"]/a'));
        $this->assertSame([route('admin.responses.index', [$webinar, 'type' => 'registration'])], $this->hrefs($dom, '//nav[@aria-label="Common webinar actions"]/a[@aria-current="page"]'));
        $this->assertSame(1, $dom->query('//nav[@aria-label="Common webinar actions"]/button[@aria-controls="mobile-webinar-navigation"][@data-mobile-webinar-nav-toggle]')->length);
    }

    public function test_participant_manager_workflow_has_only_permitted_destinations(): void
    {
        $administrator = User::factory()->create();
        $webinar = $this->webinar($administrator);
        $manager = $this->manager($administrator, $webinar, ['manage_participants' => true]);
        $response = $this->actingAs($manager)->get(route('admin.webinars.show', $webinar))->assertOk();
        $dom = $this->dom($response->getContent());

        $this->assertCount(3, $this->hrefs($dom, '//section[@aria-labelledby="webinar-workflow-heading"]//ol/li/a'));
        $this->assertSame([], $this->hrefs($dom, '//a[@href="'.route('admin.certification.edit', $webinar).'"]'));
        $this->assertSame([], $this->hrefs($dom, '//a[@href="'.route('admin.certificates.studio', $webinar).'"]'));
        $this->assertSame([], $this->hrefs($dom, '//a[@href="'.route('admin.webinars.edit', $webinar).'"]'));
    }

    public function test_certificate_manager_phone_navigation_uses_setup_and_studio_instead_of_participant_routes(): void
    {
        $administrator = User::factory()->create();
        $webinar = $this->webinar($administrator);
        $manager = $this->manager($administrator, $webinar, ['manage_certificates' => true]);
        $response = $this->actingAs($manager)->get(route('admin.webinars.show', $webinar))->assertOk();
        $dom = $this->dom($response->getContent());

        $this->assertSame([
            route('admin.webinars.show', $webinar),
            route('admin.certification.edit', $webinar),
            route('admin.certificates.studio', $webinar),
        ], $this->hrefs($dom, '//nav[@aria-label="Common webinar actions"]/a'));
        $this->assertSame([], $this->hrefs($dom, '//a[@href="'.route('admin.responses.index', [$webinar, 'type' => 'registration']).'"]'));
    }

    public function test_file_storage_navigation_never_offers_unavailable_response_or_certificate_routes(): void
    {
        $administrator = User::factory()->create();
        $webinar = $this->webinar($administrator, true);
        $response = $this->actingAs($administrator)->get(route('admin.webinars.show', $webinar))->assertOk();
        $dom = $this->dom($response->getContent());

        $this->assertSame([
            route('admin.webinars.show', $webinar),
            route('admin.webinars.documents.index', $webinar),
        ], $this->hrefs($dom, '//nav[@aria-label="Common webinar actions"]/a'));
        $this->assertSame([], $this->hrefs($dom, '//a[contains(@href,"/responses") or contains(@href,"/certification") or contains(@href,"/certificates") or contains(@href,"/participants") or contains(@href,"/imports")]'));
        $this->assertSame([route('admin.webinars.create')], $this->hrefs($dom, '//main//a[normalize-space(.)="Create a response and certificate webinar"]'));
    }

    private function webinar(User $owner, bool $uploads = false): Webinar
    {
        $webinar = Webinar::query()->create([
            'title' => 'Synthetic workflow webinar', 'slug' => (string) Str::uuid(),
            'created_by' => $owner->id, 'status' => 'draft', 'timezone' => 'UTC',
            'creation_mode' => $uploads ? 'uploads' : 'standard', 'data_retention_days' => 30,
        ]);
        if (! $uploads) {
            $webinar->forms()->create(['type' => 'registration', 'title' => 'Registration', 'status' => 'draft']);
        }

        return $webinar;
    }

    private function manager(User $administrator, Webinar $webinar, array $permissions): User
    {
        $manager = User::factory()->create(['role' => User::MANAGER_ROLE, 'account_source' => 'admin_invitation']);
        $manager->assignedWebinars()->attach($webinar, array_merge([
            'assigned_by' => $administrator->id, 'manage_participants' => false,
            'manage_certificates' => false, 'send_emails' => false,
        ], $permissions));

        return $manager;
    }

    private function dom(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML($html);

        return new DOMXPath($document);
    }

    private function hrefs(DOMXPath $dom, string $query): array
    {
        return array_map(fn ($node): string => $node->getAttribute('href'), iterator_to_array($dom->query($query)));
    }
}
