<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmail;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\EligibilityRule;
use App\Models\EmailDelivery;
use App\Models\Form;
use App\Models\Participant;
use App\Models\ParticipantAccessToken;
use App\Models\Submission;
use App\Models\Webinar;
use App\Services\EligibilityService;
use App\Services\ParticipantStatusLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ParticipantStatusPageTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    private Form $form;

    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'webinar.verification_token_minutes' => 15,
            'webinar.participant_session_minutes' => 120,
        ]);
        Queue::fake();

        $this->webinar = Webinar::factory()->create(['title' => 'Participant Status Event']);
        $this->form = $this->webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Registration',
            'status' => 'published',
        ]);
        $this->participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => 'Mailbox Owner',
            'email' => 'owner@example.com',
            'verified_at' => now(),
        ]);
    }

    public function test_unknown_and_existing_email_requests_have_the_same_public_response(): void
    {
        $route = route('forms.public.status.access.request', $this->form->public_token);

        $unknown = $this->post($route, ['email' => 'missing@example.com']);
        $unknown->assertRedirect(route('forms.public.status', ['token' => $this->form->public_token, 'sent' => 1]))
            ->assertSessionHas('participant_status_requested');
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('participant_access_tokens', 0);

        $existing = $this->post($route, ['email' => 'OWNER@example.com']);
        $existing->assertRedirect($unknown->headers->get('Location'))
            ->assertSessionHas('participant_status_requested');

        $this->assertSame($unknown->getStatusCode(), $existing->getStatusCode());
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertDatabaseCount('participant_access_tokens', 1);
        $this->assertDatabaseCount('participants', 1);
        Queue::assertPushed(SendTransactionalEmail::class, 1);
    }

    public function test_status_token_is_fragment_only_scanner_safe_and_single_use(): void
    {
        [$rawToken, $delivery] = $this->requestToken();

        $this->assertSame(ParticipantStatusLinkService::PURPOSE, ParticipantAccessToken::query()->firstOrFail()->purpose);
        $this->assertNull(ParticipantAccessToken::query()->firstOrFail()->form_id);
        $this->assertStringNotContainsString(
            $rawToken,
            (string) DB::table('email_deliveries')->whereKey($delivery->id)->value('payload'),
        );

        $confirmation = route('forms.public.status.access.confirm', $this->form->public_token);
        $this->get($confirmation)
            ->assertOk()
            ->assertSee('Continue to participant status')
            ->assertDontSee($rawToken);
        $this->assertNull(ParticipantAccessToken::query()->firstOrFail()->used_at);

        $this->post(route('forms.public.status.access.consume', $this->form->public_token), [
            'access_token' => $rawToken,
        ])->assertRedirect(route('forms.public.status.view', $this->form->public_token));

        $this->assertNotNull(ParticipantAccessToken::query()->firstOrFail()->fresh()->used_at);
        $this->get(route('forms.public.status.view', $this->form->public_token))->assertOk();

        $this->post(route('forms.public.status.access.consume', $this->form->public_token), [
            'access_token' => $rawToken,
        ])->assertRedirect(route('forms.public.status', $this->form->public_token))
            ->assertSessionHas('participant_status_error');
    }

    public function test_a_status_token_is_bound_to_its_webinar(): void
    {
        [$rawToken] = $this->requestToken();
        $otherWebinar = Webinar::factory()->create(['title' => 'Other Status Event']);
        $otherForm = $otherWebinar->forms()->create([
            'type' => 'registration',
            'title' => 'Other Registration',
            'status' => 'published',
        ]);

        $this->post(route('forms.public.status.access.consume', $otherForm->public_token), [
            'access_token' => $rawToken,
        ])->assertRedirect(route('forms.public.status', $otherForm->public_token))
            ->assertSessionHas('participant_status_error');

        $this->assertNull(ParticipantAccessToken::query()->firstOrFail()->used_at);
        $this->get(route('forms.public.status.view', $otherForm->public_token))
            ->assertRedirect(route('forms.public.status', $otherForm->public_token));
    }

    public function test_expired_and_malformed_status_tokens_are_rejected_identically(): void
    {
        [$rawToken] = $this->requestToken();
        ParticipantAccessToken::query()->update(['expires_at' => now()->subSecond()]);

        foreach ([$rawToken, 'not-a-token'] as $candidate) {
            $this->post(route('forms.public.status.access.consume', $this->form->public_token), [
                'access_token' => $candidate,
            ])->assertRedirect(route('forms.public.status', $this->form->public_token))
                ->assertSessionHas('participant_status_error')
                ->assertSessionMissing('_old_input.access_token');
        }

        $this->assertNull(ParticipantAccessToken::query()->firstOrFail()->used_at);
    }

    public function test_status_view_uses_the_eligibility_evaluation_and_shows_a_valid_certificate(): void
    {
        $forms = ['registration' => $this->form];
        foreach (['pretest', 'posttest', 'evaluation'] as $type) {
            $forms[$type] = $this->webinar->forms()->create([
                'type' => $type,
                'title' => ucfirst($type),
                'status' => 'published',
            ]);
        }

        foreach (['registration', 'attendance', 'pretest', 'posttest', 'evaluation'] as $requirement) {
            EligibilityRule::query()->create([
                'webinar_id' => $this->webinar->id,
                'requirement' => $requirement,
                'is_required' => true,
            ]);
        }

        $this->participant->update(['checked_in_at' => now()]);
        Submission::query()->create([
            'form_id' => $forms['pretest']->id,
            'participant_id' => $this->participant->id,
            'status' => 'submitted',
            'score' => 8,
            'maximum_score' => 10,
            'submitted_at' => now(),
        ]);
        $certificate = $this->issuedCertificate();
        $expected = app(EligibilityService::class)->evaluate($this->participant->fresh());
        [$rawToken] = $this->requestToken();

        $this->post(route('forms.public.status.access.consume', $this->form->public_token), [
            'access_token' => $rawToken,
        ]);
        $response = $this->get(route('forms.public.status.view', $this->form->public_token));

        $response->assertOk()
            ->assertViewHas('evaluation', $expected)
            ->assertSee('Registration completed')
            ->assertSee('Attendance recorded')
            ->assertSee('Pre-assessment completed')
            ->assertSee('Post-assessment completed')
            ->assertSee('Evaluation completed')
            ->assertSee('Still required')
            ->assertSee(
                route('forms.public.status.certificate.download', [$this->form->public_token, $certificate->public_id]),
                false,
            );

        $this->assertTrue($expected['requirements']['registration']);
        $this->assertTrue($expected['requirements']['attendance']);
        $this->assertTrue($expected['requirements']['pretest']);
        $this->assertFalse($expected['requirements']['posttest']);
        $this->assertFalse($expected['requirements']['evaluation']);
    }

    public function test_certificate_download_requires_the_matching_status_session(): void
    {
        Storage::fake('local');
        $certificate = $this->issuedCertificate();
        $path = 'certificates/'.$certificate->public_id.'.pdf';
        Storage::disk('local')->put($path, 'PRIVATE STATUS PDF');
        $certificate->update(['file_path' => $path]);
        $download = route('forms.public.status.certificate.download', [
            $this->form->public_token,
            $certificate->public_id,
        ]);

        $this->get($download)
            ->assertRedirect(route('forms.public.status', $this->form->public_token));

        [$rawToken] = $this->requestToken();
        $this->post(route('forms.public.status.access.consume', $this->form->public_token), [
            'access_token' => $rawToken,
        ]);

        $response = $this->get($download);
        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf');
        $this->assertStringContainsString('no-store', (string) $response->headers->get('cache-control'));
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'certificate.downloaded',
            'auditable_id' => $certificate->id,
        ]);
    }

    public function test_no_status_email_is_sent_without_an_explicit_request(): void
    {
        $this->participant->update(['checked_in_at' => now()]);

        $this->assertDatabaseCount('email_deliveries', 0);
        Queue::assertNothingPushed();
        $this->assertContains(
            'completed',
            SendTransactionalEmail::allowedWebinarStatusesFor(ParticipantStatusLinkService::DELIVERY_TYPE),
        );
    }

    /** @return array{string, EmailDelivery} */
    private function requestToken(): array
    {
        $this->post(route('forms.public.status.access.request', $this->form->public_token), [
            'email' => $this->participant->email,
        ])->assertRedirect(route('forms.public.status', ['token' => $this->form->public_token, 'sent' => 1]))
            ->assertSessionHas('participant_status_requested');

        $delivery = EmailDelivery::query()->latest('id')->firstOrFail();
        $html = (string) ($delivery->payload['html'] ?? '');
        preg_match('/#token=([a-f0-9]{64})/', $html, $matches);
        $this->assertArrayHasKey(1, $matches, 'The queued status email did not contain a fragment credential.');

        return [$matches[1], $delivery];
    }

    private function issuedCertificate(): Certificate
    {
        $template = CertificateTemplate::query()->create([
            'webinar_id' => $this->webinar->id,
            'name' => 'Status certificate',
            'storage_disk' => 'local',
            'template_path' => 'uploaded',
            'layout' => [],
            'is_active' => true,
        ]);

        return Certificate::query()->create([
            'verification_code' => 'CERT-STATUS-PAGE-001',
            'webinar_id' => $this->webinar->id,
            'participant_id' => $this->participant->id,
            'certificate_template_id' => $template->id,
            'recipient_name' => $this->participant->full_name,
            'storage_disk' => 'local',
            'status' => 'issued',
            'issued_at' => now(),
        ]);
    }
}
