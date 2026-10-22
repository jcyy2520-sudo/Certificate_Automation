<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\SendTransactionalEmail;
use App\Models\CertificateTemplate;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CertificateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class CertificateSetupTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->admin = User::factory()->create(['email' => 'admin@example.test']);
        $this->webinar = Webinar::factory()->create(['title' => 'Digital Integrity', 'created_by' => $this->admin->id]);
        $this->actingAs($this->admin);
    }

    private function artwork(): CertificateTemplate
    {
        $file = UploadedFile::fake()->image('certificate.png', 1200, 850);
        Storage::disk('local')->put('certificate-backgrounds/setup.png', file_get_contents($file->getRealPath()));

        return $this->webinar->certificateTemplates()->create(['name' => 'Existing saved name', 'is_active' => true, 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/setup.png', 'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 36, 'name_font_family' => 'sans']]);
    }

    public function test_setup_has_no_eligibility_or_template_name_inputs_and_get_never_writes(): void
    {
        $this->webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
        $this->get(route('admin.certification.edit', $this->webinar))->assertOk()->assertSee('Certificate Setup')
            ->assertSee('Email Configuration')->assertDontSee('name="name"', false)->assertDontSee('rules[', false)->assertDontSee('Certificate eligibility');
        $this->assertDatabaseCount('certificate_templates', 0);
        $this->assertDatabaseHas('eligibility_rules', ['requirement' => 'registration']);
        $this->get(route('admin.webinars.edit', $this->webinar))->assertOk()->assertDontSee('name="certificate_email_subject"', false);
    }

    public function test_artwork_save_generates_name_and_title_changes_keep_identifiers_and_design(): void
    {
        $template = $this->artwork();
        $this->put(route('admin.certification.template', $this->webinar), ['name' => 'Ignored manual name'])->assertSessionHasNoErrors();
        $this->assertSame('Digital Integrity — Certificate of Participation', $template->fresh()->name);
        $path = $template->background_path;
        $layout = $template->layout;
        $this->webinar->update(['title' => 'Renamed Digital Integrity']);
        $this->get(route('admin.certification.edit', $this->webinar))->assertOk()->assertSee('Renamed Digital Integrity — Certificate of Participation');
        $this->assertSame($path, $template->fresh()->background_path);
        $this->assertSame($layout, $template->fresh()->layout);
        $this->assertDatabaseCount('certificate_templates', 1);
        $this->assertSame('Digital Integrity — Certificate of Participation', $template->fresh()->name, 'Reading the display name must not mutate stored history.');
    }

    public function test_new_template_upload_requires_no_manual_name(): void
    {
        $this->put(route('admin.certification.template', $this->webinar), ['background' => UploadedFile::fake()->image('new.png', 1200, 850)])->assertSessionHasNoErrors();
        $this->assertSame('Digital Integrity — Certificate of Participation', $this->webinar->certificateTemplates()->sole()->name);
    }

    public function test_defaults_and_both_placeholder_styles_resolve_as_plain_text(): void
    {
        $this->assertSame('Certificate of Participation — Digital Integrity', $this->webinar->certificateEmailSubject('Maria Santos'));
        $this->assertStringContainsString('Dear Maria Santos,', $this->webinar->certificateEmailMessage('Maria Santos'));
        $template = $this->artwork();
        $template->update(['layout' => $template->layout + ['certificate_type' => 'Certificate of Completion']]);
        $this->webinar->update(['certificate_email_subject' => '{{certificate_type}} for {{participant_name}}: {webinar}', 'certificate_email_message' => "Dear {participant},\n{{webinar_title}}\n{{certificate_type}}"]);
        $this->assertSame('Certificate of Completion for Maria Santos: Digital Integrity', $this->webinar->certificateEmailSubject('Maria Santos'));
        $this->assertSame("Dear Maria Santos,\nDigital Integrity\nCertificate of Completion", $this->webinar->certificateEmailMessage('Maria Santos'));
    }

    public function test_email_save_is_webinar_specific_and_normal_webinar_edits_preserve_copy(): void
    {
        $other = Webinar::factory()->create(['certificate_email_subject' => 'Other subject', 'certificate_email_message' => 'Other message']);
        $this->post(route('admin.certification.email', $this->webinar), ['certificate_email_subject' => 'Award — {{participant_name}}', 'certificate_email_message' => "Dear {{participant_name}},\nThank you for {{webinar_title}}."])->assertSessionHasNoErrors();
        $saved = $this->webinar->fresh()->only('certificate_email_subject', 'certificate_email_message');
        $this->put(route('admin.webinars.update', $this->webinar), ['title' => 'Updated title', 'status' => 'published', 'ends_at' => now()->addDay()->format('Y-m-d H:i:s'), 'data_retention_days' => 7])->assertSessionHasNoErrors();
        $this->assertSame($saved, $this->webinar->fresh()->only(array_keys($saved)));
        $this->assertSame('Other subject', $other->fresh()->certificate_email_subject);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_preview_resolves_unsaved_copy_escapes_html_and_creates_no_records(): void
    {
        $this->post(route('admin.certification.email-preview', $this->webinar), ['certificate_email_subject' => 'For {{participant_name}} — {{webinar_title}}', 'certificate_email_message' => "Dear {{participant_name}},\n<script>bad()</script>\n{{certificate_type}}"])
            ->assertOk()->assertSee('For Sample Participant — Digital Integrity')->assertSee('Sample Participant')
            ->assertSee('&lt;script&gt;bad()&lt;/script&gt;', false)->assertDontSee('<script>bad()</script>', false)->assertSee('certificate.pdf')->assertSee('Sender:');
        $this->assertNull($this->webinar->fresh()->certificate_email_subject);
        $this->assertDatabaseCount('certificate_templates', 0);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        Queue::assertNothingPushed();
    }

    public function test_invalid_placeholders_and_subject_line_breaks_are_actionable_errors(): void
    {
        $this->post(route('admin.certification.email', $this->webinar), ['certificate_email_subject' => "Subject\nBcc: other@example.test"])->assertSessionHasErrors('certificate_email_subject');
        $this->post(route('admin.certification.email', $this->webinar), ['certificate_email_message' => 'Dear {{unavailable_value}}'])->assertSessionHasErrors('certificate_email_message');
        $this->assertNull($this->webinar->fresh()->certificate_email_subject);
    }

    public function test_sample_email_is_admin_only_confirmed_idempotent_and_has_a_real_sample_pdf(): void
    {
        $this->artwork();
        $key = (string) Str::uuid();
        $url = route('admin.certification.email-test', $this->webinar);
        $this->post($url, ['test_key' => $key])->assertSessionHasErrors('confirmed');
        $this->post($url, ['test_key' => $key, 'confirmed' => '1', 'recipient' => 'someone-else@example.test'])->assertSessionHas('success');
        $delivery = EmailDelivery::query()->sole();
        $this->assertSame('admin@example.test', $delivery->recipient_email);
        $this->assertSame('certificate_test', $delivery->type);
        $this->assertSame($this->webinar->id, $delivery->webinar_id);
        $this->assertNull($delivery->participant_id);
        $this->assertNull($delivery->certificate_id);
        $this->assertStringStartsWith('[TEST] ', $delivery->subject);
        $this->assertStringContainsString('Dear Sample Participant,', $delivery->payload['html']);
        $this->assertStringStartsWith('%PDF', base64_decode($delivery->payload['attachments'][0]['content']));
        $this->post($url, ['test_key' => $key, 'confirmed' => '1'])->assertSessionHas('warning');
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('participants', 0);
        Queue::assertPushed(SendTransactionalEmail::class, 1);
    }

    public function test_sample_delivery_uses_existing_worker_and_duplicate_execution_does_not_send_again(): void
    {
        $this->artwork();
        $this->post(route('admin.certification.email-test', $this->webinar), ['test_key' => (string) Str::uuid(), 'confirmed' => '1'])->assertSessionHas('success');
        $delivery = EmailDelivery::query()->sole();
        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->with('admin@example.test', $delivery->subject, $delivery->payload['html'], $delivery->payload['attachments'], $delivery->idempotency_key)->andReturn('sample-provider-id');
        $job = new SendTransactionalEmail($delivery->id);
        $job->handle($mailer);
        $job->handle($mailer);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame('sample-provider-id', $delivery->fresh()->provider_message_id);
    }

    public function test_certificate_delivery_keeps_personalized_message_and_the_correct_pdf(): void
    {
        $this->artwork();
        $this->webinar->update(['certificate_email_subject' => '{{participant_name}} — {{webinar_title}}', 'certificate_email_message' => "Dear {{participant_name}},\nYour {{certificate_type}} is attached."]);
        $participant = $this->webinar->participants()->create(['email' => 'recipient@example.test', 'full_name' => 'Recipient Name', 'verified_at' => now()]);
        $this->webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
        $this->post(route('admin.responses.approve', $this->webinar), ['participants' => [$participant->public_id], 'confirmed' => '1'])->assertSessionHasNoErrors();
        $certificate = app(CertificateService::class)->issue($participant);
        $delivery = $certificate->deliveries()->sole();
        $this->assertSame('recipient@example.test', $delivery->recipient_email);
        $this->assertSame('Recipient Name — Digital Integrity', $delivery->subject);
        $this->assertStringContainsString('Dear Recipient Name,', $delivery->payload['html']);
        $this->assertSame(Storage::disk('local')->get($certificate->file_path), base64_decode($delivery->payload['attachments'][0]['content']));
    }

    public function test_settings_and_test_delivery_keep_password_and_admin_gates(): void
    {
        config(['security.require_sensitive_action_password_confirmation' => true]);
        $this->post(route('admin.certification.email', $this->webinar), ['certificate_email_subject' => 'Changed'])->assertRedirect(route('admin.password.confirm'));
        $this->post(route('admin.certification.email-test', $this->webinar), ['test_key' => (string) Str::uuid(), 'confirmed' => '1'])->assertRedirect(route('admin.password.confirm'));
        $this->assertNull($this->webinar->fresh()->certificate_email_subject);
        $this->assertDatabaseCount('email_deliveries', 0);
        auth()->logout();
        $this->post(route('admin.certification.email-preview', $this->webinar))->assertRedirect(route('login'));
    }

    public function test_draft_test_email_can_retry_a_provider_failure_on_the_same_delivery(): void
    {
        $this->webinar = Webinar::factory()->draft()->create(['created_by' => $this->admin->id]);
        $this->assertNull($this->webinar->retention_due_at);
        $this->artwork();
        $this->post(route('admin.certification.email-test', $this->webinar), ['test_key' => (string) Str::uuid(), 'confirmed' => '1'])->assertSessionHas('success');
        $delivery = EmailDelivery::query()->sole();
        $failing = \Mockery::mock(TransactionalMailer::class);
        $failing->shouldReceive('send')->once()->andThrow(new \RuntimeException('Synthetic provider outage'));
        try {
            (new SendTransactionalEmail($delivery->id))->handle($failing);
            $this->fail('Provider error should be retried.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic provider outage', $exception->getMessage());
        }
        $this->assertSame('pending', $delivery->fresh()->status);
        $success = \Mockery::mock(TransactionalMailer::class);
        $success->shouldReceive('send')->once()->withArgs(fn ($to, $subject, $html, $attachments, $key) => $to === $this->admin->email && $key === $delivery->idempotency_key)->andReturn('retried-sample');
        (new SendTransactionalEmail($delivery->id))->handle($success);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_expired_samples_are_cancelled_and_missing_artwork_creates_no_delivery(): void
    {
        $url = route('admin.certification.email-test', $this->webinar);
        $this->post($url, ['test_key' => (string) Str::uuid(), 'confirmed' => '1'])->assertSessionHas('error');
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->artwork();
        $this->post($url, ['test_key' => (string) Str::uuid(), 'confirmed' => '1'])->assertSessionHas('success');
        $delivery = EmailDelivery::query()->sole();
        $this->travel(16)->minutes();
        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldNotReceive('send');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->payload);
    }

    public function test_preview_sender_matches_the_selected_provider_without_contacting_it(): void
    {
        config(['webinar.email.provider' => 'brevo', 'webinar.email.certificate_provider' => 'brevo', 'mail.from.name' => 'Fallback sender', 'services.brevo.from_name' => 'Configured Organizer', 'services.brevo.from_address' => 'organizer@example.test']);
        $this->get(route('admin.certification.email-preview', $this->webinar))->assertOk()->assertSee('Configured Organizer')->assertSee('organizer@example.test')->assertDontSee('Fallback sender');
        $this->assertDatabaseCount('email_deliveries', 0);
    }
}
