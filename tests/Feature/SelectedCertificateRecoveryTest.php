<?php

namespace Tests\Feature;

use App\Jobs\IssueSelectedCertificate;
use App\Models\Certificate;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\CertificateService;
use App\Services\EligibilityService;
use App\Services\NotificationService;
use Error;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class SelectedCertificateRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private Participant $participant;

    private string $disk;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->disk = 'selected-recovery-'.Str::uuid();
        Storage::fake($this->disk);
        config(['webinar.certificate_disk' => $this->disk]);
        $webinar = Webinar::factory()->create();
        $webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
        $webinar->certificateTemplates()->create([
            'name' => 'Synthetic recovery', 'storage_disk' => $this->disk,
            'background_path' => 'certificate-backgrounds/synthetic.png', 'is_active' => true,
            'layout' => ['bg_w' => 1200, 'bg_h' => 850],
        ]);
        $this->participant = $webinar->participants()->create([
            'email' => 'selected-recovery@example.test', 'full_name' => 'Synthetic Recipient', 'verified_at' => now(),
        ]);
    }

    public function test_terminal_job_failure_releases_the_claim_and_allows_a_successful_selected_retry(): void
    {
        $service = Mockery::mock(CertificateService::class, [app(EligibilityService::class), app(NotificationService::class)])->makePartial();
        $service->shouldReceive('render')->once()->andReturn("%PDF-1.4\nSynthetic retry fixture");
        $original = $service->queue($this->participant);
        $this->assertNotNull($original->issuance_key);

        (new IssueSelectedCertificate($this->participant->id))->failed(new Error('Synthetic worker interruption'));
        $replacement = $service->queue($this->participant->fresh());

        $this->assertSame('failed', $original->fresh()->status);
        $this->assertNull($original->fresh()->issuance_key);
        $this->assertNotSame($original->id, $replacement->id);
        (new IssueSelectedCertificate($this->participant->id))->handle($service);

        $this->assertSame('issued', $replacement->fresh()->status);
        $this->assertSame(1, Certificate::query()->whereNotNull('issuance_key')->count());
        Storage::disk($this->disk)->assertExists($replacement->file_path);
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_failure_callback_preserves_a_committed_issued_certificate_and_file_inventory(): void
    {
        $certificate = app(CertificateService::class)->queue($this->participant);
        $certificate->update(['status' => 'issued', 'issued_at' => now()]);
        $key = $certificate->issuance_key;
        $path = $certificate->file_path;

        (new IssueSelectedCertificate($this->participant->id))->failed(new Error('Synthetic late callback'));

        $this->assertSame('issued', $certificate->fresh()->status);
        $this->assertSame($key, $certificate->fresh()->issuance_key);
        $this->assertSame($path, $certificate->fresh()->file_path);
    }

    public function test_failed_broker_handoff_does_not_strand_processing_work_or_its_unique_claim(): void
    {
        $webinar = $this->participant->webinar;
        $this->actingAs($webinar->creator);
        Bus::shouldReceive('dispatch')->once()->with(Mockery::type(IssueSelectedCertificate::class))
            ->andThrow(new RuntimeException('Synthetic broker unavailable'));

        $this->post(route('admin.certificates.issue-selected', $webinar), [
            'participants' => [$this->participant->public_id], 'preview_confirmed' => '1',
        ])->assertRedirect()->assertSessionHas('error');

        $certificate = Certificate::query()->sole();
        $this->assertSame('failed', $certificate->status);
        $this->assertNull($certificate->issuance_key);
        $this->get(route('admin.certificates.studio', $webinar))->assertOk()->assertSee('Retry generation');
        $replacement = app(CertificateService::class)->queue($this->participant);
        $this->assertSame('processing', $replacement->status);
        $this->assertNotSame($certificate->id, $replacement->id);
    }
}
