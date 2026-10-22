<?php

namespace Tests\Feature;

use App\Models\CertificateBatch;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\CertificateService;
use App\Services\EligibilityService;
use App\Services\NotificationService;
use Error;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CertificateBatchRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public static function revocations(): array
    {
        return ['ordinary interruption' => [false], 'revoked during interruption' => [true]];
    }

    #[DataProvider('revocations')]
    public function test_interrupted_batch_reconciles_committed_certificates_and_preserves_total_and_progress(bool $revokeBeforeRetry): void
    {
        Queue::fake();
        Storage::fake('local');
        $webinar = Webinar::factory()->create();
        $webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
        foreach (range(1, 2) as $number) {
            $webinar->participants()->create(['email' => "batch-{$number}@example.test", 'full_name' => 'Batch Recipient '.$number, 'verified_at' => now()]);
        }
        $image = UploadedFile::fake()->image('batch.png', 1200, 850);
        Storage::disk('local')->put('certificate-backgrounds/batch-recovery.png', file_get_contents($image->getRealPath()));
        $template = $webinar->certificateTemplates()->create([
            'name' => 'Recovery', 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/batch-recovery.png', 'is_active' => true,
            'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 36, 'name_font_family' => 'sans', 'accent' => '#172554'],
        ]);
        $batch = $webinar->certificateBatches()->create(['certificate_template_id' => $template->id, 'created_by' => $webinar->created_by, 'status' => 'pending']);
        $real = app(CertificateService::class);
        $interrupted = Mockery::mock(CertificateService::class, [app(EligibilityService::class), app(NotificationService::class)])->makePartial();
        $calls = 0;
        $interrupted->shouldReceive('issue')->andReturnUsing(function (Participant $participant, CertificateBatch $batch) use ($real, &$calls) {
            if (++$calls === 2) {
                throw new Error('Synthetic worker interruption');
            }

            return $real->issue($participant, $batch);
        });
        try {
            $interrupted->issueBatch($batch);
            $this->fail('The simulated interruption was not reached.');
        } catch (Error $exception) {
            $this->assertSame('Synthetic worker interruption', $exception->getMessage());
        }
        $this->assertSame(1, $batch->certificates()->where('status', 'issued')->count());
        $this->assertSame(1, $batch->fresh()->completed_count, 'Progress must be durable before the batch finishes.');
        if ($revokeBeforeRetry) {
            $batch->certificates()->firstOrFail()->update(['status' => 'revoked', 'revoked_at' => now()]);
        }
        // Simulate death after certificate commit and before its sidecar outcome write.
        $batch->items()->delete();
        $recovered = $real->issueBatch($batch->fresh());
        $this->assertSame('completed', $recovered->status);
        $this->assertSame(2, $recovered->total_count);
        $this->assertSame(2, $recovered->completed_count);
        $this->assertSame(0, $recovered->failed_count);
        $this->assertSame(2, $batch->items()->where('status', 'issued')->count());
        $this->assertSame(2, $batch->certificates()->where('status', 'issued')->count());
        $this->assertDatabaseCount('email_deliveries', $revokeBeforeRetry ? 3 : 2);
    }
}
