<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Import;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CertificateNameLayout;
use App\Services\CertificateService;
use App\Services\CsvImportService;
use App\Services\Email\BrevoTransactionalMailer;
use App\Services\ParticipantPrivacyService;
use Dompdf\Dompdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ApprovesCertificateFixtures;
use Tests\TestCase;

class AssessmentRemediationTest extends TestCase
{
    use ApprovesCertificateFixtures;
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->webinar = Webinar::query()->create([
            'title' => 'Remediation Fixture',
            'slug' => 'remediation-'.uniqid(),
            'status' => 'published',
            'timezone' => 'UTC',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDays(2)->addHours(4),
            'created_by' => $this->administrator->id,
            'data_retention_days' => 30,
        ]);
        foreach (['registration', 'pretest', 'posttest', 'evaluation'] as $type) {
            $this->webinar->forms()->create(['type' => $type, 'title' => ucfirst($type), 'status' => 'published']);
        }
    }

    public function test_brevo_retry_after_is_bounded(): void
    {
        $this->assertSame(5, BrevoTransactionalMailer::retryAfterSeconds(null));
        $this->assertSame(5, BrevoTransactionalMailer::retryAfterSeconds(''));
        $this->assertSame(10, BrevoTransactionalMailer::retryAfterSeconds('10'));
        $this->assertSame(60, BrevoTransactionalMailer::retryAfterSeconds('999'));
        $this->assertSame(1, BrevoTransactionalMailer::retryAfterSeconds('0'));
        $this->assertSame(5, BrevoTransactionalMailer::retryAfterSeconds('not-a-date'));
    }

    public function test_import_options_require_source_verification_and_checkable_completion_evidence(): void
    {
        $this->actingAs($this->administrator);
        $form = $this->webinar->forms()->where('type', 'registration')->firstOrFail();
        $import = app(CsvImportService::class)->storePending(
            $this->webinar, $form,
            UploadedFile::fake()->createWithContent('r.csv', "Email Address,Full Name\nuser@example.com,Example Person\n")
        );

        try {
            CsvImportService::validateOptions($import, [
                'allow_import_time' => true,
            ]);
            $this->fail('Source verification should be required.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Google Form source', $exception->getMessage());
        }

        $evaluation = $this->webinar->forms()->where('type', 'evaluation')->firstOrFail();
        $summary = app(CsvImportService::class)->storePending(
            $this->webinar, $evaluation,
            UploadedFile::fake()->createWithContent('summary.csv', "Email Address\nuser@example.com\n")
        );
        try {
            CsvImportService::validateOptions($summary, [
                'required_columns' => [],
                'completion_verified' => '1',
                'attestation_reason' => 'A human reviewed the source response tab.',
                'source_description' => 'Evaluation Form v2, exported 2026-08-20',
                'source_confirmed' => '1',
                'allow_import_time' => true,
            ]);
            $this->fail('Summary imports must not establish completion.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Summary-only', $exception->getMessage());
        }

        $options = CsvImportService::validateOptions($import, [
            'source_description' => 'Registration Form v2, exported 2026-08-20', 'source_confirmed' => '1',
            'allow_import_time' => true,
        ]);
        $this->assertSame('Registration Form v2, exported 2026-08-20', $options['source_description']);
    }

    public function test_wide_export_retains_mapped_columns_in_review_evidence(): void
    {
        $this->actingAs($this->administrator);
        $form = $this->webinar->forms()->where('type', 'registration')->firstOrFail();
        $headers = array_merge(['Email Address', 'Full Name'], array_map(fn ($i) => "Filler {$i}", range(1, 70)));
        $headers[65] = 'Organization';
        $row = array_merge(['wide@example.com', 'Wide Person'], array_fill(0, 70, 'x'));
        $row[65] = 'Acme Org';
        $csv = implode(',', array_map(fn ($v) => '"'.$v.'"', $headers))."\n".implode(',', array_map(fn ($v) => '"'.$v.'"', $row))."\n";
        $import = app(CsvImportService::class)->storePending($this->webinar, $form, UploadedFile::fake()->createWithContent('wide.csv', $csv));
        app(CsvImportService::class)->process($import, ['email' => 0, 'full_name' => 1, 'organization' => 65], options: [
            'allow_import_time' => true, 'source_description' => 'Registration Form v1, exported 2026-08-20', 'source_confirmed' => true,
        ]);
        $this->assertSame('Acme Org', Participant::query()->sole()->organization);
    }

    public function test_batch_records_per_recipient_outcomes(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'batch@example.com', 'full_name' => 'Batch Person', 'verified_at' => now()]);
        $image = imagecreatetruecolor(1200, 850);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        ob_start();
        imagepng($image);
        $png = ob_get_clean();
        imagedestroy($image);
        Storage::disk('local')->put('certificate-backgrounds/batch.png', $png);
        $template = $this->webinar->certificateTemplates()->create([
            'name' => 'Batch', 'storage_disk' => 'local', 'is_active' => true,
            'background_path' => 'certificate-backgrounds/batch.png',
            'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 42, 'name_font_family' => 'sans', 'accent' => '#172554'],
        ]);
        $batch = $this->webinar->certificateBatches()->create([
            'certificate_template_id' => $template->id, 'created_by' => $this->administrator->id, 'status' => 'pending',
        ]);
        $this->approveCertificateFixtures($this->webinar);
        app(CertificateService::class)->issueBatch($batch);
        $this->assertGreaterThanOrEqual(1, $batch->items()->count());
    }

    public function test_identity_merge_moves_responses_with_audit(): void
    {
        $primary = $this->webinar->participants()->create(['email' => 'work@example.com', 'full_name' => 'Same Person', 'verified_at' => now()]);
        $secondary = $this->webinar->participants()->create(['email' => 'personal@example.com', 'full_name' => 'Same Person', 'verified_at' => now()]);
        $pretest = $this->webinar->forms()->where('type', 'pretest')->firstOrFail();
        $posttest = $this->webinar->forms()->where('type', 'posttest')->firstOrFail();
        $primary->submissions()->create(['form_id' => $pretest->id, 'status' => 'submitted']);
        $secondary->submissions()->create(['form_id' => $posttest->id, 'status' => 'submitted']);

        $this->actingAs($this->administrator)->post(
            route('admin.participants.merge', [$this->webinar, $primary]),
            ['secondary_email' => 'personal@example.com', 'reason' => 'Same person used work and personal mailboxes; verified by organizer.', 'merge_confirmed' => '1']
        )->assertRedirect();

        $this->assertSame(2, $primary->fresh()->submissions()->count());
        $this->assertTrue(Participant::query()->where('email_normalized', 'personal@example.com')->doesntExist());
        $this->assertDatabaseHas('audit_logs', ['action' => 'participant.merged']);
    }

    public function test_resend_requires_confirmation_for_unknown_provider_outcome(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'unknown@example.com', 'full_name' => 'Unknown Person', 'verified_at' => now()]);
        $certificate = Certificate::query()->create([
            'verification_code' => 'CERT-'.strtoupper(uniqid()),
            'webinar_id' => $this->webinar->id, 'participant_id' => $participant->id,
            'certificate_template_id' => $this->webinar->certificateTemplates()->create([
                'name' => 'T', 'storage_disk' => 'local', 'is_active' => true, 'layout' => [],
            ])->id,
            'recipient_name' => 'Unknown Person', 'storage_disk' => 'local', 'status' => 'issued', 'issued_at' => now(),
        ]);
        EmailDelivery::query()->create([
            'webinar_id' => $this->webinar->id, 'participant_id' => $participant->id, 'certificate_id' => $certificate->id,
            'type' => 'certificate', 'provider' => 'log', 'recipient_email' => 'unknown@example.com',
            'subject' => 's', 'status' => 'failed', 'last_error' => 'Provider outcome unknown (connection lost). Reconcile first.',
            'scheduled_at' => now(), 'failed_at' => now(),
        ]);

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.resend', $certificate))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'uncertain provider outcome'));

        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.resend', $certificate), ['confirm_unknown' => '1'])
            ->assertSessionHasErrors('provider_reconciliation_reference');
    }

    public function test_stale_pending_prune_command_cancels_and_deletes(): void
    {
        $form = $this->webinar->forms()->where('type', 'registration')->firstOrFail();
        $import = Import::query()->create([
            'webinar_id' => $this->webinar->id, 'form_id' => $form->id, 'status' => Import::STATUS_PENDING,
            'original_filename' => 'stale.csv', 'file_hash' => hash('sha256', 'x'), 'file_size' => 1,
            'column_map' => ['headers' => ['Email Address']], 'imported_by' => $this->administrator->id,
            'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2),
        ]);
        Storage::disk('local')->put('imports-pending/import-'.$import->id.'.csv', 'Email Address');
        $this->artisan('imports:prune-stale')->assertSuccessful();
        $this->assertSame(Import::STATUS_CANCELLED, $import->fresh()->status);
        $this->assertFalse(Storage::disk('local')->exists('imports-pending/import-'.$import->id.'.csv'));
    }

    public function test_header_signature_flags_a_changed_export_layout(): void
    {
        $this->assertSame(
            CsvImportService::headerSignature(['Email Address', 'Full  Name']),
            CsvImportService::headerSignature(['email address', 'full name'])
        );
        $this->assertNotSame(
            CsvImportService::headerSignature(['Email Address', 'Full Name']),
            CsvImportService::headerSignature(['Email Address', 'Score'])
        );
    }

    public function test_mapping_warns_when_headers_differ_from_the_last_import(): void
    {
        $this->actingAs($this->administrator);
        $form = $this->webinar->forms()->where('type', 'registration')->firstOrFail();
        $service = app(CsvImportService::class);
        $first = $service->storePending($this->webinar, $form, UploadedFile::fake()->createWithContent(
            'v1.csv', "\"Email Address\",\"Full Name\"\n\"a@example.com\",\"Person A\"\n"
        ));
        $service->process($first, $first->column_map['map'], options: [
            'allow_import_time' => true,
            'source_description' => 'Registration Form v1, exported 2026-08-20',
            'source_confirmed' => true,
        ]);

        $second = $service->storePending($this->webinar, $form, UploadedFile::fake()->createWithContent(
            'v2.csv', "\"Email Address\",\"Full Name\",\"Extra Question\"\n\"b@example.com\",\"Person B\",\"Yes\"\n"
        ));

        $this->get(route('admin.webinars.imports.map', [$this->webinar, $second]))
            ->assertOk()
            ->assertSee('Header change detected');
    }

    public function test_erasure_keeps_a_non_pii_import_summary(): void
    {
        $this->actingAs($this->administrator);
        $form = $this->webinar->forms()->where('type', 'evaluation')->firstOrFail();
        $service = app(CsvImportService::class);
        $import = $service->storePending($this->webinar, $form, UploadedFile::fake()->createWithContent(
            'eval.csv', "\"Email Address\",\"Feedback\"\n\"person@example.com\",\"Useful session\"\n"
        ));
        $service->process($import, $import->column_map['map'], options: [
            'allow_import_time' => true,
            'required_columns' => [1],
            'source_description' => 'Evaluation Form v1, exported 2026-08-20',
            'source_confirmed' => true,
        ]);

        app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);

        $summary = $import->fresh()->column_map;
        $this->assertTrue($summary['erased'] ?? false);
        $this->assertArrayNotHasKey('headers', $summary);
        $this->assertSame(
            CsvImportService::headerSignature(['Email Address', 'Feedback']),
            $summary['header_signature']
        );
        $this->assertSame('Evaluation Form v1, exported 2026-08-20', $summary['source_description']);
        $this->assertNotNull($import->fresh()->privacy_erased_at);
        $this->assertSame($import->file_hash, $import->fresh()->file_hash);
    }

    public function test_imports_beyond_the_row_cap_are_rejected(): void
    {
        $this->actingAs($this->administrator);
        $form = $this->webinar->forms()->where('type', 'registration')->firstOrFail();
        $lines = ['"Email Address","Full Name"'];
        for ($i = 0; $i < CsvImportService::MAX_ROWS + 1; $i++) {
            $lines[] = '"user'.$i.'@example.com","Person '.$i.'"';
        }
        try {
            app(CsvImportService::class)->storePending(
                $this->webinar, $form, UploadedFile::fake()->createWithContent('big.csv', implode("\n", $lines)."\n")
            );
            $this->fail('Imports beyond MAX_ROWS should be rejected.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString((string) CsvImportService::MAX_ROWS, str_replace(',', '', $exception->getMessage()));
        }
        $this->assertSame(0, Participant::query()->count());
    }

    public function test_name_layout_width_matches_the_browser_preview_at_every_position(): void
    {
        $dompdf = new Dompdf;
        foreach ([50, 20, 80, 62] as $left) {
            $fitted = app(CertificateNameLayout::class)->fit(
                'Ana Santos Reyes',
                ['name_left' => $left, 'name_top' => 55, 'name_font_size' => 42],
                210 / 297,
                $dompdf
            );
            // Same safe-width rule as certificate-preview.blade.php: the usable
            // width shrinks symmetrically as the anchor moves off-centre.
            $this->assertEqualsWithDelta(min(94, 2 * min($left, 100 - $left)), $fitted['width_percent'], 0.0001);
            $this->assertGreaterThanOrEqual(12, $fitted['font_size']);
        }

        // At the extreme edge the preview and the PDF agree there is almost no
        // room, so a long name is refused instead of shrunk below legibility.
        try {
            app(CertificateNameLayout::class)->fit(
                'Maria Alexandra Cristina Isabel Santos de los Reyes',
                ['name_left' => 5, 'name_top' => 55, 'name_font_size' => 42],
                210 / 297,
                $dompdf
            );
            $this->fail('A long name at the page edge should be refused, not shrunk.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('cannot fit legibly', $exception->getMessage());
        }
    }
}
