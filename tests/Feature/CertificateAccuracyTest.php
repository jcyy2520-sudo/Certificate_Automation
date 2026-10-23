<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\IssueSelectedCertificate;
use App\Jobs\SendTransactionalEmail;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CertificateFonts;
use App\Services\CertificateService;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function test_selected_arial_equivalent_reaches_the_pdf_and_its_email_attachment(): void
    {
        Queue::fake();
        $participant = $this->webinar->participants()->create(['email' => 'font-fidelity@example.test',
            'full_name' => 'Synthetic Font Fidelity Recipient', 'verified_at' => now()]);
        $design = array_replace($this->template->layout, ['name_font_family' => 'arial']);
        $this->post(route('admin.certificates.issue-selected', $this->webinar), [
            'participants' => [$participant->public_id], 'preview_confirmed' => '1',
            'designs_json' => json_encode([$participant->public_id => $design]),
        ])->assertSessionHasNoErrors()->assertRedirect();
        $certificate = Certificate::where('participant_id', $participant->id)->sole();
        $this->assertSame('arial', $certificate->layout['name_font_family']);
        (new IssueSelectedCertificate($participant->id))->handle(app(CertificateService::class));
        $certificate->refresh();
        $pdf = Storage::disk('local')->get($certificate->file_path);
        $this->saveQa('arial-selected.pdf', $pdf);
        $this->assertSame($pdf, base64_decode($certificate->deliveries()->sole()->payload['attachments'][0]['content']));
        $this->assertStringContainsString('DejaVuSans', $pdf);
        $this->assertStringNotContainsString('/BaseFont /Times-', $pdf);
    }

    public static function fontCases(): array
    {
        $cases = [];
        foreach (['sans' => 'DejaVuSans', 'arial' => 'DejaVuSans', 'serif' => 'DejaVuSerif', 'times' => 'DejaVuSerif', 'mono' => 'DejaVuSansMono', 'montserrat' => 'Montserrat', 'lato' => 'Lato', 'cormorant' => 'CormorantGaramond'] as $key => $face) {
            foreach (['regular', 'bold'] as $weight) {
                foreach (['regular', 'italic'] as $style) {
                    $suffix = $weight === 'bold' ? 'Bold' : '';
                    if ($style === 'italic') {
                        $suffix .= in_array($face, ['DejaVuSans', 'DejaVuSansMono'], true) ? 'Oblique' : 'Italic';
                    }
                    if ($suffix === '' && ! str_starts_with($face, 'DejaVu')) {
                        $suffix = 'Regular';
                    }
                    $cases[$key.'-'.$weight.'-'.$style] = [$key, $weight, $style, $face.($suffix ? '-'.$suffix : '')];
                }
            }
        }

        return $cases;
    }

    #[DataProvider('fontCases')]
    public function test_every_font_variant_is_embedded_in_the_generated_pdf_and_attachment(string $key, string $weight, string $style, string $expectedFace): void
    {
        Queue::fake();
        $this->template->update(['layout' => array_replace($this->template->layout, [
            'name_font_family' => $key, 'name_font_weight' => $weight, 'name_font_style' => $style,
        ])]);
        $participant = $this->webinar->participants()->create(['email' => 'font-matrix@example.test',
            'full_name' => 'Jose Niño de la Cruz Jr.', 'verified_at' => now()]);
        $certificate = app(CertificateService::class)->issue($participant);
        $pdf = Storage::disk('local')->get($certificate->file_path);
        $this->saveQa($key.'-'.$weight.'-'.$style.'.pdf', $pdf);
        preg_match_all('/\/BaseFont\s*\/([^\s<>()\[\]]+)/', $pdf, $fonts);
        $this->assertNotEmpty($fonts[1]);
        foreach ($fonts[1] as $font) {
            $this->assertMatchesRegularExpression('/^[A-Z]{6}\+'.preg_quote($expectedFace, '/').'$/', $font);
        }
        $this->assertSame($key, $certificate->layout['name_font_family']);
        $this->assertSame($pdf, base64_decode($certificate->deliveries()->sole()->payload['attachments'][0]['content']));
        $definition = app(CertificateFonts::class)->definition($certificate->layout);
        [$baseline, $size] = $this->pdfNamePosition($pdf);
        $expectedBaseline = 595 * $certificate->layout['name_top'] / 100 + (1 + $definition['ascent'] - $definition['descent']) / 2 * $size;
        $this->assertEqualsWithDelta($expectedBaseline, $baseline, 0.002, 'The PDF name must use the shared browser baseline.');
        $html = view('certificates.pdf-custom', ['certificate' => $certificate, 'backgroundDataUri' => null])->render();
        $this->assertStringNotContainsString('&#039;', $html);
        $this->assertStringNotContainsString('&quot;', $html);
        Queue::assertPushed(SendTransactionalEmail::class, 1);
    }

    public function test_the_template_name_area_constrains_recipient_adjustments_without_shortening_the_name(): void
    {
        Queue::fake();
        $this->template->update(['layout' => array_replace($this->template->layout, ['name_max_width' => 65, 'name_bottom' => 49.5])]);
        $layout = ['name_top' => 42.4, 'name_left' => 42.94, 'name_font_size' => 80, 'name_font_family' => 'arial'];
        $participant = $this->webinar->participants()->create(['email' => 'name-area@example.test', 'full_name' => 'Maria Alexandra Cristina Isabel Santos de los Reyes Jr.', 'verified_at' => now()]);
        $certificate = app(CertificateService::class)->issue($participant, layout: $layout);
        [$baseline, $size] = $this->pdfNamePosition(Storage::disk('local')->get($certificate->file_path));
        $definition = app(CertificateFonts::class)->definition($layout);
        $this->assertLessThan(80, $size);
        $this->assertGreaterThanOrEqual(12, $size);
        $this->assertLessThanOrEqual(595 * 0.495 - 4 + 0.002, $baseline + $definition['descent'] * $size);
        $this->assertSame($participant->full_name, $certificate->recipient_name);
        $this->assertSame($layout, $certificate->layout);
    }

    private function pdfNamePosition(string $pdf): array
    {
        preg_match_all('/<<[^>]*\/Length\s+(\d+)[^>]*>>\s*stream\r?\n/s', $pdf, $streams, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($streams as $match) {
            $stream = substr($pdf, $match[0][1] + strlen($match[0][0]), (int) $match[1][0]);
            $content = @gzuncompress($stream) ?: $stream;
            if (preg_match('/BT [\d.-]+ ([\d.-]+) Td \/F\d+ ([\d.]+) Tf/', $content, $position)) {
                return [595 - (float) $position[1], (float) $position[2]];
            }
        }
        $this->fail('No drawn PDF name baseline was found.');
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
