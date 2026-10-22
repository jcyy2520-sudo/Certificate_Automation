<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\SendTransactionalEmail;
use App\Models\CertificateTemplate;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CertificateService;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\ApprovesCertificateFixtures;
use Tests\TestCase;

class CertificateAccuracyTest extends TestCase
{
    use ApprovesCertificateFixtures;
    use RefreshDatabase;

    private Webinar $webinar;

    private CertificateTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $admin = User::factory()->create(['is_active' => true]);
        $this->actingAs($admin);
        $this->webinar = Webinar::query()->create(['title' => 'Certificate Accuracy', 'slug' => 'accuracy', 'status' => 'published', 'timezone' => 'Asia/Manila', 'ends_at' => now()->addDay(), 'data_retention_days' => 30, 'created_by' => $admin->id]);
        $this->webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
        $this->webinar->forms()->create(['type' => 'registration', 'title' => 'Registration', 'status' => 'published']);
        $image = imagecreatetruecolor(1200, 850);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        $ink = imagecolorallocate($image, 45, 55, 65);
        imagerectangle($image, 25, 25, 1175, 825, $ink);
        imagestring($image, 5, 455, 100, 'CERTIFICATE OF COMPLETION', $ink);
        imagestring($image, 4, 472, 230, 'Presented to the participant', $ink);
        imagestring($image, 4, 470, 720, 'Synthetic validation fixture', $ink);
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('certificate-backgrounds/accuracy.png', $png);
        $this->template = $this->webinar->certificateTemplates()->create(['name' => 'Accuracy', 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/accuracy.png', 'is_active' => true, 'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_font_size' => 42, 'name_top' => 55, 'name_left' => 50, 'name_font_family' => 'serif', 'accent' => '#172554']]);
    }

    public function test_imported_name_reaches_the_issued_pdf_without_losing_name_parts(): void
    {
        $service = app(CsvImportService::class);
        $import = $service->storePending($this->webinar, $this->webinar->forms()->sole(), UploadedFile::fake()->createWithContent('responses.csv', "Email Address,First Name,Middle Name,Last Name,Suffix\nsynthetic@example.com,José,Niño,de la Cruz,Jr.\n"));
        $service->process($import, $import->column_map['map'], options: ['allow_import_time' => true, 'source_description' => 'Registration Form v1, exported 2026-08-20', 'source_confirmed' => true]);
        $participant = Participant::query()->sole();
        $this->approveCertificateFixtures($this->webinar);
        $certificate = app(CertificateService::class)->issue($participant);
        $this->assertSame('José Niño de la Cruz Jr.', $certificate->recipient_name);
        $this->assertSame('issued', $certificate->status);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($certificate->file_path));
        $this->saveQa('accented-name.pdf', Storage::disk('local')->get($certificate->file_path));
    }

    public function test_long_name_is_rendered_complete_and_never_shortened(): void
    {
        $name = 'Maria Alexandra Cristina Isabel Santos de los Reyes Fernández Villanueva Jr.';
        $participant = $this->webinar->participants()->create(['email' => 'long@example.com', 'full_name' => $name, 'verified_at' => now()]);
        $this->approveCertificateFixtures($this->webinar);
        $certificate = app(CertificateService::class)->issue($participant);
        $this->assertSame($name, $certificate->recipient_name);
        $this->assertSame('issued', $certificate->status);
        $this->saveQa('long-name.pdf', Storage::disk('local')->get($certificate->file_path));
        $participant->update(['full_name' => 'Corrected Name']);
        $this->expectExceptionMessage('Revoke it');
        app(CertificateService::class)->issue($participant->fresh());
    }

    public function test_verification_code_collision_retries_without_losing_issuance(): void
    {
        $first = $this->webinar->participants()->create(['email' => 'first@example.com', 'full_name' => 'First Person', 'verified_at' => now()]);
        $second = $this->webinar->participants()->create(['email' => 'second@example.com', 'full_name' => 'Second Person', 'verified_at' => now()]);
        $this->approveCertificateFixtures($this->webinar);
        $service = app(CertificateService::class);
        $existing = $service->issue($first);
        $collision = substr($existing->verification_code, 5);
        Str::createRandomStringsUsingSequence([$collision, str_repeat('z', 20)]);
        try {
            $certificate = $service->issue($second);
            $this->assertSame('issued', $certificate->status);
            $this->assertNotSame($existing->verification_code, $certificate->verification_code);
            $this->assertSame(2, $this->webinar->certificates()->where('status', 'issued')->count());
        } finally {
            Str::createRandomStringsNormally();
        }
    }

