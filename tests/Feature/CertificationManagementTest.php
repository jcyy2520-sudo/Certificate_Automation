<?php

namespace Tests\Feature;

use App\Jobs\IssueSelectedCertificate;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\CertificateTemplate;
use App\Models\EligibilityOverride;
use App\Models\EligibilityRule;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CertificationManagementTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->webinar = Webinar::query()->create([
            'title' => 'Data Privacy Briefing', 'slug' => 'data-privacy-briefing',
            'status' => 'published', 'timezone' => 'UTC',
            'ends_at' => now()->addDay(), 'data_retention_days' => 7,
            'created_by' => $this->administrator->id,
        ]);
    }

    public function test_creating_a_webinar_provisions_its_forms_rule_and_template(): void
    {
        $this->actingAs($this->administrator)->post(route('admin.webinars.store'), [
            'title' => 'Incident Response Drill',
            'status' => 'draft',
            'timezone' => 'UTC',
            'data_retention_days' => 14,
        ])->assertRedirect();

        $webinar = Webinar::query()->where('slug', 'incident-response-drill')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['registration', 'pretest', 'posttest', 'evaluation'],
            $webinar->forms->pluck('type')->all(),
        );
        $this->assertSame(1, $webinar->eligibilityRules()->where('requirement', 'registration')->count());
        $this->assertSame(1, $webinar->certificateTemplates()->where('is_active', true)->count());
    }

    public function test_requirements_can_be_enabled_scored_and_removed(): void
    {
        $this->actingAs($this->administrator)->put(route('admin.certification.rules', $this->webinar), [
            'rules' => [
                'registration' => ['enabled' => '1'],
                'posttest' => ['enabled' => '1', 'minimum_score' => '7.5'],
                'evaluation' => ['minimum_score' => '3'],
            ],
        ])->assertRedirect();

        $rules = $this->webinar->eligibilityRules()->get()->keyBy('requirement');

        $this->assertEqualsCanonicalizing(['registration', 'posttest'], $rules->keys()->all());
        $this->assertNull($rules['registration']->minimum_score, 'Registration never carries a score threshold.');
        $this->assertEquals(7.5, (float) $rules['posttest']->minimum_score);

        // Unchecking a requirement removes it.
        $this->actingAs($this->administrator)->put(route('admin.certification.rules', $this->webinar), [
            'rules' => ['registration' => ['enabled' => '1']],
        ])->assertRedirect();

        $this->assertSame(['registration'], $this->webinar->eligibilityRules()->pluck('requirement')->all());
    }

    public function test_the_uploaded_certificate_is_saved_and_previewable(): void
    {
        Storage::fake('local');

        $this->actingAs($this->administrator)->get(route('admin.certification.edit', $this->webinar))->assertOk();

        // The template page now handles only the name and the uploaded image;
        // the name's placement/font/colour are set in the visual editor.
        $this->actingAs($this->administrator)->put(route('admin.certification.template', $this->webinar), [
            'name' => 'Formal award',
            'background' => UploadedFile::fake()->image('certificate.png', 1200, 850),
        ])->assertRedirect();

        $template = $this->webinar->certificateTemplates()->where('is_active', true)->firstOrFail();
        $this->assertSame('Formal award', $template->name);
        $this->assertNotNull($template->background_path);
        Storage::disk('local')->assertExists($template->background_path);
        // The image's real dimensions are remembered so the PDF preserves the ratio.
        $this->assertSame(1200, (int) $template->layout['bg_w']);
        $this->assertSame(850, (int) $template->layout['bg_h']);
        // The removed "generated design" fields must not linger in the layout.
        $this->assertArrayNotHasKey('heading', $template->layout);
        $this->assertArrayNotHasKey('signatory_name', $template->layout);

        // Saving the visual placement stores position, font, size, and colour.
        $this->actingAs($this->administrator)->put(route('admin.certification.design', $this->webinar), [
            'name_top' => 58, 'name_left' => 50, 'name_font_size' => 48,
            'name_font_family' => 'serif', 'accent' => '#7c3aed',
        ])->assertRedirect();
        $template->refresh();
        $this->assertSame('#7c3aed', $template->layout['accent']);
        $this->assertSame(58, (int) $template->layout['name_top']);
        $this->assertSame('serif', $template->layout['name_font_family']);

        $response = $this->actingAs($this->administrator)->get(route('admin.certification.preview', $this->webinar));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // A typed name can be previewed for exact spelling.
        $this->actingAs($this->administrator)
            ->get(route('admin.certification.preview', $this->webinar, ['name' => 'Corrected Name']))
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // A preview must never persist a certificate.
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_a_certificate_image_is_required_before_saving(): void
    {
        $this->actingAs($this->administrator)->put(route('admin.certification.template', $this->webinar), [
            'name' => 'No image yet',
        ])->assertSessionHasErrors('background');
    }

    public function test_preview_is_refused_until_a_certificate_is_uploaded(): void
    {
        $this->actingAs($this->administrator)
            ->get(route('admin.certification.preview', $this->webinar))
            ->assertRedirect(route('admin.certification.edit', $this->webinar))
            ->assertSessionHas('error');
    }

    public function test_an_invalid_accent_colour_is_rejected(): void
    {
        $this->actingAs($this->administrator)->put(route('admin.certification.design', $this->webinar), [
            'name_top' => 60, 'name_left' => 50, 'name_font_size' => 42,
            'name_font_family' => 'sans', 'accent' => 'red',
        ])->assertSessionHasErrors('accent');
    }

    public function test_a_participant_name_is_corrected_before_issuing(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Maria Snatos', 'email' => 'maria@example.com', 'verified_at' => now(),
        ]);

        $this->actingAs($this->administrator)
            ->putJson(route('admin.participants.name', [$this->webinar, $participant]), ['full_name' => 'Maria Santos'])
            ->assertOk()
            ->assertJsonPath('participant_id', $participant->public_id)
            ->assertJsonPath('full_name', 'Maria Santos');

        $this->assertSame('Maria Santos', $participant->fresh()->full_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'participant.name_corrected']);

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.store', [$this->webinar, $participant]), [
                'recipient_name' => 'Certificate-only overrides are ignored',
            ])
            ->assertRedirect();

        $certificate = Certificate::query()->where('participant_id', $participant->id)->firstOrFail();
        $this->assertSame('Maria Santos', $certificate->recipient_name);
        $this->assertSame('issued', $certificate->status);
        Storage::disk('local')->assertExists($certificate->file_path);
    }

    public function test_selected_eligible_participants_are_sent_certificates_from_the_studio(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();
        $eligible = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Wrong Spelling', 'email' => 'wei@example.com', 'verified_at' => now(),
        ]);
        $notEligible = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Not Verified', 'email' => 'nv@example.com',
        ]);

        // The studio lists only eligible participants and stays in the section.
        $this->actingAs($this->administrator)->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk()
            ->assertSee('Wrong Spelling')
            ->assertDontSee('Not Verified');

        $this->actingAs($this->administrator)
            ->put(route('admin.participants.name', [$this->webinar, $eligible]), ['full_name' => 'Wei Chen'])
            ->assertRedirect();

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.issue-selected', $this->webinar), [
                'participants' => [$eligible->public_id, $notEligible->public_id],
                'preview_confirmed' => '1',
            ])
            ->assertRedirect(route('admin.certificates.studio', $this->webinar))
            ->assertSessionHas('success');

        $certificate = Certificate::query()->where('participant_id', $eligible->id)->firstOrFail();
        $this->assertSame('Wei Chen', $eligible->fresh()->full_name);
        $this->assertSame('Wei Chen', $certificate->recipient_name);
        $this->assertSame('issued', $certificate->status);
        // The ineligible participant is skipped, not certified.
        $this->assertSame(0, Certificate::query()->where('participant_id', $notEligible->id)->count());
    }

    public function test_selected_certificates_are_queued_without_rendering_in_the_browser_request(): void
    {
        Storage::fake('local');
        Queue::fake();

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => 'Queued Person',
            'email' => 'queued@example.com',
            'verified_at' => now(),
        ]);

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.issue-selected', $this->webinar), [
                'participants' => [$participant->public_id],
                'preview_confirmed' => '1',
            ])
            ->assertRedirect(route('admin.certificates.studio', $this->webinar));

        $certificate = Certificate::query()->where('participant_id', $participant->id)->sole();
        $this->assertSame('processing', $certificate->status);
        Storage::disk('local')->assertMissing($certificate->file_path);
        $this->assertDatabaseCount('email_deliveries', 0);
        Queue::assertPushed(IssueSelectedCertificate::class, fn ($job) => $job->participantId === $participant->id);
    }

    public function test_saved_name_placement_survives_a_reload_of_the_editor(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();
        Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Ready Person', 'email' => 'ready@example.com', 'verified_at' => now(),
        ]);

        $this->actingAs($this->administrator)->put(route('admin.certification.design', $this->webinar), [
            'name_top' => 58, 'name_left' => 40, 'name_font_size' => 50,
            'name_font_family' => 'serif', 'accent' => '#123456',
        ])->assertRedirect();

        // Re-opening the editor shows those exact values in its controls.
        $this->actingAs($this->administrator)->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk()
            ->assertSee('name="name_top" value="58"', false)
            ->assertSee('name="name_left" value="40"', false)
            ->assertSee('value="50"', false)
            ->assertSee('value="#123456"', false);
    }

    public function test_an_off_platform_recipient_is_added_and_selected_from_the_participant_table(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();

        // Someone who attended but never registered here.
        $addResponse = $this->actingAs($this->administrator)
            ->post(route('admin.participants.store', $this->webinar), [
                'full_name' => 'Off List Person', 'email' => 'Off@Example.com', 'organization' => 'Community Group',
            ]);

        $participant = Participant::query()->where('email', 'off@example.com')->firstOrFail();
        $addResponse->assertRedirect(route('admin.participants.index', $this->webinar))
            ->assertSessionHas('success')
            ->assertSessionHas('new_participant_public_id', $participant->public_id);
        $this->assertSame('Off List Person', $participant->full_name);
        $this->assertSame('Community Group', $participant->organization);
        $this->assertSame(1, $participant->eligibilityOverrides()->where('decision', 'eligible')->count());

        // They are selected in the table, then flow into the normal studio.
        $this->actingAs($this->administrator)->get(route('admin.participants.index', $this->webinar))
            ->assertOk()
            ->assertSee('Off List Person')
            ->assertSee('data-auto-selected checked', false);
        $this->actingAs($this->administrator)->get(route('admin.certificates.studio', [
            'webinar' => $this->webinar,
            'participants' => [$participant->public_id],
        ]))->assertOk()->assertSee('data-initial-participant="'.$participant->public_id.'"', false);

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.issue-selected', $this->webinar), [
                'participants' => [$participant->public_id],
                'preview_confirmed' => '1',
            ])
            ->assertRedirect()->assertSessionHas('success');

        $certificate = Certificate::query()->where('participant_id', $participant->id)->sole();
        $delivery = EmailDelivery::query()->where('certificate_id', $certificate->id)->sole();
        $this->assertSame('off@example.com', $delivery->recipient_email);
        $this->assertSame('certificate', $delivery->type);
        $this->assertSame('certificate.pdf', $delivery->payload['attachments'][0]['name']);
        $this->assertNotEmpty($delivery->payload['attachments'][0]['content']);
    }

    public function test_empty_studio_sends_the_administrator_back_to_the_participant_table_and_sending_requires_preview(): void
    {
        Storage::fake('local');
        $this->uploadedTemplate();

        $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk()
            ->assertSee('Open participants')
            ->assertDontSee('Add certificate recipient manually')
            ->assertDontSee('/certificates/add-recipient', false);

        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => 'Preview Required',
            'email' => 'preview@example.com',
        ]);
        EligibilityOverride::query()->create([
            'participant_id' => $participant->id,
            'decision' => 'eligible',
            'reason' => 'Manual test recipient.',
            'overridden_by' => $this->administrator->id,
        ]);

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.issue-selected', $this->webinar), [
                'participants' => [$participant->public_id],
            ])
            ->assertSessionHasErrors('preview_confirmed');

        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_a_failed_certificate_delivery_can_be_resent(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Ken Adams', 'email' => 'ken@example.com', 'verified_at' => now(),
        ]);

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.store', [$this->webinar, $participant]))
            ->assertRedirect();
        $certificate = Certificate::query()->where('participant_id', $participant->id)->firstOrFail();

        // Simulate the delivery having failed.
        EmailDelivery::query()->where('certificate_id', $certificate->id)->update(['status' => 'failed', 'failed_at' => now()]);
        $before = EmailDelivery::query()->where('certificate_id', $certificate->id)->count();

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.resend', $certificate))
            ->assertRedirect()->assertSessionHas('success');

        $this->assertGreaterThan($before, EmailDelivery::query()->where('certificate_id', $certificate->id)->count());
    }

    public function test_issuance_is_refused_until_a_certificate_is_uploaded(): void
    {
        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        // A default template exists but carries no uploaded design.
        $this->webinar->certificateTemplates()->create([
            'name' => 'Empty', 'storage_disk' => 'local', 'template_path' => 'uploaded',
            'layout' => ['accent' => '#1d4ed8'], 'is_active' => true,
        ]);
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Ana Cruz', 'email' => 'ana@example.com', 'verified_at' => now(),
        ]);

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.store', [$this->webinar, $participant]))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_bulk_issuance_certifies_eligible_participants_and_skips_the_rest(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();

        $eligible = collect(['ana@example.com', 'ben@example.com'])->map(fn ($email) => Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Verified '.$email, 'email' => $email, 'verified_at' => now(),
        ]));
        $unverified = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Unverified', 'email' => 'carl@example.com',
        ]);

        // QUEUE_CONNECTION is sync in tests, so the job runs inline.
        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.batch', $this->webinar))
            ->assertRedirect()
            ->assertSessionHas('success');

        $batch = CertificateBatch::query()->firstOrFail();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(3, $batch->total_count);
        $this->assertSame(2, $batch->completed_count);
        $this->assertSame(1, $batch->failed_count);

        foreach ($eligible as $participant) {
            $certificate = Certificate::query()->where('participant_id', $participant->id)->firstOrFail();
            $this->assertSame($batch->id, $certificate->certificate_batch_id);
            Storage::disk('local')->assertExists($certificate->file_path);
        }

        $this->assertSame(0, Certificate::query()->where('participant_id', $unverified->id)->count());

        // A second run finds nothing left to do.
        $this->actingAs($this->administrator)->post(route('admin.certificates.batch', $this->webinar))->assertRedirect();
        $this->assertSame(2, Certificate::query()->count());
    }

    public function test_bulk_issuance_is_refused_without_an_active_template(): void
    {
        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.batch', $this->webinar))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('certificate_batches', 0);
    }

    public function test_a_revoked_certificate_reports_as_invalid_publicly(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Ana Cruz', 'email' => 'ana@example.com', 'verified_at' => now(),
        ]);

        $this->actingAs($this->administrator)->post(route('admin.certificates.store', [$this->webinar, $participant]));
        $certificate = Certificate::query()->firstOrFail();

        $this->get(route('certificates.verify', $certificate->verification_code))->assertOk();

        $this->actingAs($this->administrator)->post(route('admin.certificates.revoke', $certificate), [
            'reason' => 'Issued to the wrong participant record.',
        ])->assertRedirect();

        $certificate->refresh();
        $this->assertSame('revoked', $certificate->status);
        $this->assertFalse($certificate->isPubliclyValid());
        $this->get(route('certificates.verify', $certificate->verification_code))
            ->assertOk()
            ->assertSee('Certificate revoked')
            ->assertDontSee('Issued to the wrong participant record.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.revoked']);

        // The organizer's own download link still works so the record stays auditable.
        $this->actingAs($this->administrator)
            ->get(route('admin.certificates.download', $certificate))
            ->assertOk();
    }

    /**
     * An active template with a real (tiny but valid) uploaded PNG on the faked
     * local disk, so issuance and rendering behave as they do in production.
     * Call after Storage::fake('local').
     */
    private function uploadedTemplate(): CertificateTemplate
    {
        $image = UploadedFile::fake()->image('certificate.png', 1000, 700);
        Storage::disk('local')->put('certificate-backgrounds/test.png', file_get_contents($image->getPathname()));

        return $this->webinar->certificateTemplates()->create([
            'name' => 'Uploaded certificate',
            'storage_disk' => 'local',
            'template_path' => 'uploaded',
            'background_path' => 'certificate-backgrounds/test.png',
            'layout' => ['accent' => '#1d4ed8', 'name_top' => 62, 'name_font_size' => 42],
            'is_active' => true,
        ]);
    }
}
