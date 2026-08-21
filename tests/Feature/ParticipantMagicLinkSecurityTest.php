<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\Form;
use App\Models\Participant;
use App\Models\ParticipantAccessToken;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\ParticipantMagicLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class ParticipantMagicLinkSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webinar.participant_email_verification' => true,
            'webinar.verification_token_minutes' => 15,
            'webinar.participant_session_minutes' => 120,
        ]);

        $administrator = User::factory()->create(['is_active' => true]);
        $this->webinar = Webinar::factory()->create([
            'title' => 'Private Event Name',
            'slug' => 'private-event-name',
            'created_by' => $administrator->id,
        ]);
        $this->form = $this->webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Registration',
            'status' => 'published',
        ]);
    }

    public function test_a_submission_cannot_name_an_email_until_that_mailbox_is_verified(): void
    {
        $this->get($this->form->shareUrl())
            ->assertOk()
            ->assertSee('Verify your email to continue')
            ->assertDontSee('About you');

        $this->post($this->form->shareUrl(), [
            'full_name' => 'Impersonator',
            'email' => 'victim@example.com',
            'privacy_acknowledged' => '1',
        ])->assertRedirect($this->form->shareUrl());

        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_link_confirmation_is_scanner_safe_one_time_and_session_bound(): void
    {
        [$rawToken, $participant, $delivery] = $this->requestToken('Owner@Example.com');

        $this->assertNull($participant->email_verified_at);
        $this->assertSame(64, strlen(ParticipantAccessToken::query()->firstOrFail()->token_hash));
        $this->assertNotSame(hash('sha256', $rawToken), ParticipantAccessToken::query()->firstOrFail()->token_hash);
        $this->assertStringNotContainsString(
            $rawToken,
            (string) DB::table('email_deliveries')->whereKey($delivery->id)->value('payload'),
        );
        $this->assertDatabaseCount('audit_logs', 0);

        $confirmation = route('forms.public.access.confirm', $this->form->public_token);
        $this->get($confirmation)
            ->assertOk()
            ->assertSee('Continue to the form')
            ->assertDontSee($rawToken);
        $this->assertNull(ParticipantAccessToken::query()->firstOrFail()->fresh()->used_at);

        Session::start();
        $oldSessionId = Session::getId();
        $this->post(route('forms.public.access.consume', $this->form->public_token), [
            'access_token' => $rawToken,
        ])->assertRedirect($this->form->shareUrl());

        $this->assertNotSame($oldSessionId, Session::getId());
        $this->assertNotNull($participant->fresh()->email_verified_at);
        $this->assertNotNull(ParticipantAccessToken::query()->firstOrFail()->fresh()->used_at);
        $this->get($this->form->shareUrl())
            ->assertOk()
            ->assertSee('About you')
            ->assertSee('Verified for this secure session.');

        // Replaying the already-spent credential never creates a second grant.
        $this->post(route('forms.public.access.consume', $this->form->public_token), [
            'access_token' => $rawToken,
        ])->assertRedirect($this->form->shareUrl())
            ->assertSessionHas('participant_access_error');
    }

    public function test_a_token_is_bound_to_the_form_and_event_that_issued_it(): void
    {
        [$rawToken] = $this->requestToken('owner@example.com');

        $otherForm = $this->webinar->forms()->create([
            'type' => 'posttest',
            'title' => 'Other form',
            'status' => 'published',
        ]);

        $this->post(route('forms.public.access.consume', $otherForm->public_token), [
            'access_token' => $rawToken,
        ])->assertSessionHas('participant_access_error');
        $this->assertNull(ParticipantAccessToken::query()->firstOrFail()->used_at);

        $this->post(route('forms.public.access.consume', $this->form->public_token), [
            'access_token' => $rawToken,
        ])->assertRedirect($this->form->shareUrl());
    }

    public function test_expired_and_malformed_tokens_fail_identically_without_being_flashed(): void
    {
        [$rawToken] = $this->requestToken('owner@example.com');
        ParticipantAccessToken::query()->update(['expires_at' => now()->subSecond()]);

        foreach ([$rawToken, 'not-a-token'] as $candidate) {
            $this->post(route('forms.public.access.consume', $this->form->public_token), [
                'access_token' => $candidate,
            ])->assertRedirect($this->form->shareUrl())
                ->assertSessionHas('participant_access_error')
                ->assertSessionMissing('_old_input.access_token');
        }

        $this->assertNull(ParticipantAccessToken::query()->firstOrFail()->used_at);
    }

    public function test_verified_email_cannot_be_replaced_in_the_submission_request(): void
    {
        [$rawToken, $participant] = $this->requestToken('owner@example.com');
        $this->post(route('forms.public.access.consume', $this->form->public_token), [
            'access_token' => $rawToken,
        ]);

        $this->from($this->form->shareUrl())->post($this->form->shareUrl(), [
            'full_name' => 'Attacker Supplied Name',
            'email' => 'victim@example.com',
            'privacy_acknowledged' => '1',
        ])->assertSessionHasErrors('email');
        $this->assertDatabaseCount('submissions', 0);

        $this->post($this->form->shareUrl(), [
            'full_name' => 'Mailbox Owner',
            'email' => 'OWNER@example.com',
            'privacy_acknowledged' => '1',
        ])->assertRedirect(route('forms.public.thanks', $this->form->public_token));

        $this->assertSame($participant->id, Submission::query()->firstOrFail()->participant_id);
        $this->assertSame('Mailbox Owner', $participant->fresh()->full_name);
        $this->assertNotNull($participant->fresh()->verified_at);
    }

    public function test_requests_are_generic_rate_limited_and_the_email_discloses_no_event_or_address(): void
    {
        Queue::fake();

        foreach (range(1, 4) as $attempt) {
            $response = $this->post(route('forms.public.access.request', $this->form->public_token), [
                'email' => 'target@example.com',
            ]);

            if ($attempt <= 3) {
                $response->assertRedirect($this->form->shareUrl())
                    ->assertSessionHas('participant_access_requested');
            } else {
                $response->assertTooManyRequests();
            }
        }

        // A resend cannot invalidate the earlier email and deny access to its
        // owner. Once any one link is spent, all siblings are revoked together.
        $this->assertSame(3, ParticipantAccessToken::query()->whereNull('used_at')->count());
        Queue::assertPushed(SendTransactionalEmail::class, 3);

        $delivery = EmailDelivery::query()->oldest('id')->firstOrFail();
        $html = (string) ($delivery->payload['html'] ?? '');
        $this->assertSame('Your secure form access link', $delivery->subject);
        $this->assertStringNotContainsString('target@example.com', $html);
        $this->assertStringNotContainsString('Private Event Name', $html);

        preg_match('/#token=([a-f0-9]{64})/', $html, $matches);
        $this->post(route('forms.public.access.consume', $this->form->public_token), [
            'access_token' => $matches[1],
        ])->assertRedirect($this->form->shareUrl());
        $this->assertSame(0, ParticipantAccessToken::query()->whereNull('used_at')->count());
    }

    public function test_abandoned_records_and_credentials_are_pruned(): void
    {
        Queue::fake();
        config(['webinar.unverified_participant_retention_minutes' => 60]);
        [$rawToken, $participant] = $this->requestToken('abandoned@example.com');

        $participant->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
        ParticipantAccessToken::query()->update(['expires_at' => now()->subHour()]);

        $this->artisan('security:prune-participant-access')
            ->assertSuccessful()
            ->expectsOutput('1 token(s) and 1 participant(s) deleted.');

        $this->assertDatabaseMissing('participants', ['id' => $participant->id]);
        $this->assertDatabaseCount('participant_access_tokens', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('audit_logs', 0);
        $this->assertNotEmpty($rawToken);
    }

    public function test_concurrent_unused_links_are_bounded_without_invalidating_every_resend(): void
    {
        Queue::fake();
        config(['webinar.maximum_live_verification_tokens' => 3]);

        foreach (range(1, 5) as $request) {
            app(ParticipantMagicLinkService::class)->request($this->form, 'bounded@example.com');
        }

        $this->assertSame(5, ParticipantAccessToken::query()->count());
        $this->assertSame(3, ParticipantAccessToken::query()->whereNull('used_at')->count());
        $this->assertSame(2, ParticipantAccessToken::query()->whereNotNull('used_at')->count());
        Queue::assertPushed(SendTransactionalEmail::class, 5);
    }

    public function test_confirmation_post_is_in_the_csrf_protected_web_stack(): void
    {
        $route = app('router')->getRoutes()->getByName('forms.public.access.consume');

        $this->assertNotNull($route);
        // Laravel's `web` stack contains ValidateCsrfToken. Keeping this route in
        // that stack is the invariant; tests intentionally bypass token checking.
        $this->assertContains('web', $route->middleware());
    }

    /** @return array{string, Participant, EmailDelivery} */
    private function requestToken(string $email): array
    {
        Queue::fake();

        $this->post(route('forms.public.access.request', $this->form->public_token), [
            'email' => $email,
        ])->assertRedirect($this->form->shareUrl())
            ->assertSessionHas('participant_access_requested');

        $participant = Participant::query()->where('email', strtolower($email))->firstOrFail();
        $delivery = EmailDelivery::query()->latest('id')->firstOrFail();
        $html = (string) ($delivery->payload['html'] ?? '');

        preg_match('/#token=([a-f0-9]{64})/', $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'The queued email did not contain a fragment credential.');

        return [$matches[1], $participant, $delivery];
    }
}