    public function test_unprintable_or_missing_names_cannot_be_issued(): void
    {
        foreach ([null, 'Name 😀', 'person@example.com'] as $index => $name) {
            $participant = $this->webinar->participants()->create(['email' => 'invalid'.$index.'@example.com', 'full_name' => $name, 'verified_at' => now()]);
            try {
                $this->approveCertificateFixtures($this->webinar);
                app(CertificateService::class)->issue($participant);
                $this->fail('An invalid certificate name was issued.');
            } catch (RuntimeException $exception) {
                $this->assertNotSame('', $exception->getMessage());
            }
            $this->assertSame(0, $participant->certificates()->where('status', 'issued')->count());
        }
    }

    public function test_off_page_name_and_missing_artwork_are_refused(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'layout@example.com', 'full_name' => 'Layout Person', 'verified_at' => now()]);
        $this->approveCertificateFixtures($this->webinar);
        $service = app(CertificateService::class);
        try {
            $service->issue($participant, layout: ['name_top' => 100, 'name_left' => 50]);
            $this->fail('Off-page name was issued.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('inside the page', $exception->getMessage());
        }
        Storage::disk('local')->delete($this->template->background_path);
        $this->expectExceptionMessage('artwork is missing');
        $service->issue($participant);
    }

    public function test_name_correction_warns_about_an_issued_certificate(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'correct@example.com', 'full_name' => 'Maria Reyes', 'verified_at' => now()]);
        $this->approveCertificateFixtures($this->webinar);
        $certificate = app(CertificateService::class)->issue($participant);
        $this->saveQa('standard-name.pdf', Storage::disk('local')->get($certificate->file_path));
        $this->putJson(route('admin.participants.name', [$this->webinar, $participant]), ['full_name' => 'Maria Santos Reyes'])
            ->assertOk()->assertJsonPath('full_name', 'Maria Santos Reyes')
            ->assertJsonPath('certificate_warning', fn ($message) => str_contains($message, 'Revoke'));
        $this->assertSame('Maria Reyes', $certificate->fresh()->recipient_name);
    }

    public function test_correcting_a_response_requires_revoking_the_active_certificate(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'scored@example.com', 'full_name' => 'Scored Person', 'verified_at' => now()]);
        $this->approveCertificateFixtures($this->webinar);
        app(CertificateService::class)->issue($participant);
        $form = $this->webinar->forms()->create(['type' => 'posttest', 'title' => 'Posttest', 'status' => 'published']);
        $service = app(CsvImportService::class);
        foreach ([8, 4] as $score) {
            $import = $service->storePending($this->webinar, $form, UploadedFile::fake()->createWithContent('scores.csv', "Email Address,Score\nscored@example.com,$score\n"));
            $service->process($import, $import->column_map['map'], options: [
                'allow_import_time' => true,
                'source_description' => 'Posttest Form v1, exported 2026-08-20',
                'source_confirmed' => true,
            ]);
        }
        $issue = $import->issues()->sole();
        $this->post(route('admin.webinars.import-issues.replace', [$this->webinar, $issue]), ['reason' => 'Correct against original source', 'replacement_confirmed' => '1'])
            ->assertSessionHas('error', fn ($message) => str_contains($message, 'Revoke'));
        $this->assertSame('8.00', $form->submissions()->sole()->score);
    }

    public function test_queued_certificate_email_is_cancelled_after_a_name_correction(): void
    {
        Queue::fake();
        $participant = $this->webinar->participants()->create(['email' => 'queued@example.com', 'full_name' => 'Earlier Name', 'verified_at' => now()]);
        $this->approveCertificateFixtures($this->webinar);
        $certificate = app(CertificateService::class)->issue($participant);
        $delivery = $certificate->deliveries()->sole();
        $participant->update(['full_name' => 'Corrected Name']);
        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldNotReceive('send');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->post(route('admin.certificates.resend', $certificate))->assertSessionHas('error');
    }

    private function saveQa(string $filename, string $pdf): void
    {
        if ($directory = getenv('CERTIFICATE_QA_DIR')) {
            if (! is_dir($directory)) {
                mkdir($directory, 0700, true);
            }
            file_put_contents($directory.DIRECTORY_SEPARATOR.$filename, $pdf);
        }
    }
}
