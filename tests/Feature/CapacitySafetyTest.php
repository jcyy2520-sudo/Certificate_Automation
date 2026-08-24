<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class CapacitySafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_hundreds_of_confirmation_requests_are_durable_and_queued_without_inline_email(): void
    {
        Queue::fake();
        $webinar = Webinar::factory()->create(['requires_verification' => true]);
        $form = $webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Capacity registration',
            'status' => 'published',
        ]);
        foreach (range(1, 250) as $number) {
            $this->post(route('forms.public.access.request', $form->public_token), [
                'email' => "capacity-{$number}@example.test",
            ])->assertRedirect($form->shareUrl().'?sent=1');
        }

        $this->assertSame(250, Participant::query()->count());
        $this->assertSame(250, EmailDelivery::query()->where('status', 'pending')->count());
        $this->assertSame(0, EmailDelivery::query()->whereNotNull('sent_at')->count());
        Queue::assertPushed(SendTransactionalEmail::class, 250);
    }

    public function test_hundreds_of_unique_form_submissions_are_recorded_without_identity_collisions(): void
    {
        $webinar = Webinar::factory()->create(['requires_verification' => false]);
        $form = $webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Capacity registration',
            'status' => 'published',
        ]);

        foreach (range(1, 200) as $number) {
            $this->post($form->shareUrl(), [
                'full_name' => "Capacity Participant {$number}",
                'email' => "capacity-{$number}@example.test",
                'privacy_acknowledged' => '1',
            ])->assertRedirect(route('forms.public.thanks', $form->public_token));
        }

        $this->assertSame(200, Participant::query()->count());
        $this->assertSame(200, Submission::query()->count());
        $this->assertSame(200, Participant::query()->distinct('email_normalized')->count('email_normalized'));
    }
}
