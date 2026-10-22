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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\Concerns\ApprovesCertificateFixtures;
use Tests\TestCase;

class CertificationManagementTest extends TestCase
{
    use ApprovesCertificateFixtures;
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
        $this->assertSame(0, $webinar->eligibilityRules()->where('requirement', 'registration')->count());
        $this->assertEqualsCanonicalizing(
            ['pretest', 'posttest', 'evaluation'],
            $webinar->eligibilityRules()->where('is_required', true)->pluck('requirement')->all(),
        );
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
        $this->assertSame($this->webinar->title.' — Certificate of Participation', $template->name);
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
        ])->assertRedirect(route('admin.certification.edit', $this->webinar).'#participant-name-style');
        $template->refresh();
        $this->assertSame('#7c3aed', $template->layout['accent']);
        $this->assertSame(58, (int) $template->layout['name_top']);
        $this->assertSame('serif', $template->layout['name_font_family']);

        // The studio's "use for every recipient" action saves the same style
        // but returns straight back to the studio instead of the template page.
        $this->actingAs($this->administrator)->put(route('admin.certification.design', $this->webinar), [
            'name_top' => 30, 'name_left' => 50, 'name_font_size' => 48,
            'name_font_family' => 'serif', 'accent' => '#7c3aed', 'studio' => '1',
        ])->assertRedirect(route('admin.certificates.studio', $this->webinar));
        $template->refresh();
        $this->assertSame(30, (int) $template->layout['name_top']);

        $response = $this->actingAs($this->administrator)->get(route('admin.certification.preview', $this->webinar));
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent());

        // A typed name can be previewed for exact spelling.
        $this->actingAs($this->administrator)
            ->post(route('admin.certification.preview', $this->webinar), ['name' => 'Corrected Name'])
            ->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // A preview must never persist a certificate.
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_the_template_page_accepts_one_page_pdf_artwork_and_raw_pdfs_fail_closed(): void
    {
        Storage::fake('local');

        $this->actingAs($this->administrator)
            ->get(route('admin.certification.edit', $this->webinar))
            ->assertOk()
            ->assertSee('PNG, JPG, or one-page PDF')
            ->assertSee('application/pdf', false)
            ->assertSee('converted privately in this browser');

        // JavaScript converts the selected PDF to PNG before submission. If
        // conversion has not finished or was bypassed, the server rejects the
        // raw PDF rather than storing an unverified document as artwork.
        $this->actingAs($this->administrator)->put(route('admin.certification.template', $this->webinar), [
            'name' => 'Formal award',
            'background' => UploadedFile::fake()->create('certificate.pdf', 256, 'application/pdf'),
        ])->assertSessionHasErrors('background');

        $this->assertSame(0, $this->webinar->certificateTemplates()->count());
    }

    public function test_saving_certificate_style_never_redirects_to_the_raw_background_image(): void
    {
        Storage::fake('local');
        $template = $this->uploadedTemplate();

        // Loading the protected image after the settings page reproduces the
        // browser request order that can poison Laravel's session previous URL.
        $this->actingAs($this->administrator)
            ->get(route('admin.certification.edit', $this->webinar))
            ->assertOk();
        $this->actingAs($this->administrator)
            ->get(route('admin.certification.background', $this->webinar))
            ->assertOk();

        $this->actingAs($this->administrator)
            ->put(route('admin.certification.design', $this->webinar), [
                'name_top' => 58,
                'name_left' => 50,
                'name_font_size' => 48,
                'name_font_family' => 'serif',
                'accent' => '#7c3aed',
            ])
            ->assertRedirect(route('admin.certification.edit', $this->webinar).'#participant-name-style')
            ->assertSessionHas('success');

        $this->assertSame('#7c3aed', $template->fresh()->layout['accent']);
    }

    public function test_the_pdf_canvas_uses_the_same_point_units_as_the_pdf_page(): void
    {
        $certificate = new Certificate([
            'verification_code' => 'CERT-LAYOUT-TEST',
            'recipient_name' => 'Layout Test',
            'layout' => ['name_font_size' => 42],
        ]);

        $html = view('certificates.pdf-custom', [
            'certificate' => $certificate,
            'backgroundDataUri' => null,
        ])->render();

        // DomPDF's setPaper() dimensions are points. Using px here converts
        // 595px to 446.25pt, stretching the background across only 75% of the
        // page and leaving a large white strip underneath it.
        $this->assertStringContainsString('height: 595pt;', $html);
        $this->assertStringContainsString('font-size: 42pt;', $html);
        $this->assertStringNotContainsString('height: 595px;', $html);
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

        $this->confirmAdministratorPassword();
        $this->actingAs($this->administrator)
            ->putJson(route('admin.participants.name', [$this->webinar, $participant]), ['full_name' => 'Maria Santos'])
            ->assertOk()
            ->assertJsonPath('participant_id', $participant->public_id)
            ->assertJsonPath('full_name', 'Maria Santos');

        $this->assertSame('Maria Santos', $participant->fresh()->full_name);
        $this->assertDatabaseHas('audit_logs', ['action' => 'participant.name_corrected']);

        $this->approveCertificateFixtures($this->webinar);
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
        $registration = $this->webinar->forms()->create([
            'type' => 'registration', 'title' => 'Registration', 'status' => 'published',
        ]);
        $this->uploadedTemplate();
        $eligible = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Wrong Spelling', 'email' => 'wei@example.com', 'verified_at' => now(),
        ]);
        $notEligible = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Not Verified', 'email' => 'nv@example.com',
        ]);

        // The studio lists only eligible participants and stays in the section.
        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk()
            ->assertSee('Wrong Spelling')
            ->assertDontSee('Not Verified');

        $this->confirmAdministratorPassword();
        $this->actingAs($this->administrator)
            ->put(route('admin.participants.name', [$this->webinar, $eligible]), ['full_name' => 'Wei Chen'])
            ->assertRedirect();

        $this->approveCertificateFixtures($this->webinar);
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
        // Decision update (F-1): the certificate email now links to the
        // participant status page for self-service. The link is still built
        // from APP_URL at issue time and baked into delivered mail, so the
        // deployment host remains a long-term commitment; the verification
        // code stays in the email as text for support.
        $html = (string) EmailDelivery::query()->where('certificate_id', $certificate->id)->sole()->payload['html'];
        $this->assertStringContainsString($certificate->verification_code, $html);
        $this->assertStringContainsString(route('forms.public.status', $registration->public_token), $html);

        EmailDelivery::query()->where('certificate_id', $certificate->id)->delete();
        $certificate->update(['sent_at' => null]);

        $this->get(route('admin.participants.index', $this->webinar))
            ->assertOk()
            ->assertSee('Queued for email');
        $this->approveCertificateFixtures($this->webinar);
        $this->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk()
            ->assertSee('Queued for email');
        $this->get(route('admin.certificates.studio.status', $this->webinar))
            ->assertOk()
            ->assertJsonFragment([
                'participant_id' => $eligible->public_id,
                'status' => 'queued',
                'status_label' => 'Queued for email',
                'status_detail' => "Certificate ready \u{00B7} waiting for the email worker",
            ]);

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

        $this->approveCertificateFixtures($this->webinar);
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
        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk()
            ->assertSee('name="name_top" value="58"', false)
            ->assertSee('name="name_left" value="40"', false)
            ->assertSee('value="50"', false)
            ->assertSee('value="#123456"', false);
    }

    /**
     * The studio's "use for every recipient" button fills a hidden form and
     * submits it. A browser sends exactly what that form carries, so the test
     * reads the form out of the rendered page and submits it the same way. A
     * method-override field is part of that contract, not an optional extra:
     * without it the request never reaches the style-saving endpoint.
     */
    public function test_the_studio_applies_the_edited_style_to_every_recipient_without_changing_names(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();

        $edited = $this->webinar->participants()->create([
            'email' => 'edited@example.com', 'full_name' => 'Edited Person', 'verified_at' => now(),
        ]);
        $other = $this->webinar->participants()->create([
            'email' => 'other@example.com', 'full_name' => 'Other Person', 'verified_at' => now(),
        ]);

        $this->approveCertificateFixtures($this->webinar);
        $page = $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk();

        [$action, $fields] = $this->browserFormSubmission($page->getContent(), 'apply-all-form');

        // The editor fills this form with the style being edited.
        $fields = array_merge($fields, [
            'name_top' => '30', 'name_left' => '42.5', 'name_font_size' => '58',
            'name_font_family' => 'serif', 'accent' => '#7c3aed',
            'name_font_weight' => 'regular', 'name_font_style' => 'italic',
            'name_text_align' => 'right', 'studio' => '1',
        ]);

        $this->actingAs($this->administrator)
            ->post($action, $fields)
            ->assertRedirect(route('admin.certificates.studio', $this->webinar))
            ->assertSessionHas('success');

        // One style now covers the whole webinar.
        $template = $this->webinar->certificateTemplates()->where('is_active', true)->firstOrFail();
        $this->assertSame(30.0, (float) $template->layout['name_top']);
        $this->assertSame(42.5, (float) $template->layout['name_left']);
        $this->assertSame(58.0, (float) $template->layout['name_font_size']);
        $this->assertSame('serif', $template->layout['name_font_family']);
        $this->assertSame('#7c3aed', $template->layout['accent']);
        $this->assertSame('regular', $template->layout['name_font_weight']);
        $this->assertSame('italic', $template->layout['name_font_style']);
        $this->assertSame('right', $template->layout['name_text_align']);

        // Only the formatting moved. The names are exactly as they were.
        $this->assertSame('Edited Person', $edited->fresh()->full_name);
        $this->assertSame('Other Person', $other->fresh()->full_name);

        // Both recipients' certificates carry that one style.
        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.issue-selected', $this->webinar), [
                'participants' => [$edited->public_id, $other->public_id],
                'preview_confirmed' => '1',
            ])
            ->assertRedirect(route('admin.certificates.studio', $this->webinar));

        foreach ([$edited, $other] as $participant) {
            $certificate = Certificate::query()->where('participant_id', $participant->id)->sole();
            $this->assertSame(30.0, (float) $certificate->layout['name_top']);
            $this->assertSame(42.5, (float) $certificate->layout['name_left']);
            $this->assertSame('serif', $certificate->layout['name_font_family']);
            $this->assertSame('#7c3aed', $certificate->layout['accent']);
            $this->assertSame('right', $certificate->layout['name_text_align']);
            $this->assertSame($participant->full_name, $certificate->recipient_name);
        }
    }

    /**
     * The participant page's "Send certificate" button must open the studio
     * with that one person, already selected. It used to be dropped, so the
     * manual send opened the whole ready list with nothing chosen.
     */
    public function test_the_manual_send_button_opens_the_studio_with_that_person_preselected(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();
        $manual = $this->webinar->participants()->create([
            'email' => 'manual@example.com', 'full_name' => 'Manual Person', 'verified_at' => now(),
        ]);
        $other = $this->webinar->participants()->create([
            'email' => 'other@example.com', 'full_name' => 'Other Person', 'verified_at' => now(),
        ]);

        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->get(route('admin.participants.show', [$this->webinar, $manual]))
            ->assertOk()
            ->assertSee('manual='.$manual->public_id, false);

        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', ['webinar' => $this->webinar, 'manual' => $manual->public_id]))
            ->assertOk()
            ->assertSee('data-initial-participant="'.$manual->public_id.'"', false)
            ->assertSee('data-send-participant="'.$manual->public_id.'" checked', false)
            // The scope is that person alone, not the whole ready list.
            ->assertDontSee('data-send-participant="'.$other->public_id.'"', false);
    }

    /**
     * A manual send for someone who does not qualify must explain itself
     * instead of showing a generic empty studio.
     */
    public function test_a_manual_send_for_someone_who_is_not_eligible_explains_what_is_missing(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'posttest', 'is_required' => true]);
        $this->uploadedTemplate();
        $blocked = $this->webinar->participants()->create([
            'email' => 'blocked@example.com', 'full_name' => 'Blocked Person', 'verified_at' => now(),
        ]);

        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', ['webinar' => $this->webinar, 'manual' => $blocked->public_id]))
            ->assertOk()
            ->assertSee('cannot be certified yet')
            ->assertSee('Missing Posttest')
            ->assertSee('Use Add Recipient Manually for an authorized certificate exception')
            ->assertSee(route('admin.participants.show', [$this->webinar, $blocked]), false);
    }

    /**
     * A greyed-out selection box must say why, so "it does nothing" never
     * reads as a broken button.
     */
    public function test_the_participant_table_explains_why_a_row_cannot_be_selected(): void
    {
        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'pretest', 'is_required' => true]);
        $this->webinar->participants()->create([
            'email' => 'notmet@example.com', 'full_name' => 'Not Met Person', 'verified_at' => now(),
        ]);

        $this->actingAs($this->administrator)
            ->get(route('admin.participants.index', $this->webinar))
            ->assertOk()
            ->assertSee('Not eligible')
            ->assertSee('missing Pretest')
            ->assertSee('Use Add Recipient Manually in Certificate Studio');
    }

    /**
     * Read a rendered form the way a browser would: its own action, method and
     * fields, including the method-override field when the form carries one.
     *
     * @return array{0: string, 1: array<string, string>}
     */
    private function browserFormSubmission(string $html, string $formId): array
    {
        $document = new \DOMDocument;
        $this->assertTrue(
            @$document->loadHTML('<!DOCTYPE html><html><body>'.$html.'</body></html>'),
            'The rendered page could not be parsed.',
        );

        $xpath = new \DOMXPath($document);
        $forms = $xpath->query("//form[@id='".$formId."']");
        $this->assertGreaterThan(0, $forms->length, "The {$formId} form is missing from the page.");
        $form = $forms->item(0);

        $fields = [];
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            $fields[$input->getAttribute('name')] = $input->getAttribute('value');
        }

        return [$form->getAttribute('action'), $fields];
    }

    public function test_legacy_participant_add_uses_the_same_manual_studio_mechanism(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();

        // Someone who attended but never registered here.
        $this->confirmAdministratorPassword();
        $addResponse = $this->actingAs($this->administrator)
            ->post(route('admin.participants.store', $this->webinar), [
                'full_name' => 'Off List Person', 'email' => 'Off@Example.com', 'organization' => 'Community Group',
            ]);

        $participant = Participant::query()->where('email', 'off@example.com')->firstOrFail();
        $addResponse->assertRedirect(route('admin.certificates.studio', [$this->webinar, 'manual' => $participant->public_id]))
            ->assertSessionHas('success');
        $this->assertSame('Off List Person', $participant->full_name);
        $this->assertNull($participant->organization);
        $this->assertSame(0, $participant->eligibilityOverrides()->count());
        $this->assertSame('manual', $participant->certificateApproval->source);

        // They are selected in the table, then flow into the normal studio.
        $this->actingAs($this->administrator)->get(route('admin.participants.index', $this->webinar))
            ->assertOk()
            ->assertSee('Off List Person')
            ->assertSee('Add Recipient Manually');
        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)->get(route('admin.certificates.studio', [
            'webinar' => $this->webinar,
            'participants' => [$participant->public_id],
        ]))->assertOk()->assertSee('data-initial-participant="'.$participant->public_id.'"', false);

        $this->approveCertificateFixtures($this->webinar);
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

    /**
     * "Issued" says the PDF exists, not that the email left. The participant
     * page must show the delivery outcome too, or a rejected email reads as a
     * finished send.
     */
    public function test_the_participant_page_reports_a_failed_delivery(): void
    {
        Storage::fake('local');
        $template = $this->uploadedTemplate();
        $participant = $this->webinar->participants()->create([
            'email' => 'failed@example.com', 'full_name' => 'Failed Delivery', 'verified_at' => now(),
        ]);
        $certificate = Certificate::query()->create([
            'verification_code' => 'CERT-FAILED-DELIVERY',
            'webinar_id' => $this->webinar->id,
            'participant_id' => $participant->id,
            'certificate_template_id' => $template->id,
            'recipient_name' => $participant->full_name,
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        EmailDelivery::query()->create([
            'webinar_id' => $this->webinar->id,
            'participant_id' => $participant->id,
            'certificate_id' => $certificate->id,
            'type' => 'certificate',
            'recipient_email' => $participant->email,
            'subject' => 'Your certificate',
            'payload' => ['html' => 'Certificate attached'],
            'status' => 'failed',
            'failed_at' => now(),
            'last_error' => 'Brevo request failed with HTTP 401 (unauthorized).',
        ]);

        $this->actingAs($this->administrator)
            ->get(route('admin.participants.show', [$this->webinar, $participant]))
            ->assertOk()
            ->assertSee('Delivery failed')
            ->assertSee('HTTP 401');
    }

    /**
     * A manual request that resolves to nobody must never open the whole
     * ready list. Falling back would put every eligible participant one click
     * away from being sent.
     */
    public function test_a_manual_request_that_resolves_to_nobody_never_opens_the_whole_ready_list(): void
    {
        Storage::fake('local');
        $this->uploadedTemplate();
        foreach (range(1, 3) as $index) {
            $this->webinar->participants()->create([
                'email' => "ready{$index}@example.com", 'full_name' => "Ready {$index}", 'verified_at' => now(),
            ]);
        }

        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', [
                'webinar' => $this->webinar, 'manual' => 'not-a-real-identifier',
            ]))
            ->assertOk()
            ->assertSee('That recipient was not found')
            ->assertDontSee('data-send-participant="', false);
    }

    /** Public identifiers are upper-case ULIDs; a hand-typed lower-case one is the same identifier. */
    public function test_the_manual_scope_accepts_a_lower_cased_identifier(): void
    {
        Storage::fake('local');
        $this->uploadedTemplate();
        $participant = $this->webinar->participants()->create([
            'email' => 'lower@example.com', 'full_name' => 'Lower Case', 'verified_at' => now(),
        ]);

        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', [
                'webinar' => $this->webinar, 'manual' => strtolower($participant->public_id),
            ]))
            ->assertOk()
            ->assertSee('data-send-participant="'.$participant->public_id.'" checked', false)
            ->assertDontSee('That recipient was not found');
    }

    /**
     * A send that fails must say so. The studio is its own layout, and it
     * used to render neither the validation summary nor any explanation, so a
     * rejected send looked exactly like a successful one.
     */
    public function test_the_studio_explains_a_rejected_send(): void
    {
        Storage::fake('local');
        $this->uploadedTemplate();

        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->withSession(['errors' => (new ViewErrorBag)->put('default', new MessageBag([
                'participants' => ['Select at least one participant to send a certificate to.'],
            ]))])
            ->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk()
            ->assertSee('The certificates were not sent.')
            ->assertSee('Select at least one participant to send a certificate to.');
    }

    public function test_empty_studio_allows_manual_additions_and_sending_requires_preview(): void
    {
        Storage::fake('local');
        $this->uploadedTemplate();

        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk()
            ->assertSee('Add Recipient Manually')
            ->assertSee('No recipients match this list')
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

        $this->approveCertificateFixtures($this->webinar);
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

        $this->approveCertificateFixtures($this->webinar);
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

    public function test_only_one_active_certificate_delivery_can_claim_a_certificate(): void
    {
        $template = CertificateTemplate::query()->create([
            'webinar_id' => $this->webinar->id,
            'name' => 'Duplicate guard',
            'layout' => [],
        ]);
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => 'Delivery Guard',
            'email' => 'delivery-guard@example.com',
        ]);
        $certificate = Certificate::query()->create([
            'verification_code' => 'CERT-DELIVERY-GUARD',
            'webinar_id' => $this->webinar->id,
            'participant_id' => $participant->id,
            'certificate_template_id' => $template->id,
            'recipient_name' => $participant->full_name,
            'status' => 'issued',
            'issued_at' => now(),
        ]);

        $attributes = [
            'webinar_id' => $this->webinar->id,
            'participant_id' => $participant->id,
            'certificate_id' => $certificate->id,
            'type' => 'certificate',
            'recipient_email' => $participant->email,
            'subject' => 'Certificate',
            'payload' => ['html' => 'Certificate attached'],
            'status' => 'pending',
        ];
        EmailDelivery::query()->create($attributes);

        $this->expectException(QueryException::class);
        EmailDelivery::query()->create($attributes);
    }

    public function test_issuance_is_refused_until_a_certificate_is_uploaded(): void
    {
        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        // A default template exists but carries no uploaded design.
        $this->webinar->certificateTemplates()->create([
            'name' => 'Empty', 'storage_disk' => 'local',
            'layout' => ['accent' => '#1d4ed8'], 'is_active' => true,
        ]);
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Ana Cruz', 'email' => 'ana@example.com', 'verified_at' => now(),
        ]);

        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.store', [$this->webinar, $participant]))
            ->assertSessionHas('error');

        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_bulk_issuance_certifies_only_eligible_participants_and_records_auditable_outcomes(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();

        $eligible = collect(['ana@example.com', 'ben@example.com'])->map(fn ($email) => Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Verified '.ucfirst(strstr($email, '@', true)), 'email' => $email, 'verified_at' => now(),
        ]));
        $unverified = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Unverified', 'email' => 'carl@example.com',
        ]);

        // QUEUE_CONNECTION is sync in tests, so the job runs inline.
        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.batch', $this->webinar))
            ->assertRedirect()
            ->assertSessionHas('success');

        $batch = CertificateBatch::query()->firstOrFail();
        $this->assertSame('completed', $batch->status);
        $this->assertSame(2, $batch->total_count);
        $this->assertSame(2, $batch->completed_count);
        $this->assertSame(0, $batch->failed_count);
        $this->assertSame(2, $batch->items()->where('status', 'issued')->count());

        foreach ($eligible as $participant) {
            $certificate = Certificate::query()->where('participant_id', $participant->id)->firstOrFail();
            $this->assertSame($batch->id, $certificate->certificate_batch_id);
            Storage::disk('local')->assertExists($certificate->file_path);
        }

        $this->assertSame(0, Certificate::query()->where('participant_id', $unverified->id)->count());

        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', $this->webinar))
            ->assertOk()
            ->assertSee('Latest bulk issuance')
            ->assertSee('Verified Ana')
            ->assertSee('Issued');

        // A second run finds nothing left to do.
        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)->post(route('admin.certificates.batch', $this->webinar))->assertRedirect();
        $this->assertSame(2, Certificate::query()->count());
    }

    public function test_bulk_issuance_is_refused_without_an_active_template(): void
    {
        $this->approveCertificateFixtures($this->webinar);
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

        $this->approveCertificateFixtures($this->webinar);
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
            'background_path' => 'certificate-backgrounds/test.png',
            'layout' => ['accent' => '#1d4ed8', 'name_top' => 62, 'name_font_size' => 42],
            'is_active' => true,
        ]);
    }

    public function test_delivered_certificates_are_separated_from_the_studio_working_list(): void
    {
        Storage::fake('local');
        $template = $this->uploadedTemplate();

        $waiting = $this->webinar->participants()->create([
            'email' => 'waiting@example.com', 'full_name' => 'Waiting Person', 'verified_at' => now(),
        ]);
        $done = $this->webinar->participants()->create([
            'email' => 'done@example.com', 'full_name' => 'Done Person', 'verified_at' => now(),
        ]);

        // Both carry an administrator eligibility decision, so both qualify;
        // only one has actually been delivered a certificate.
        foreach ([$waiting, $done] as $participant) {
            EligibilityOverride::query()->create([
                'participant_id' => $participant->id,
                'decision' => 'eligible',
                'reason' => 'Studio separation fixture',
                'overridden_by' => $this->administrator->id,
            ]);
        }

        Certificate::query()->create([
            'verification_code' => 'CERT-SEPARATED-0001',
            'webinar_id' => $this->webinar->id,
            'participant_id' => $done->id,
            'certificate_template_id' => $template->id,
            'recipient_name' => 'Done Person',
            'storage_disk' => 'local',
            'status' => 'issued',
            'issued_at' => now(),
            'sent_at' => now(),
        ]);

        $this->approveCertificateFixtures($this->webinar);
        $response = $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', $this->webinar));

        $response->assertOk();
        // The delivered recipient is gone from the selectable working list…
        $response->assertDontSee('data-queue-check="'.$done->public_id.'"', false);
        // …and lives behind the separated Sent tab instead.
        $response->assertSee('data-recipient-tab="sent"', false);
        $response->assertSee('done@example.com');
        // The still-waiting participant stays exactly where the organizer works.
        $response->assertSee('data-queue-check="'.$waiting->public_id.'"', false);
    }

    private function confirmAdministratorPassword(): void
    {
        $this->actingAs($this->administrator)
            ->post(route('admin.password.confirm.store'), ['password' => 'password'])
            ->assertRedirect(route('admin.dashboard'));
    }
}
