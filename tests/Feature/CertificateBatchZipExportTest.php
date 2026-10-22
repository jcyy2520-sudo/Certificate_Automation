<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use ZipArchive;

class CertificateBatchZipExportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Webinar $webinar;

    private CertificateTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->admin = User::factory()->create([
            'password' => bcrypt('AdminPassword123!'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->webinar = Webinar::query()->create([
            'title' => 'Mastering AI Webinar',
            'slug' => 'mastering-ai-webinar',
            'status' => 'published',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'data_retention_days' => 30,
            'timezone' => 'UTC',
            'created_by' => $this->admin->id,
        ]);

        $this->template = CertificateTemplate::query()->create([
            'webinar_id' => $this->webinar->id,
            'name' => 'Default Certificate',
            'storage_disk' => 'local',
            'layout' => [],
            'is_active' => true,
        ]);
    }

    #[Test]
    public function it_exports_all_issued_certificates_as_a_zip_archive(): void
    {
        $participant1 = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'email' => 'maria@example.com',
            'full_name' => 'Maria Santos',
        ]);
        $cert1 = Certificate::query()->create([
            'webinar_id' => $this->webinar->id,
            'certificate_template_id' => $this->template->id,
            'participant_id' => $participant1->id,
            'recipient_name' => 'Maria Santos',
            'verification_code' => 'CERT-ZIP-0001',
            'verification_code_hash' => Certificate::verificationCodeHash('CERT-ZIP-0001'),
            'status' => 'issued',
            'issued_at' => now(),
            'storage_disk' => 'local',
            'file_path' => 'certificates/placeholder1.pdf',
        ]);
        $cert1->forceFill(['file_path' => 'certificates/'.$cert1->public_id.'.pdf'])->save();
        Storage::disk('local')->put($cert1->file_path, '%PDF-1.4 dummy certificate 1');

        $participant2 = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'email' => 'juan@example.com',
            'full_name' => 'Juan Dela Cruz',
        ]);
        $cert2 = Certificate::query()->create([
            'webinar_id' => $this->webinar->id,
            'certificate_template_id' => $this->template->id,
            'participant_id' => $participant2->id,
            'recipient_name' => 'Juan Dela Cruz',
            'verification_code' => 'CERT-ZIP-0002',
            'verification_code_hash' => Certificate::verificationCodeHash('CERT-ZIP-0002'),
            'status' => 'issued',
            'issued_at' => now(),
            'storage_disk' => 'local',
            'file_path' => 'certificates/placeholder2.pdf',
        ]);
        $cert2->forceFill(['file_path' => 'certificates/'.$cert2->public_id.'.pdf'])->save();
        Storage::disk('local')->put($cert2->file_path, '%PDF-1.4 dummy certificate 2');

        $response = $this->actingAs($this->admin)->get(
            route('admin.webinars.certificates.export-zip', $this->webinar),
        );

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('mastering-ai-webinar-certificates.zip', (string) $response->headers->get('Content-Disposition'));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'webinar.certificates_exported_zip',
            'auditable_id' => $this->webinar->id,
            'auditable_type' => Webinar::class,
        ]);

        // Inspect zip contents
        $tempZip = tempnam(sys_get_temp_dir(), 'test_zip_');
        file_put_contents($tempZip, $response->streamedContent());

        $zip = new ZipArchive;
        $this->assertTrue($zip->open($tempZip));
        $this->assertSame(2, $zip->numFiles);

        $entry1 = $zip->getNameIndex(0);
        $entry2 = $zip->getNameIndex(1);
        $entries = [$entry1, $entry2];

        $this->assertContains('Maria_Santos_CERT-ZIP-0001.pdf', $entries);
        $this->assertContains('Juan_Dela_Cruz_CERT-ZIP-0002.pdf', $entries);

        $zip->close();
        @unlink($tempZip);
    }

    #[Test]
    public function it_redirects_back_when_no_issued_certificates_exist(): void
    {
        $response = $this->actingAs($this->admin)->get(
            route('admin.webinars.certificates.export-zip', $this->webinar),
        );

        $response->assertRedirect();
        $response->assertSessionHas('error', 'There are no issued certificate files available to export for this webinar.');
        $this->assertDatabaseMissing('audit_logs', ['action' => 'webinar.certificates_exported_zip']);
    }

    #[Test]
    public function it_aborts_with_conflict_when_webinar_deletion_is_started(): void
    {
        $this->webinar->forceFill(['deletion_started_at' => now()])->save();

        $response = $this->actingAs($this->admin)->get(
            route('admin.webinars.certificates.export-zip', $this->webinar),
        );

        $response->assertStatus(409);
    }
}
