<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\CertificateTemplate;
use App\Models\EligibilityRule;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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
            'status' => 'published', 'timezone' => 'UTC', 'created_by' => $this->administrator->id,
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

        $this->actingAs($this->administrator)->put(route('admin.certification.template', $this->webinar), [
            'name' => 'Formal award',
            'accent' => '#7c3aed',
            'name_top' => 58,
            'name_font_size' => 48,
            'background' => UploadedFile::fake()->image('certificate.png', 1200, 850),
        ])->assertRedirect();

        $template = $this->webinar->certificateTemplates()->where('is_active', true)->firstOrFail();
        $this->assertSame('Formal award', $template->name);
        $this->assertSame('#7c3aed', $template->layout['accent']);
        $this->assertSame(58, (int) $template->layout['name_top']);
        $this->assertNotNull($template->background_path);
        Storage::disk('local')->assertExists($template->background_path);
        // The removed "generated design" fields must not linger in the layout.
        $this->assertArrayNotHasKey('heading', $template->layout);
        $this->assertArrayNotHasKey('signatory_name', $template->layout);

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
            'name' => 'No image yet', 'accent' => '#123456',
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
        Storage::fake('local');

        $this->actingAs($this->administrator)->put(route('admin.certification.template', $this->webinar), [
            'name' => 'Broken', 'accent' => 'red',
            'background' => UploadedFile::fake()->image('certificate.png', 800, 600),
        ])->assertSessionHasErrors('accent');
    }

    public function test_a_wrong_name_can_be_corrected_when_issuing(): void
    {
        Storage::fake('local');

        EligibilityRule::query()->create(['webinar_id' => $this->webinar->id, 'requirement' => 'registration', 'is_required' => true]);
        $this->uploadedTemplate();
        $participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id, 'full_name' => 'Maria Snatos', 'email' => 'maria@example.com', 'verified_at' => now(),
        ]);

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.store', [$this->webinar, $participant]), ['recipient_name' => 'Maria Santos'])
            ->assertRedirect();

        $certificate = Certificate::query()->where('participant_id', $participant->id)->firstOrFail();
        $this->assertSame('Maria Santos', $certificate->recipient_name);
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
