<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Webinar;
use App\Services\CertificateFileService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class CertificateZipMemoryTest extends TestCase
{
    use RefreshDatabase;

    private function certificate(Webinar $webinar, int $number, string $disk = 'local'): Certificate
    {
        $template = $webinar->certificateTemplates()->firstOrCreate(['name' => 'Synthetic ZIP template'],
            ['storage_disk' => $disk, 'layout' => [], 'is_active' => true]);
        $participant = $webinar->participants()->create(['full_name' => 'Synthetic '.$number, 'email' => 'synthetic'.$number.'@example.test']);
        $certificate = $webinar->certificates()->create(['participant_id' => $participant->id,
            'certificate_template_id' => $template->id,
            'recipient_name' => $participant->full_name, 'verification_code' => 'ZIP-'.$number,
            'status' => 'issued', 'issued_at' => now(), 'storage_disk' => $disk]);
        $certificate->update(['file_path' => 'certificates/'.$certificate->public_id.'.pdf']);

        return $certificate;
    }

    public function test_large_local_export_does_not_retain_all_pdf_bytes_and_preserves_exact_contents(): void
    {
        Storage::fake('local');
        $webinar = Webinar::factory()->create();
        $content = '%PDF-1.4 '.random_bytes(1024 * 1024);
        foreach (range(1, 40) as $number) {
            $certificate = $this->certificate($webinar, $number);
            Storage::disk('local')->put($certificate->file_path, $content);
        }
        $before = memory_get_usage();
        memory_reset_peak_usage();
        $result = app(CertificateFileService::class)->createZipArchive($webinar);
        $peak = memory_get_peak_usage() - $before;
        $metricsPath = storage_path('framework/testing/cleanup-measurements.json');
        $metrics = is_file($metricsPath) ? json_decode(file_get_contents($metricsPath), true) : [];
        $metrics['zip_40_megabyte_files_peak_extra_bytes'] = $peak;
        file_put_contents($metricsPath, json_encode($metrics, JSON_PRETTY_PRINT));
        try {
            $this->assertSame(40, $result['count']);
            $this->assertLessThan(12 * 1024 * 1024, $peak, 'ZIP assembly must not retain the 40 MB of PDF contents.');
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($result['path']));
            try {
                $this->assertSame(40, $zip->numFiles);
                $this->assertSame($content, $zip->getFromName('Synthetic_1_ZIP-1.pdf'));
                $this->assertSame($content, $zip->getFromName('Synthetic_40_ZIP-40.pdf'));
            } finally {
                $zip->close();
            }
            $this->assertTrue(Storage::disk('local')->exists($certificate->file_path));
        } finally {
            @unlink($result['path']);
        }
    }

    public function test_remote_export_streams_sources_and_cleans_its_private_temporary_files(): void
    {
        $webinar = Webinar::factory()->create();
        $this->certificate($webinar, 1, 'remote-test');
        $this->certificate($webinar, 2, 'remote-test');
        $before = $this->temporaryFiles();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->twice()->andReturnTrue();
        $disk->shouldReceive('readStream')->twice()->andReturnUsing(fn () => $this->stream('%PDF exact synthetic content'));
        $disk->shouldNotReceive('get');
        Storage::shouldReceive('disk')->with('remote-test')->twice()->andReturn($disk);
        $result = app(CertificateFileService::class)->createZipArchive($webinar);
        try {
            $this->assertSame(2, $result['count']);
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($result['path']));
            try {
                $this->assertSame('%PDF exact synthetic content', $zip->getFromName('Synthetic_2_ZIP-2.pdf'));
            } finally {
                $zip->close();
            }
            $this->assertSame([str_replace('\\', '/', $result['path'])], array_values(array_diff($this->temporaryFiles(), $before)));
        } finally {
            @unlink($result['path']);
        }
    }

    public function test_remote_failure_cleans_the_archive_and_previously_spooled_sources(): void
    {
        $webinar = Webinar::factory()->create();
        $first = $this->certificate($webinar, 1, 'remote-test');
        $second = $this->certificate($webinar, 2, 'remote-test');
        $before = $this->temporaryFiles();
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->twice()->andReturnTrue();
        $disk->shouldReceive('readStream')->with($first->file_path)->once()->andReturn($this->stream('%PDF first source'));
        $disk->shouldReceive('readStream')->with($second->file_path)->once()->andThrow(new RuntimeException('Synthetic remote read failure'));
        Storage::shouldReceive('disk')->with('remote-test')->twice()->andReturn($disk);
        try {
            app(CertificateFileService::class)->createZipArchive($webinar);
            $this->fail('Expected a remote read failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic remote read failure', $exception->getMessage());
        }
        $this->assertSame($before, $this->temporaryFiles());
    }

    public function test_export_keeps_path_validation_and_skips_revoked_missing_and_unissued_files(): void
    {
        Storage::fake('local');
        $webinar = Webinar::factory()->create();
        foreach (range(1, 5) as $number) {
            $certificate = $this->certificate($webinar, $number);
            if ($number !== 2) {
                Storage::disk('local')->put($certificate->file_path, '%PDF '.$number);
            }
            match ($number) {
                1 => $certificate->update(['file_path' => '../outside.pdf']),
                3 => $certificate->update(['revoked_at' => now()]),
                4 => $certificate->update(['status' => 'processing']),
                default => null,
            };
        }
        $result = app(CertificateFileService::class)->createZipArchive($webinar);
        try {
            $this->assertSame(1, $result['count']);
            $this->assertTrue(Storage::disk('local')->exists($certificate->file_path));
        } finally {
            @unlink($result['path']);
        }
    }

    private function stream(string $content)
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, $content);
        rewind($stream);

        return $stream;
    }

    private function temporaryFiles(): array
    {
        // Windows tempnam uses only the first three prefix characters.
        $pattern = PHP_OS_FAMILY === 'Windows' ? '/web*.tmp' : '/webinar_*';
        $files = array_map(fn ($path) => str_replace('\\', '/', $path), glob(sys_get_temp_dir().$pattern));
        sort($files);

        return $files;
    }
}
