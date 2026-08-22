<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicFormController;
use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\ParticipantAccessToken;
use App\Models\Submission;
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
        $otherWebinar = Webinar::factory()->create(['created_by' => $administrator->id]);

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
        $this->assertTrue($otherWebinar->fresh()->requiresVerification());
        $audit = AuditLog::query()->where('action', 'webinar.updated')->latest('id')->firstOrFail();
        $this->assertTrue($audit->metadata['verification_mode_changed']);
        $this->assertFalse($audit->metadata['requires_verification']);
        $this->actingAs($administrator)
            ->get(route('admin.webinars.edit', $webinar))
            ->assertOk()
            ->assertSee('Secure mode off')
            ->assertSee('impersonation is possible');
    }

    public function test_off_mode_records_registration_immediately_without_sending_a_link(): void
    {
        $webinar = Webinar::factory()->create(['requires_verification' => false]);
        $registration = $webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Registration',
            'status' => 'published',
        ]);

        $this->get($registration->shareUrl())
            ->assertOk()
            ->assertSee('About you')
            ->assertDontSee('Verify your email to continue');
        $this->post($registration->shareUrl(), [
            'full_name' => 'Trusted Guest',
            'email' => 'Guest@Example.com',
            'privacy_acknowledged' => '1',
        ])->assertRedirect(route('forms.public.thanks', $registration->public_token));

        $participant = Participant::query()->sole();
        $this->assertSame('guest@example.com', $participant->email);
        $this->assertNotNull($participant->verified_at);
        $this->assertNull($participant->email_verified_at);
        $this->assertDatabaseCount('participant_access_tokens', 0);
        $this->assertDatabaseCount('email_deliveries', 0);

        $this->post(route('forms.public.access.request', $registration->public_token), [
            'email' => 'guest@example.com',
        ])->assertNotFound();
        $this->assertSame(0, ParticipantAccessToken::query()->count());
    }

    public function test_off_mode_later_forms_accept_only_an_existing_registration(): void
    {
        $webinar = Webinar::factory()->create(['requires_verification' => false]);
        $registration = $webinar->forms()->create([
            'type' => 'registration', 'title' => 'Registration', 'status' => 'published',
        ]);
        $evaluation = $webinar->forms()->create([
            'type' => 'evaluation', 'title' => 'Evaluation', 'status' => 'published',
        ]);
        $this->post($registration->shareUrl(), [
            'full_name' => 'Registered Guest',
            'email' => 'guest@example.com',
            'privacy_acknowledged' => '1',
        ]);

        $this->post($evaluation->shareUrl(), [
            'full_name' => 'Attempted Rewrite',
            'email' => 'GUEST@example.com',
            'privacy_acknowledged' => '1',
        ])->assertRedirect(route('forms.public.thanks', $evaluation->public_token));

        $participant = Participant::query()->sole();
        $this->assertSame('Registered Guest', $participant->full_name);
        $this->assertSame(2, $participant->submissions()->count());
        $this->assertSame(2, Submission::query()->count());

        $this->from($evaluation->shareUrl())
            ->post($evaluation->shareUrl(), [
                'full_name' => 'Unknown Guest',
                'email' => 'unknown@example.com',
                'privacy_acknowledged' => '1',
            ])
            ->assertRedirect($evaluation->shareUrl())
            ->assertSessionHasErrors([
                'email' => PublicFormController::REGISTRATION_REQUIRED_MESSAGE,
            ])
            ->assertSessionMissing('_old_input.email');

        $this->assertDatabaseMissing('participants', ['email_normalized' => 'unknown@example.com']);
        $this->assertSame(2, Submission::query()->count());
    }
}
