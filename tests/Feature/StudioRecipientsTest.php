<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\IssueSelectedCertificate;
use App\Jobs\SendTransactionalEmail;
use App\Models\Certificate;
use App\Models\CertificateApproval;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\CertificateService;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudioRecipientsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($this->owner);
        $this->webinar = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $this->webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
        $this->webinar->eligibilityRules()->create(['requirement' => 'posttest', 'is_required' => true, 'minimum_score' => 8]);
        $this->webinar->forms()->create(['type' => 'registration', 'title' => 'Registration', 'status' => 'published']);
        $this->webinar->forms()->create(['type' => 'posttest', 'title' => 'Post-Test', 'status' => 'published']);
        $image = UploadedFile::fake()->image('certificate.png', 1200, 850);
        Storage::disk('local')->put('certificate-backgrounds/studio-test.png', file_get_contents($image->getRealPath()));
        $this->webinar->certificateTemplates()->create(['name' => 'Studio fixture', 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/studio-test.png', 'is_active' => true,
            'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 36, 'name_font_family' => 'sans', 'accent' => '#172554']]);
    }

    private function add(array $data = [])
    {
        return $this->postJson(route('admin.certificates.manual.store', $this->webinar), $data + ['full_name' => 'Manual Person', 'email' => 'manual@example.test']);
    }

    private function manual(): Participant
    {
        $this->add()->assertCreated();

        return $this->webinar->participants()->sole();
    }

    private function qualifies(Participant $participant): void
    {
        $participant->update(['verified_at' => now()]);
        $this->webinar->forms()->where('type', 'posttest')->firstOrFail()->submissions()->create([
            'participant_id' => $participant->id, 'attempt_number' => 1, 'status' => 'submitted', 'score' => 8, 'maximum_score' => 10, 'submitted_at' => now(),
        ]);
    }

    private function roster(): array
    {
        return app(EligibilityService::class)->certificateParticipantsQuery($this->webinar->fresh())->pluck('participants.id')->all();
    }

    public function test_submissions_and_rule_changes_drive_studio_without_approval_or_duplicate_rows(): void
    {
        $participant = $this->webinar->participants()->create(['full_name' => 'Automatic Person', 'email' => 'auto@example.test']);
        $this->assertSame([], $this->roster());
        $this->qualifies($participant);
        $this->assertSame([$participant->id], $this->roster());
        $this->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertSee('data-source="eligible"', false);
        $this->assertDatabaseCount('certificate_approvals', 0);
        $submission = $participant->submissions()->sole();
        $submission->update(['score' => 7]);
        $this->assertSame([], $this->roster());
        $this->webinar->eligibilityRules()->where('requirement', 'posttest')->update(['minimum_score' => 7]);
        $this->assertSame([$participant->id], $this->roster());
        $submission->update(['status' => 'superseded']);
        $this->assertSame([], $this->roster());
    }

    public function test_two_field_manual_add_is_audited_without_completion_or_issuance(): void
    {
        $this->add(['email' => ' Manual@Example.Test ', 'full_name' => ' Manual Person '])->assertCreated();
        $participant = $this->webinar->participants()->sole();
        $this->assertSame('manual@example.test', $participant->email);
        $this->assertSame('Manual Person', $participant->full_name);
        $this->assertNull($participant->verified_at);
        $this->assertNull($participant->email_verified_at);
        $this->assertSame([], $participant->submissions->all());
        $this->assertFalse(app(EligibilityService::class)->evaluate($participant)['eligible']);
        $this->assertSame([$participant->id], $this->roster());
        $this->assertSame('manual', $participant->certificateApproval->source);
        $this->assertTrue($participant->certificateApproval->manages_identity);
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.recipient_added', 'user_id' => $this->owner->id, 'auditable_id' => $participant->id]);
        $this->assertDatabaseCount('eligibility_overrides', 0);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        Queue::assertNothingPushed();
        $this->get(route('admin.responses.index', [$this->webinar, 'view' => 'eligibility']))->assertOk()->assertSee('Incomplete')->assertSee('Manual');
    }

    public function test_manual_recipient_uses_existing_preview_generation_and_fake_delivery_pipeline(): void
    {
        $participant = $this->manual();
        $this->get(route('admin.certification.preview', [$this->webinar, 'participant' => $participant->public_id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->post(route('admin.certificates.issue-selected', $this->webinar), ['participants' => [$participant->public_id]])->assertSessionHasErrors('preview_confirmed');
        $this->assertDatabaseCount('certificates', 0);
        $this->post(route('admin.certificates.issue-selected', $this->webinar), ['participants' => [$participant->public_id], 'preview_confirmed' => '1'])->assertSessionHas('success');
        Queue::assertPushed(IssueSelectedCertificate::class);
        $certificate = app(CertificateService::class)->issue($participant->fresh());
        $this->assertSame('manual', $certificate->eligibility_snapshot['source']);
        $this->assertFalse($certificate->eligibility_snapshot['requirements']['posttest']);
        $this->assertStringStartsWith('%PDF', Storage::disk('local')->get($certificate->file_path));
        $delivery = $certificate->deliveries()->sole();
        $this->assertStringNotContainsString('href=', $delivery->payload['html']);
        $this->assertSame('certificate', $delivery->type);
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->withArgs(fn ($email, $subject, $html, $attachments, $key) => $email === 'manual@example.test' && count($attachments) === 1 && filled($key))->andReturn('synthetic-provider-id');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertNotNull($certificate->fresh()->sent_at);
        $this->assertSame($certificate->id, app(CertificateService::class)->issue($participant->fresh())->id);
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertFalse(app(EligibilityService::class)->evaluate($participant->fresh())['eligible']);
    }

    public function test_duplicate_manual_email_is_case_insensitive_and_different_names_are_not_overwritten(): void
    {
        $participant = $this->manual();
        $this->add(['email' => 'MANUAL@example.test'])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->add(['email' => 'manual@example.test', 'full_name' => 'Different Identity'])->assertUnprocessable()->assertJsonValidationErrors('full_name');
        $this->assertSame('Manual Person', $participant->fresh()->full_name);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('certificate_approvals', 1);
    }

    public function test_eligible_participant_cannot_be_converted_to_manually_editable_identity(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'manual@example.test', 'full_name' => 'Manual Person']);
        $this->qualifies($participant);
        $this->add()->assertUnprocessable()->assertJsonValidationErrors('email')->assertJsonFragment(['This participant already appears in Certificate Studio through eligibility.']);
        $this->putJson(route('admin.certificates.manual.update', [$this->webinar, $participant]), ['full_name' => 'Changed', 'email' => 'new@example.test'])->assertUnprocessable();
        $this->deleteJson(route('admin.certificates.manual.destroy', [$this->webinar, $participant]))->assertUnprocessable();
        $this->assertDatabaseCount('certificate_approvals', 0);
    }

    public function test_same_name_different_emails_stay_separate(): void
    {
        $this->add()->assertCreated();
        $this->add(['email' => 'different@example.test'])->assertCreated();
        $this->assertCount(2, $this->roster());
        $this->assertDatabaseCount('participants', 2);
    }

    public function test_existing_incomplete_identity_can_be_authorized_but_not_edited_as_a_manual_identity(): void
    {
        $participant = $this->webinar->participants()->create(['email' => 'manual@example.test', 'full_name' => 'Manual Person', 'verified_at' => now()]);
        $this->add()->assertCreated();
        $this->assertFalse($participant->fresh()->certificateApproval->manages_identity);
        $this->putJson(route('admin.certificates.manual.update', [$this->webinar, $participant]), ['full_name' => 'Changed', 'email' => 'new@example.test'])->assertUnprocessable();
        $this->putJson(route('admin.participants.name', [$this->webinar, $participant]), ['full_name' => 'Reviewed Participant Correction'])->assertOk();
        $this->assertSame('Reviewed Participant Correction', $participant->fresh()->full_name);
        $this->assertNotNull($participant->fresh()->verified_at);
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_manual_details_can_be_corrected_before_issuance_with_conflict_protection(): void
    {
        $participant = $this->manual();
        $this->putJson(route('admin.certificates.manual.update', [$this->webinar, $participant]), ['full_name' => 'Corrected Person', 'email' => ' Corrected@Example.test '])->assertOk();
        $this->assertSame('Corrected Person', $participant->fresh()->full_name);
        $this->assertSame('corrected@example.test', $participant->fresh()->email_normalized);
        $other = $this->webinar->participants()->create(['email' => 'reserved@example.test', 'full_name' => 'Verified Person']);
        $this->putJson(route('admin.certificates.manual.update', [$this->webinar, $participant]), ['full_name' => 'Changed', 'email' => $other->email])->assertUnprocessable()->assertJsonValidationErrors('email');
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.recipient_updated']);
    }

    public function test_response_evidence_added_after_manual_creation_blocks_identity_edits(): void
    {
        $participant = $this->manual();
        $this->qualifies($participant);
        $this->putJson(route('admin.certificates.manual.update', [$this->webinar, $participant]), ['full_name' => 'Changed', 'email' => 'changed@example.test'])->assertUnprocessable();
        $this->assertCount(1, $this->roster());
        $this->deleteJson(route('admin.certificates.manual.destroy', [$this->webinar, $participant]))->assertOk();
        $this->assertCount(1, $this->roster()); // normal eligibility still governs it
    }

    public function test_removal_preserves_identity_and_audit_and_readdition_reuses_the_record(): void
    {
        $participant = $this->manual();
        $approval = $participant->certificateApproval;
        $this->deleteJson(route('admin.certificates.manual.destroy', [$this->webinar, $participant]))->assertOk();
        $this->assertSame([], $this->roster());
        $this->assertNotNull($approval->fresh()->removed_at);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.recipient_removed']);
        $this->add()->assertCreated();
        $this->assertNull($approval->fresh()->removed_at);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('certificate_approvals', 1);
    }

    public function test_issued_and_sent_records_lock_manual_details_and_preserve_history(): void
    {
        $participant = $this->manual();
        $certificate = app(CertificateService::class)->issue($participant);
        $certificate->update(['sent_at' => now()]);
        $delivery = $certificate->deliveries()->sole();
        $before = $certificate->fresh()->getRawOriginal();
        $this->putJson(route('admin.certificates.manual.update', [$this->webinar, $participant]), ['full_name' => 'Changed', 'email' => 'changed@example.test'])->assertUnprocessable();
        $this->putJson(route('admin.participants.name', [$this->webinar, $participant]), ['full_name' => 'Changed'])->assertUnprocessable();
        $this->deleteJson(route('admin.certificates.manual.destroy', [$this->webinar, $participant]))->assertUnprocessable();
        $this->assertSame($before, $certificate->fresh()->getRawOriginal());
        $this->assertNotNull($delivery->fresh());
        Storage::disk('local')->assertExists($certificate->file_path);
        $certificate->update(['revoked_at' => now(), 'status' => 'revoked']);
        $this->deleteJson(route('admin.certificates.manual.destroy', [$this->webinar, $participant]))->assertOk();
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_cross_owner_cross_webinar_unapproved_and_guest_access_is_denied(): void
    {
        $participant = $this->manual();
        $other = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($other);
        $this->add()->assertNotFound();
        $this->putJson(route('admin.certificates.manual.update', [$this->webinar, $participant]), ['full_name' => 'Changed', 'email' => 'changed@example.test'])->assertNotFound();
        $this->deleteJson(route('admin.certificates.manual.destroy', [$this->webinar, $participant]))->assertNotFound();
        $another = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $this->actingAs($this->owner)->deleteJson(route('admin.certificates.manual.destroy', [$another, $participant]))->assertNotFound();
        $pending = User::factory()->create(['role' => User::USER_ROLE, 'is_active' => false, 'approval_status' => 'pending_verification']);
        $this->actingAs($pending);
        $this->add()->assertRedirect(route('login'));
        auth()->logout();
        $this->add()->assertUnauthorized();
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_administrator_requires_existing_audited_webinar_access(): void
    {
        $admin = User::factory()->create();
        $this->actingAs($admin);
        $this->add()->assertForbidden();
        $this->withSession(['administrative_webinars.'.$this->webinar->id => now()->addMinutes(10)->timestamp]);
        $this->add()->assertCreated();
        $this->assertDatabaseHas('audit_logs', ['action' => 'certificate.recipient_added', 'user_id' => $admin->id]);
    }

    public function test_invalid_identity_erased_and_archived_records_are_protected(): void
    {
        $this->add(['full_name' => ' ', 'email' => 'invalid'])->assertUnprocessable()->assertJsonValidationErrors(['full_name', 'email']);
        $this->add(['full_name' => "Name\nInjected"])->assertUnprocessable()->assertJsonValidationErrors('full_name');
        $participant = $this->manual();
        $participant->delete();
        $this->add()->assertUnprocessable();
        $this->webinar->update(['archived_at' => now()]);
        $this->add(['email' => 'new@example.test'])->assertConflict();
    }

    public function test_roster_refresh_filters_privately_and_retains_historical_authorization(): void
    {
        $manual = $this->manual();
        $auto = $this->webinar->participants()->create(['email' => 'auto@example.test', 'full_name' => 'Automatic Person']);
        $this->qualifies($auto);
        $approval = CertificateApproval::query()->create(['participant_id' => $auto->id, 'approved_by' => $this->owner->id, 'approved_at' => now(), 'eligibility_snapshot' => ['original' => true]]);
        $before = $approval->fresh()->getRawOriginal();
        $this->post(route('admin.certificates.studio.recipients', $this->webinar), ['search' => 'manual@', 'source' => 'manual'])
            ->assertOk()->assertViewHas('participants', fn ($rows) => $rows->count() === 1 && $rows->first()->id === $manual->id);
        $this->withHeader('X-Frontend-Navigation', '1')->post(route('admin.certificates.studio.recipients', $this->webinar), ['source' => 'eligible'])
            ->assertOk()->assertJsonPath('boundary', false)->assertJsonPath('version', 1);
        $this->assertSame($before, $approval->fresh()->getRawOriginal());
    }

    public function test_pending_manual_management_keeps_password_confirmation_and_resumes_the_action(): void
    {
        config(['security.require_sensitive_action_password_confirmation' => true]);
        $participant = $this->manual(); // adding an exception needs no second approval
        $url = route('admin.certificates.manual.update', [$this->webinar, $participant]);
        $this->putJson($url, ['full_name' => 'Confirmed Correction', 'email' => 'confirmed@example.test'])->assertRedirect(route('admin.password.confirm'));
        $this->assertSame('Manual Person', $participant->fresh()->full_name);
        $this->post(route('admin.password.confirm.store'), ['password' => 'password'])->assertRedirect(route('admin.certificates.studio', $this->webinar));
        $this->assertSame('Confirmed Correction', $participant->fresh()->full_name);
        $this->assertSame('confirmed@example.test', $participant->fresh()->email);
        $this->withSession(['auth.password_confirmed_at' => 0]);
        $this->deleteJson(route('admin.certificates.manual.destroy', [$this->webinar, $participant]))->assertRedirect(route('admin.password.confirm'));
        $this->assertNull($participant->fresh()->certificateApproval->removed_at);
        $this->post(route('admin.password.confirm.store'), ['password' => 'password'])->assertRedirect(route('admin.certificates.studio', $this->webinar));
        $this->assertNotNull($participant->fresh()->certificateApproval->removed_at);
    }

    public function test_manual_name_correction_after_explicit_revocation_can_be_reissued_without_changing_the_old_pdf(): void
    {
        $participant = $this->manual();
        $original = app(CertificateService::class)->issue($participant);
        $bytes = Storage::disk('local')->get($original->file_path);
        $this->post(route('admin.certificates.revoke', $original), ['reason' => 'Correct the certificate spelling.'])->assertRedirect();
        $this->putJson(route('admin.participants.name', [$this->webinar, $participant]), ['full_name' => 'Corrected Manual Person'])->assertOk();
        $reissue = app(CertificateService::class)->issue($participant->fresh());
        $this->assertNotSame($original->id, $reissue->id);
        $this->assertSame('Manual Person', $original->fresh()->recipient_name);
        $this->assertSame('Corrected Manual Person', $reissue->recipient_name);
        $this->assertSame($bytes, Storage::disk('local')->get($original->file_path));
        $this->assertDatabaseCount('certificates', 2);
        $this->assertDatabaseCount('email_deliveries', 2);
    }

    public function test_local_selected_send_processes_only_its_recipient_and_substitutes_email_placeholders(): void
    {
        $recipient = $this->manual();
        $other = $this->webinar->participants()->create(['email' => 'excluded@example.test', 'full_name' => 'Excluded Non Eligible']);
        $queued = app(CertificateService::class)->queue($recipient);
        config(['operations.local_immediate_delivery' => true]);
        $this->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertSee('Send now');
        Queue::fake([SendTransactionalEmail::class]);
        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->withArgs(function ($email, $subject, $html, $attachments) use ($recipient) {
            $this->assertSame($recipient->email, $email);
            $this->assertStringContainsString($this->webinar->title, $subject);
            $this->assertStringContainsString($recipient->full_name, $html);
            $this->assertStringNotContainsString('{{', $subject.$html);
            $this->assertCount(1, $attachments);

            return true;
        })->andReturn('mock-provider-acceptance');
        $this->app->instance(TransactionalMailer::class, $mailer);
        $this->post(route('admin.certificates.issue-selected', $this->webinar), ['participants' => [$recipient->public_id], 'preview_confirmed' => '1'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('certificates', ['id' => $queued->id, 'participant_id' => $recipient->id, 'status' => 'issued']);
        $this->assertDatabaseHas('email_deliveries', ['participant_id' => $recipient->id, 'type' => 'certificate', 'status' => 'sent']);
        $this->assertDatabaseMissing('email_deliveries', ['participant_id' => $other->id]);
        $this->assertDatabaseCount('email_deliveries', 1);
        Queue::assertNothingPushed();
        $this->post(route('admin.certificates.issue-selected', $this->webinar), ['participants' => [$recipient->public_id], 'preview_confirmed' => '1'])->assertRedirect();
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_local_all_ready_batch_does_not_notify_noneligible_participants(): void
    {
        $recipient = $this->manual();
        $other = $this->webinar->participants()->create(['email' => 'excluded-batch@example.test', 'full_name' => 'Excluded From Batch']);
        config(['operations.local_immediate_delivery' => true]);
        Queue::fake([SendTransactionalEmail::class]);
        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->withArgs(fn ($email, $subject, $html, $attachments) => $email === $recipient->email && count($attachments) === 1)->andReturn('mock-batch-acceptance');
        $this->app->instance(TransactionalMailer::class, $mailer);
        $this->post(route('admin.certificates.batch', $this->webinar))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertDatabaseHas('certificate_batches', ['status' => 'completed']);
        $this->assertDatabaseHas('email_deliveries', ['participant_id' => $recipient->id, 'status' => 'sent', 'type' => 'certificate']);
        $this->assertDatabaseMissing('email_deliveries', ['participant_id' => $other->id]);
        $this->assertDatabaseCount('email_deliveries', 1);
        Queue::assertNothingPushed();
    }

    public function test_local_stale_generation_warning_uses_send_now_and_never_requests_a_worker(): void
    {
        $recipient = $this->manual();
        $certificate = app(CertificateService::class)->queue($recipient);
        Certificate::whereKey($certificate->id)->update(['updated_at' => now()->subMinutes(3)]);
        config(['operations.local_immediate_delivery' => true, 'webinar.email.certificate_provider' => 'brevo',
            'services.brevo.key' => 'synthetic-key-never-used']);
        $this->get(route('admin.certificates.studio', $this->webinar))->assertOk()
            ->assertSee('Use Send now on that recipient')->assertSee('Nothing will be sent automatically.')
            ->assertDontSee('Start the certificate worker')->assertDontSee('php artisan queue:work');
        $this->assertSame('processing', $certificate->fresh()->status);
        $this->assertDatabaseCount('email_deliveries', 0);
        Queue::assertNothingPushed();
        config(['operations.local_immediate_delivery' => false]);
        $this->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertSee('Start the certificate worker');
    }

    public function test_sent_tab_exposes_confirmed_resend_only_for_currently_eligible_recipients_without_sending_on_view(): void
    {
        $manual = $this->manual();
        $eligible = $this->webinar->participants()->create(['full_name' => 'Corrected Recipient', 'email' => 'corrected@example.test']);
        $this->qualifies($eligible);
        $ineligible = $this->webinar->participants()->create(['full_name' => 'Incomplete Recipient', 'email' => 'incomplete@example.test']);
        $certificates = collect([$eligible, $manual, $ineligible])->map(function ($participant) {
            $certificate = Certificate::create([
                'webinar_id' => $this->webinar->id, 'participant_id' => $participant->id,
                'certificate_template_id' => $this->webinar->certificateTemplates()->sole()->id,
                'verification_code' => 'CERT-'.str_pad((string) $participant->id, 12, '0', STR_PAD_LEFT),
                'recipient_name' => $participant->full_name, 'status' => 'issued', 'storage_disk' => 'local',
                'issued_at' => now(), 'sent_at' => now(),
            ]);
            app(AuditService::class)->record(request(), 'certificate.signature_corrected', $certificate);

            return $certificate;
        });

        foreach (['admin.certificates.studio', 'admin.certificates.studio.recipients'] as $route) {
            $response = $route === 'admin.certificates.studio'
                ? $this->get(route($route, $this->webinar))
                : $this->post(route($route, $this->webinar));
            $response->assertOk()->assertSee('Resend corrected certificate')
                ->assertSee('data-confirm-title="Resend this certificate?"', false)
                ->assertSee(route('admin.certificates.resend', $certificates[0]), false)
                ->assertDontSee(route('admin.certificates.resend', $certificates[1]), false)
                ->assertDontSee(route('admin.certificates.resend', $certificates[2]), false);
        }
        $this->assertDatabaseCount('email_deliveries', 0);
        Queue::assertNothingPushed();
    }

    private function correctedSentRecipient(string $suffix): Certificate
    {
        $person = $this->webinar->participants()->create(['full_name' => 'Correction Recipient '.$suffix, 'email' => 'correction'.$suffix.'@example.test']);
        $this->qualifies($person);
        $certificate = Certificate::create([
            'webinar_id' => $this->webinar->id, 'participant_id' => $person->id,
            'certificate_template_id' => $this->webinar->certificateTemplates()->sole()->id,
            'verification_code' => 'CERT-'.str_pad($suffix, 12, '0', STR_PAD_LEFT), 'recipient_name' => $person->full_name,
            'status' => 'issued', 'issued_at' => now(), 'sent_at' => now(), 'storage_disk' => 'local',
        ]);
        $certificate->update(['file_path' => 'certificates/'.$certificate->public_id.'.pdf']);
        Storage::disk('local')->put($certificate->file_path, '%PDF-1.4 synthetic corrected attachment '.$suffix);
        app(AuditService::class)->record(request(), 'certificate.signature_corrected', $certificate);

        return $certificate;
    }

    public function test_86_person_correction_plan_creates_no_mail_until_each_step_and_replays_are_idempotent(): void
    {
        config(['operations.local_immediate_delivery' => true]);
        $certificates = collect(range(1, 86))->map(fn ($number) => $this->correctedSentRecipient((string) $number));
        $page = $this->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertSee('Preview corrected PDF');
        $this->assertCount(86, $page->viewData('participants'));
        $this->getJson(route('admin.certificates.studio.status', $this->webinar))->assertOk()->assertJsonPath('certificates.0.status', 'ready');
        $plan = $this->postJson(route('admin.certificates.send-plan', $this->webinar), [
            'mode' => 'resend', 'certificates' => $certificates->pluck('id')->all(),
        ])->assertOk()->assertJsonCount(86, 'items')->json();
        $this->assertDatabaseCount('email_deliveries', 0);
        foreach ($certificates as $index => $certificate) {
            $payload = ['plan_token' => $plan['plan_token'], 'index' => $index];
            $this->postJson($plan['step_url'], $payload)->assertOk()->assertJsonPath('outcome', 'captured');
            $this->postJson($plan['step_url'], $payload)->assertOk()->assertJsonPath('outcome', 'captured');
            $this->assertDatabaseCount('email_deliveries', $index + 1);
            $delivery = $certificate->deliveries()->sole();
            $this->assertSame($certificate->participant->email, $delivery->recipient_email);
            $this->assertSame('sent', $delivery->status);
            $this->travel(5)->seconds();
        }
        $this->assertDatabaseCount('certificates', 86);
        $this->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertViewHas('participants', fn ($people) => $people->isEmpty());
        $this->assertDatabaseMissing('email_deliveries', ['type' => 'eligibility_notification']);
        Queue::assertNothingPushed();
    }

    public function test_correction_plan_rejects_mixed_ineligible_selection_and_cannot_be_used_for_another_webinar(): void
    {
        config(['operations.local_immediate_delivery' => true]);
        $certificate = $this->correctedSentRecipient('1');
        $this->postJson(route('admin.certificates.send-plan', $this->webinar), [
            'mode' => 'resend', 'certificates' => [$certificate->id, 999999],
        ])->assertUnprocessable();
        $plan = $this->postJson(route('admin.certificates.send-plan', $this->webinar), [
            'mode' => 'resend', 'certificates' => [$certificate->id],
        ])->assertOk()->json();
        $other = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $this->postJson(route('admin.certificates.send-step', $other), ['plan_token' => $plan['plan_token'], 'index' => 0])->assertUnprocessable();
        $this->assertDatabaseCount('email_deliveries', 0);
        $certificate->participant->submissions()->update(['score' => 0]);
        $this->postJson(route('admin.certificates.send-plan', $this->webinar), [
            'mode' => 'resend', 'certificates' => [$certificate->id],
        ])->assertUnprocessable();
        Queue::assertNothingPushed();
    }

    public function test_selected_progress_plan_issues_only_its_one_claimed_recipient(): void
    {
        config(['operations.local_immediate_delivery' => true]);
        Queue::fake([SendTransactionalEmail::class]);
        $person = $this->webinar->participants()->create(['full_name' => 'New Eligible', 'email' => 'neweligible@example.test']);
        $this->qualifies($person);
        $plan = $this->postJson(route('admin.certificates.send-plan', $this->webinar), [
            'mode' => 'selected', 'participants' => [$person->public_id],
        ])->assertOk()->assertJsonCount(1, 'items')->json();
        $this->assertDatabaseCount('certificates', 0);
        $this->postJson($plan['step_url'], ['plan_token' => $plan['plan_token'], 'index' => 0])->assertOk()->assertJsonPath('outcome', 'captured');
        $this->assertDatabaseCount('certificates', 1);
        $this->assertDatabaseCount('email_deliveries', 1);
        Queue::assertNothingPushed();
    }

    public function test_selecting_a_corrected_recipient_in_to_send_resends_the_exact_file_without_reissuing(): void
    {
        config(['operations.local_immediate_delivery' => true]);
        $certificate = $this->correctedSentRecipient('1');
        $before = $certificate->fresh()->getRawOriginal();
        $pdf = Storage::disk('local')->get($certificate->file_path);
        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->withArgs(fn ($email, $subject, $html, $attachments) => $email === $certificate->participant->email && base64_decode($attachments[0]['content']) === $pdf)->andReturn('mock-correction');
        $this->app->instance(TransactionalMailer::class, $mailer);
        $plan = $this->postJson(route('admin.certificates.send-plan', $this->webinar), [
            'mode' => 'selected', 'participants' => [$certificate->participant->public_id],
        ])->assertOk()->assertJsonPath('items.0.mode', 'resend')->json();
        $this->postJson($plan['step_url'], ['plan_token' => $plan['plan_token'], 'index' => 0])->assertOk()->assertJsonPath('outcome', 'captured');
        $this->assertDatabaseCount('certificates', 1);
        $this->assertSame($before, $certificate->fresh()->getRawOriginal());
        $this->assertSame($pdf, Storage::disk('local')->get($certificate->file_path));
        $this->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertViewHas('participants', fn ($people) => $people->isEmpty());
        Queue::assertNothingPushed();
    }

    public function test_held_import_rows_block_new_bulk_issuance_but_not_explicit_corrected_resends(): void
    {
        config(['operations.local_immediate_delivery' => true]);
        $certificate = $this->correctedSentRecipient('1');
        $import = Import::create([
            'webinar_id' => $this->webinar->id, 'form_id' => $this->webinar->forms()->first()->id,
            'original_filename' => 'held-synthetic.csv', 'file_hash' => str_repeat('a', 64), 'file_size' => 1, 'imported_by' => $this->owner->id,
        ]);
        ImportIssue::create(['import_id' => $import->id, 'webinar_id' => $this->webinar->id,
            'row_number' => 1, 'issue_type' => 'unmatched', 'issue_message' => 'Synthetic unresolved row']);
        $this->postJson(route('admin.certificates.send-plan', $this->webinar), ['mode' => 'ready'])->assertUnprocessable();
        $plan = $this->postJson(route('admin.certificates.send-plan', $this->webinar), [
            'mode' => 'selected', 'participants' => [$certificate->participant->public_id],
        ])->assertOk()->assertJsonPath('items.0.mode', 'resend')->json();
        $this->postJson($plan['step_url'], ['plan_token' => $plan['plan_token'], 'index' => 0])->assertOk()->assertJsonPath('outcome', 'captured');
        $this->assertSame(1, $this->webinar->importIssues()->whereNull('resolved_at')->count());
        $this->assertDatabaseCount('email_deliveries', 1);
        Queue::assertNothingPushed();
    }

    public function test_uncertain_progress_step_halts_and_its_replay_never_calls_the_provider_again(): void
    {
        config(['operations.local_immediate_delivery' => true]);
        $certificate = $this->correctedSentRecipient('1');
        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new ConnectionException('Synthetic connection interruption'));
        $this->app->instance(TransactionalMailer::class, $mailer);
        $plan = $this->postJson(route('admin.certificates.send-plan', $this->webinar), [
            'mode' => 'resend', 'certificates' => [$certificate->id],
        ])->assertOk()->json();
        $payload = ['plan_token' => $plan['plan_token'], 'index' => 0];
        $this->postJson($plan['step_url'], $payload)->assertOk()->assertJsonPath('outcome', 'unknown')->assertJsonPath('halt', true);
        $this->postJson($plan['step_url'], $payload)->assertOk()->assertJsonPath('outcome', 'unknown');
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->assertSame(1, $certificate->deliveries()->sole()->attempts);
        Queue::assertNothingPushed();
    }
}
