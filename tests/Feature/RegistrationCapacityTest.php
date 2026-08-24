<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationCapacityTest extends TestCase
{
    use RefreshDatabase;

    public function test_null_capacity_leaves_registration_unlimited(): void
    {
        [$webinar, $registration] = $this->registrationWebinar();

        foreach (range(1, 8) as $number) {
            $this->register($registration, "unlimited-{$number}@example.test")
                ->assertRedirect(route('forms.public.thanks', $registration->public_token));
        }

        $this->assertNull($webinar->fresh()->registration_capacity);
        $this->assertSame(8, Participant::query()->whereNotNull('verified_at')->count());
        $this->assertSame(8, Submission::query()->count());
        $this->get($registration->shareUrl())->assertOk()->assertDontSee('Registration is full.');
    }

    public function test_only_completed_non_erased_registrations_count_and_full_capacity_blocks_get_and_post(): void
    {
        [$webinar, $registration] = $this->registrationWebinar(2);
        Participant::query()->create([
            'webinar_id' => $webinar->id,
            'full_name' => 'Registered participant',
            'email' => 'registered@example.test',
            'verified_at' => now(),
        ]);
        Participant::query()->create([
            'webinar_id' => $webinar->id,
            'email' => 'link-only@example.test',
            'email_verified_at' => now(),
        ]);
        Participant::query()->create([
            'webinar_id' => $webinar->id,
            'email' => 'erased@example.test',
            'verified_at' => now(),
            'privacy_erased_at' => now(),
        ]);

        $this->get($registration->shareUrl())->assertOk()->assertDontSee('Registration is full.');
        $this->register($registration, 'last-place@example.test')->assertRedirect();

        $this->get($registration->shareUrl())
            ->assertOk()
            ->assertSee('Registration is full.');
        $this->register($registration, 'blocked@example.test')->assertForbidden();

        $this->assertDatabaseMissing('participants', ['email_normalized' => 'blocked@example.test']);
        $this->assertSame(2, Participant::query()
            ->whereNotNull('verified_at')
            ->whereNull('privacy_erased_at')
            ->count());
    }

    public function test_a_full_registration_form_does_not_block_later_webinar_forms(): void
    {
        [$webinar, $registration] = $this->registrationWebinar(1);
        $participant = Participant::query()->create([
            'webinar_id' => $webinar->id,
            'full_name' => 'Already Registered',
            'email' => 'registered@example.test',
            'verified_at' => now(),
        ]);
        $posttest = $webinar->forms()->create([
            'type' => 'posttest',
            'title' => 'Post-assessment',
            'status' => 'published',
        ]);

        $this->get($registration->shareUrl())->assertOk()->assertSee('Registration is full.');
        $this->get($posttest->shareUrl())->assertOk()->assertSee('Post-assessment');
        $this->post($posttest->shareUrl(), [
            'full_name' => $participant->full_name,
            'email' => $participant->email,
            'privacy_acknowledged' => '1',
        ])->assertRedirect(route('forms.public.thanks', $posttest->public_token));

        $this->assertDatabaseHas('submissions', [
            'form_id' => $posttest->id,
            'participant_id' => $participant->id,
        ]);
    }

    public function test_raising_capacity_while_registration_is_open_immediately_allows_another_registration(): void
    {
        [$webinar, $registration] = $this->registrationWebinar(1);
        Participant::query()->create([
            'webinar_id' => $webinar->id,
            'full_name' => 'First Participant',
            'email' => 'first@example.test',
            'verified_at' => now(),
        ]);
        $administrator = User::factory()->create();

        $this->get($registration->shareUrl())->assertOk()->assertSee('Registration is full.');
        $this->actingAs($administrator)
            ->put(route('admin.webinars.update', $webinar), $this->updatePayload($webinar, 2))
            ->assertRedirect(route('admin.webinars.show', $webinar));

        $this->assertSame(2, $webinar->fresh()->registration_capacity);
        $this->get($registration->shareUrl())->assertOk()->assertDontSee('Registration is full.');
        $this->register($registration, 'second@example.test')->assertRedirect();
        $this->assertSame(2, Participant::query()->whereNotNull('verified_at')->count());
    }

    private function registrationWebinar(?int $capacity = null): array
    {
        $webinar = Webinar::factory()->create([
            'requires_verification' => false,
            'registration_capacity' => $capacity,
        ]);
        $registration = $webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Registration',
            'status' => 'published',
        ]);

        return [$webinar, $registration];
    }

    private function register(Form $registration, string $email)
    {
        return $this->post($registration->shareUrl(), [
            'full_name' => 'Capacity Participant',
            'email' => $email,
            'privacy_acknowledged' => '1',
        ]);
    }

    /** @return array<string, mixed> */
    private function updatePayload(Webinar $webinar, ?int $capacity): array
    {
        return [
            'title' => $webinar->title,
            'description' => $webinar->description,
            'status' => $webinar->status,
            'starts_at' => $webinar->starts_at?->format('Y-m-d\TH:i'),
            'ends_at' => $webinar->ends_at?->format('Y-m-d\TH:i'),
            'registration_opens_at' => $webinar->registration_opens_at?->format('Y-m-d\TH:i'),
            'registration_closes_at' => $webinar->registration_closes_at?->format('Y-m-d\TH:i'),
            'registration_capacity' => $capacity,
            'timezone' => $webinar->timezone,
            'data_retention_days' => $webinar->data_retention_days,
            'requires_verification' => '0',
        ];
    }
}
