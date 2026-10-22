<?php

namespace Tests\Feature;

use App\Models\EmailDelivery;
use App\Models\Form;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class RegistrationSelfRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    private Form $registrationForm;

    private Form $posttestForm;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['is_active' => true]);

        $this->webinar = Webinar::factory()->create([
            'title' => 'Self Recovery Webinar',
            'slug' => 'self-recovery-webinar',
            'created_by' => $admin->id,
        ]);

        $this->registrationForm = $this->webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Webinar Registration',
            'status' => 'published',
        ]);

        $this->posttestForm = $this->webinar->forms()->create([
            'type' => 'posttest',
            'title' => 'Post-Test Assessment',
            'status' => 'published',
        ]);
    }

    #[Test]
    public function it_renders_register_button_when_registration_is_open(): void
    {
        $this->authenticateParticipant($this->posttestForm, 'unregistered@example.com');

        $response = $this->get(route('forms.public', $this->posttestForm->public_token));

        $response->assertOk();
        $response->assertViewIs('public.registration-required');
        $response->assertSee('Register for this webinar');
        $response->assertSee(route('forms.public', $this->registrationForm->public_token));
    }

    #[Test]
    public function it_omits_register_button_when_registration_form_is_closed(): void
    {
        $this->authenticateParticipant($this->posttestForm, 'unregistered@example.com');

        $this->registrationForm->update(['closes_at' => now()->subMinute()]);

        $response = $this->get(route('forms.public', $this->posttestForm->public_token));

        $response->assertOk();
        $response->assertViewIs('public.registration-required');
        $response->assertDontSee('Register for this webinar');
    }

    private function authenticateParticipant(Form $form, string $email): void
    {
        Queue::fake();

        $this->post(route('forms.public.access.request', $form->public_token), [
            'email' => $email,
        ])->assertRedirect($form->shareUrl().'?sent=1')
            ->assertSessionHas('participant_access_requested');

        $delivery = EmailDelivery::query()->latest('id')->firstOrFail();
        $html = (string) ($delivery->payload['html'] ?? '');

        preg_match('/#token=([a-f0-9]{64})/', $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'Access token was not found in notification payload.');

        $this->post(route('forms.public.access.consume', $form->public_token), [
            'access_token' => $matches[1],
        ])->assertRedirect($form->shareUrl());
    }
}
