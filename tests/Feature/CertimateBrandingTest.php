<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Webinar;
use App\Services\AccountService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CertimateBrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['app.name' => 'Historical infrastructure label']);
    }

    public function test_sign_in_uses_the_certimate_brand_and_browser_icons_without_changing_infrastructure_name(): void
    {
        $response = $this->get(route('login'))->assertOk();
        $response->assertSee('<title>Sign in — Certimate</title>', false)
            ->assertSee('/brand/certimate-wordmark.webp', false)->assertSee('/brand/certimate-wordmark.png', false)
            ->assertSee('/favicon-32x32.png', false)->assertSee('/favicon-16x16.png', false)
            ->assertSee('/apple-touch-icon.png', false)->assertSee('/site.webmanifest', false)
            ->assertDontSee('Historical infrastructure label');
        $this->assertSame('Historical infrastructure label', config('app.name'));
        $this->assertSame('Certimate', config('branding.name'));
    }

    public function test_admin_navigation_and_webinar_titles_share_the_brand(): void
    {
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id, 'title' => 'Synthetic brand event']);
        $this->actingAs($owner)->get(route('admin.dashboard'))->assertOk()->assertSee('Certimate')
            ->assertSee('/brand/certimate-icon.webp', false)->assertDontSee('Historical infrastructure label');
        $response = $this->get(route('admin.webinars.show', $webinar))->assertOk()->assertSee(' — Certimate</title>', false)
            ->assertSee('/brand/certimate-icon.png', false)->assertSee('/site.webmanifest', false);
        $this->assertStringContainsString('Synthetic brand event', $response->getContent());
    }

    public function test_public_response_and_error_layouts_show_branding_without_new_navigation(): void
    {
        $webinar = Webinar::factory()->create(['requires_verification' => false]);
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Synthetic form', 'status' => 'published']);
        $this->get($form->shareUrl())->assertOk()->assertSee(' — Certimate</title>', false)
            ->assertSee('/brand/certimate-wordmark.webp', false)->assertDontSee('href="'.route('admin.dashboard').'"', false);
        $this->get('/synthetic-brand-page-not-found')->assertNotFound()->assertSee(' — Certimate</title>', false)
            ->assertSee('/brand/certimate-wordmark.webp', false)->assertSee('/favicon-32x32.png', false);
    }

    public function test_activation_copy_and_account_email_header_use_certimate_without_sending(): void
    {
        $user = app(AccountService::class)->request('Synthetic brand recipient', 'brand-recipient@example.test');
        app(AccountService::class)->transition($user, 'approve');
        $delivery = EmailDelivery::query()->where('account_user_id', $user->id)->sole();
        $this->assertSame('Activate your Certimate account', $delivery->subject);
        $this->assertStringContainsString('You have been invited to Certimate.', $delivery->payload['html']);
        $this->assertStringContainsString('<p>Certimate</p>', $delivery->payload['html']);
        $this->assertStringNotContainsString('Certificate Automation', $delivery->payload['html']);
        $this->assertStringNotContainsString('Historical infrastructure label', $delivery->payload['html']);
        $this->assertSame('pending', $delivery->status);
        $this->assertNull($delivery->sent_at);
    }
}
