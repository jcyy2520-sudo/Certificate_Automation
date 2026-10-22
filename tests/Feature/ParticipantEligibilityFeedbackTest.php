<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\SendTransactionalEmail;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\EligibilityReview;
use App\Models\EmailDelivery;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use App\Services\EligibilityService;
use App\Services\ParticipantEligibilityService;
use App\Services\ParticipantPrivacyService;
use App\Services\ParticipantStatusLinkService;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ParticipantEligibilityFeedbackTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Webinar $webinar;

    private Participant $participant;

    private array $forms;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        config(['webinar.document_disk' => 'local']);
        $this->admin = User::factory()->create();
        $this->webinar = Webinar::factory()->create(['created_by' => $this->admin->id]);
        foreach (['registration', 'pretest', 'posttest', 'evaluation'] as $type) {
            $this->forms[$type] = $this->webinar->forms()->create(['type' => $type, 'title' => $type, 'status' => 'draft']);
        }
        $this->participant = $this->webinar->participants()->create(['email' => 'person@example.test', 'full_name' => 'Participant One', 'verified_at' => now()]);
        foreach (['registration', 'pretest', 'posttest'] as $type) {
            $this->webinar->eligibilityRules()->create(['requirement' => $type, 'is_required' => true, 'minimum_score' => $type === 'posttest' ? 7 : null]);
        }
    }

    private function summary(?Participant $participant = null): array
    {
        return app(ParticipantEligibilityService::class)->summary(($participant ?? $this->participant)->fresh());
    }

    private function submit(string $type, float $score = 8, array $extra = []): Submission
    {
        return Submission::query()->create(['participant_id' => $this->participant->id, 'form_id' => $this->forms[$type]->id,
            'status' => 'submitted', 'score' => $score, 'maximum_score' => 10, 'submitted_at' => now(), ...$extra]);
    }

    private function notify(array $extra = [], ?string $key = null)
    {
        return $this->actingAs($this->admin)->post(route('admin.webinars.eligibility.notify', $this->webinar), [
            'submission_key' => $key ?? (string) Str::uuid(), 'confirmed' => '1', 'participants' => [$this->participant->public_id], ...$extra,
        ]);
    }

    private function access(?Participant $participant = null): void
    {
        $p = $participant ?? $this->participant;
        $url = DB::transaction(fn () => app(ParticipantStatusLinkService::class)->createLink($this->webinar, $p, now()->addDay()));
        parse_str(parse_url($url, PHP_URL_FRAGMENT), $fragment);
        $this->post(route('forms.public.status.access.consume', $this->forms['registration']->public_token), ['access_token' => $fragment['token']])->assertRedirect(route('forms.public.status.view', $this->forms['registration']->public_token));
    }

    private function review(array $extra = []): EligibilityReview
    {
        $this->access();
        $this->post(route('forms.public.status.review.store', $this->forms['registration']->public_token), [
            'submission_key' => (string) Str::uuid(), 'explanation' => 'Please check my post assessment submission.', ...$extra,
        ])->assertSessionHasNoErrors()->assertRedirect();

        return $this->participant->eligibilityReviews()->latest('id')->firstOrFail();
    }

    private function issue(string $type = ImportIssue::TYPE_UNMATCHED_EMAIL, ?string $email = null): ImportIssue
    {
        $import = Import::query()->create(['webinar_id' => $this->webinar->id, 'form_id' => $this->forms['posttest']->id,
            'file_size' => 100, 'imported_by' => $this->admin->id, 'original_filename' => 'synthetic.csv', 'file_hash' => hash('sha256', Str::uuid()),
            'status' => Import::STATUS_COMMITTED, 'committed_at' => now(), 'column_map' => ['headers' => []]]);

        return $import->issues()->create(['webinar_id' => $this->webinar->id, 'row_number' => 2,
            'normalized_email' => $email ?? $this->participant->email, 'issue_type' => $type, 'issue_message' => 'Synthetic held record',
            'raw_data' => ['_resolved' => ['name' => $this->participant->full_name, 'score' => 8, 'maximum_score' => 10,
                'submitted_at' => now()->subDay()->toIso8601String(), 'options' => []]]]);
    }

    public function test_required_rules_are_reused_and_optional_evaluation_is_not_imposed(): void
    {
        $this->submit('pretest');
        $this->submit('posttest');
        $summary = $this->summary();
        $this->assertSame('eligible', $summary['status']);
        $evaluation = collect($summary['activities'])->firstWhere('type', 'evaluation');
        $this->assertFalse($evaluation['required']);
        $this->assertSame('Not Found', $evaluation['state']);
        $this->assertSame('Not generated', $summary['certificate_status']);
        $this->assertSame('Not queued', $summary['delivery_status']);
        $this->assertSame(app(EligibilityService::class)->evaluate($this->participant->fresh()), $summary['evaluation']);
    }

    public function test_missing_and_unmatched_records_are_not_final_failures_or_name_matched(): void
    {
        $this->issue();
        $summary = $this->summary();
        $this->assertSame('incomplete', $summary['status']);
        $this->assertSame('Under Review', collect($summary['activities'])->firstWhere('type', 'posttest')['state']);
        $this->assertSame('Not Found', collect($summary['activities'])->firstWhere('type', 'pretest')['state']);
        $other = $this->webinar->participants()->create(['full_name' => $this->participant->full_name, 'email' => 'different@example.test']);
        $this->assertSame('Not Found', collect($this->summary($other)['activities'])->firstWhere('type', 'posttest')['state']);
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_low_scores_distinguish_completed_activity_from_unmet_requirement(): void
    {
        $this->submit('posttest', 4);
        $activity = collect($this->summary()['activities'])->firstWhere('type', 'posttest');
        $this->assertSame('Completed', $activity['state']);
        $this->assertFalse($activity['met']);
        $this->assertSame('4.00', $activity['records'][0]['score']);
        $this->assertStringContainsString('does not satisfy', $activity['detail']);
    }

    public function test_import_time_is_never_presented_as_a_verified_original_submission_time(): void
    {
        $this->submit('posttest', 8, ['metadata' => ['source' => 'csv_import', 'timestamp_source' => 'import_time', 'import_id' => 99, 'import_row_number' => 4]]);
        $record = collect($this->summary()['activities'])->firstWhere('type', 'posttest')['records'][0];
        $this->assertStringContainsString('original submission time unavailable', $record['timestamp_kind']);
        $this->assertSame('I-99 / row 4', $record['import_reference']);
    }

    public function test_public_page_never_exposes_answers_raw_imports_or_other_participants(): void
    {
        $this->submit('posttest', 8, ['imported_data' => ['original' => [['value' => 'SECRET TEST ANSWER']]]]);
        $other = $this->webinar->participants()->create(['email' => 'private-other@example.test', 'full_name' => 'Private Other Person']);
        $this->access();
        $this->get(route('forms.public.status.view', $this->forms['registration']->public_token))->assertOk()
            ->assertSee('Incomplete / Under Review')->assertSee('S-')->assertDontSee('SECRET TEST ANSWER')->assertDontSee($other->email)->assertDontSee($other->full_name);
    }

    public function test_review_requires_a_live_grant_and_is_duplicate_safe_without_changing_eligibility(): void
    {
        $route = route('forms.public.status.review.store', $this->forms['registration']->public_token);
        $this->post($route, ['explanation' => 'Someone else claims this'])->assertForbidden();
        $before = $this->summary()['evaluation'];
        $review = $this->review();
        $this->post($route, ['submission_key' => (string) Str::uuid(), 'explanation' => 'Repeated form submission'])->assertRedirect();
        $this->assertDatabaseCount('eligibility_reviews', 1);
        $this->assertSame($before, $this->summary()['evaluation']);
        $this->assertNull($review->decided_by);
        $this->assertStringNotContainsString('Please check', DB::table('eligibility_reviews')->value('explanation'));
    }

    public function test_private_evidence_is_validated_scoped_downloaded_and_erased_with_participant(): void
    {
        $review = $this->review(['evidence' => UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\nsynthetic evidence")]);
        Storage::disk('local')->assertExists($review->file_path);
        $url = route('forms.public.status.review.evidence', [$this->forms['registration']->public_token, $review->public_id]);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('X-Content-Type-Options', 'nosniff');
        $other = $this->webinar->participants()->create(['email' => 'other@example.test']);
        $this->access($other);
        $this->get($url)->assertNotFound();
        $path = $review->file_path;
        app(ParticipantPrivacyService::class)->erase($this->participant);
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($review->fresh()->explanation);
        $this->assertNull($review->fresh()->file_path);
    }

    public function test_disguised_active_content_and_oversize_evidence_are_rejected(): void
    {
        $this->access();
        $route = route('forms.public.status.review.store', $this->forms['registration']->public_token);
        foreach ([UploadedFile::fake()->createWithContent('fake.pdf', '<?php echo "danger";'), UploadedFile::fake()->create('large.pdf', 2049, 'application/pdf')] as $file) {
            $this->post($route, ['submission_key' => (string) Str::uuid(), 'explanation' => 'Please review the attached record.', 'evidence' => $file])->assertSessionHasErrors('evidence');
        }
        $this->assertDatabaseCount('eligibility_reviews', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_revocation_invalidates_old_tokens_and_existing_status_sessions(): void
    {
        $this->access();
        $this->get(route('forms.public.status.view', $this->forms['registration']->public_token))->assertOk();
        $this->actingAs($this->admin)->post(route('admin.webinars.eligibility.revoke', [$this->webinar, $this->participant]))->assertRedirect();
        $this->get(route('forms.public.status.view', $this->forms['registration']->public_token))->assertRedirect(route('forms.public.status', $this->forms['registration']->public_token));
        $this->assertSame(1, (int) $this->participant->fresh()->status_access_version);
        $this->assertDatabaseCount('participants', 1);
    }

    public function test_finalization_requires_closed_collection_completed_imports_and_no_pending_reviews(): void
    {
        $route = route('admin.webinars.eligibility.finalize', [$this->webinar, $this->participant]);
        $body = ['reason' => 'Verified the published records and source files.', 'verification_complete' => '1'];
        $this->forms['pretest']->update(['status' => 'published']);
        $this->actingAs($this->admin)->post($route, $body)->assertConflict();
        $this->forms['pretest']->update(['status' => 'draft']);
        $issue = $this->issue();
        $this->post($route, $body)->assertConflict();
        $issue->update(['resolved_at' => now()]);
        $review = $this->review();
        $this->actingAs($this->admin)->post($route, $body)->assertConflict();
        $review->update(['status' => 'rejected', 'open_key' => null]);
        $this->post($route, $body)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('not_eligible', $this->summary()['status']);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('eligibility_overrides', 0);
    }

    public function test_new_verified_evidence_invalidates_final_decision_without_extra_certificate_approval(): void
    {
        $this->actingAs($this->admin)->post(route('admin.webinars.eligibility.finalize', [$this->webinar, $this->participant]), ['reason' => 'Records verified after all collection ended.', 'verification_complete' => '1'])->assertRedirect();
        $this->assertSame('not_eligible', $this->summary()['status']);
        $this->submit('pretest');
        $this->assertSame('incomplete', $this->summary()['status']);
        $this->submit('posttest');
        $this->assertSame('eligible', $this->summary()['status']);
        $this->assertContains($this->participant->id, app(EligibilityService::class)->eligibleParticipantIds($this->webinar->fresh()));
        $this->assertDatabaseCount('eligibility_decisions', 1);
        $this->assertDatabaseCount('certificate_approvals', 0);
    }

    public function test_review_cannot_be_approved_without_a_verified_record_change(): void
    {
        $review = $this->review();
        $route = route('admin.webinars.eligibility.decide', [$this->webinar, $this->participant, $review]);
        $this->actingAs($this->admin)->post($route, ['decision' => 'approved', 'reason' => 'Verified against the original source.', 'correction_type' => 'records_updated'])->assertSessionHasErrors('correction_type');
        $this->assertSame('pending', $review->fresh()->status);
        $this->submit('pretest');
        $this->post($route, ['decision' => 'approved', 'reason' => 'Verified the newly matched pretest record.', 'correction_type' => 'records_updated'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $review->fresh()->status);
        $this->assertSame($this->admin->id, $review->fresh()->decided_by);
        $this->assertSame('incomplete', $this->summary()['status']);
        $audit = AuditLog::query()->where('action', 'eligibility.review_approved')->firstOrFail();
        $this->assertSame($this->admin->id, (int) $audit->actor_user_id);
        $this->assertSame($this->admin->name, $audit->actor_name);
    }

    public function test_approved_import_correction_uses_existing_matching_and_preserves_original_records(): void
    {
        $this->submit('pretest');
        $issue = $this->issue();
        $review = $this->review();
        $this->actingAs($this->admin)->post(route('admin.webinars.eligibility.decide', [$this->webinar, $this->participant, $review]), [
            'decision' => 'approved', 'reason' => 'Verified original posttest export for this email.', 'correction_type' => 'existing_import', 'import_issue_id' => $issue->id,
        ])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('approved', $review->fresh()->status);
        $this->assertNotNull($issue->fresh()->resolved_at);
        $this->assertSame('eligible', $this->summary()['status']);
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('submissions', 2);
        $this->assertDatabaseCount('eligibility_overrides', 0);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->actingAs($this->admin)->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertSee('Participant One');
    }

    public function test_cross_participant_and_cross_webinar_review_decisions_are_rejected(): void
    {
        $review = $this->review();
        $other = $this->webinar->participants()->create(['email' => 'other@example.test']);
        $this->actingAs($this->admin)->post(route('admin.webinars.eligibility.decide', [$this->webinar, $other, $review]), ['decision' => 'rejected', 'reason' => 'Verified request belongs to someone else.'])->assertNotFound();
        $this->assertSame('pending', $review->fresh()->status);
        $issue = $this->issue(email: 'other@example.test');
        $this->post(route('admin.webinars.eligibility.decide', [$this->webinar, $this->participant, $review]), ['decision' => 'approved', 'reason' => 'Attempting wrong identity record.', 'correction_type' => 'existing_import', 'import_issue_id' => $issue->id])->assertNotFound();
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_notification_selection_is_confirmed_queued_idempotent_and_never_sends_a_certificate(): void
    {
        $key = (string) Str::uuid();
        $this->notify(['confirmed' => '0'], $key)->assertSessionHasErrors('confirmed');
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->notify(key: $key)->assertSessionHasNoErrors();
        $this->notify(key: $key)->assertSessionHasNoErrors();
        $this->notify()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('email_deliveries', 1);
        $delivery = EmailDelivery::query()->firstOrFail();
        $this->assertSame('eligibility_notification', $delivery->type);
        $this->assertSame('pending', $delivery->status);
        $this->assertNull($delivery->certificate_id);
        $this->assertSame([], $delivery->payload['attachments']);
        $this->assertStringContainsString('Incomplete / Under Review', $delivery->payload['html']);
        $this->assertStringNotContainsString('Not Eligible', $delivery->payload['html']);
        $this->assertStringContainsString('#token=', $delivery->payload['html']);
        $this->assertSame($this->admin->id, (int) $delivery->initiated_by);
        Queue::assertPushed(SendTransactionalEmail::class, 1);
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_stale_eligibility_notification_and_revoked_links_cancel_before_transport(): void
    {
        $this->notify()->assertSessionHasNoErrors();
        $delivery = EmailDelivery::query()->firstOrFail();
        $this->submit('pretest');
        $mailer = \Mockery::mock(TransactionalMailer::class)->shouldNotReceive('send')->getMock();
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->payload);
    }

    public function test_notification_failure_and_intentional_retry_preserve_history(): void
    {
        $this->notify();
        $original = EmailDelivery::query()->firstOrFail();
        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new \RuntimeException('Connection unavailable'));
        try {
            (new SendTransactionalEmail($original->id))->handle($mailer);
        } catch (\RuntimeException) {
        }
        $original->refresh();
        $this->assertSame(1, (int) $original->attempts);
        $this->assertStringStartsWith('Provider outcome unknown', $original->last_error);
        $original->update(['status' => 'failed']);
        $this->travel(3)->minutes();
        $this->notify(['resend' => '1'])->assertSessionHasErrors('provider_reconciled');
        $this->assertDatabaseCount('email_deliveries', 1);
        $this->notify(['resend' => '1', 'provider_reconciled' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('email_deliveries', 2);
        $this->assertSame('failed', $original->fresh()->status);
        $this->assertDatabaseHas('audit_logs', ['action' => 'eligibility.notifications_queued', 'actor_user_id' => $this->admin->id]);
    }

    public function test_sent_certificate_recipients_do_not_receive_extra_eligibility_mail(): void
    {
        $this->submit('pretest');
        $this->submit('posttest');
        $template = $this->webinar->certificateTemplates()->create(['name' => 'Synthetic template', 'is_active' => true, 'layout' => []]);
        Certificate::query()->create(['webinar_id' => $this->webinar->id, 'participant_id' => $this->participant->id, 'certificate_template_id' => $template->id,
            'verification_code' => 'TEST-CERTIFICATE-001', 'status' => 'issued', 'issued_at' => now()]);
        $this->notify()->assertSessionHasNoErrors();
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_assigned_permissions_enforce_management_and_sending_separately(): void
    {
        $manager = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $manager->assignedWebinars()->attach($this->webinar, ['assigned_by' => $this->admin->id, 'manage_participants' => true, 'send_emails' => false]);
        $this->actingAs($manager)->get(route('admin.webinars.eligibility.index', $this->webinar))->assertOk()->assertDontSee('Queue eligibility notifications');
        $this->post(route('admin.webinars.eligibility.notify', $this->webinar), ['submission_key' => (string) Str::uuid(), 'confirmed' => '1', 'participants' => [$this->participant->public_id]])->assertForbidden();
        $manager->assignedWebinars()->updateExistingPivot($this->webinar, ['manage_participants' => false, 'send_emails' => true]);
        $this->get(route('admin.webinars.eligibility.index', $this->webinar))->assertForbidden();
        $manager->assignedWebinars()->updateExistingPivot($this->webinar, ['manage_certificates' => true]);
        $this->get(route('admin.certificates.studio', $this->webinar))->assertOk()->assertDontSee($this->participant->full_name)
            ->assertDontSee('data-recipient-tab="incomplete"', false);
        $unassigned = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $this->actingAs($unassigned)->get(route('admin.webinars.eligibility.show', [$this->webinar, $this->participant]))->assertNotFound();
    }

    public function test_studio_has_a_separate_non_selectable_list_and_filters_show_correct_statuses(): void
    {
        $this->actingAs($this->admin)->get(route('admin.certificates.studio', $this->webinar))->assertOk()
            ->assertSee('data-tab-panel="incomplete"', false)->assertViewHas('notCurrentlyEligibleCount', 1);
        $this->get(route('admin.webinars.eligibility.index', [$this->webinar, 'status' => 'incomplete']))->assertOk()->assertSee('Participant One');
        $this->get(route('admin.webinars.eligibility.index', [$this->webinar, 'status' => 'eligible']))->assertOk()->assertDontSee('Participant One');
        $this->get(route('admin.webinars.eligibility.show', [$this->webinar, $this->participant]))->assertOk()->assertSee('Not generated');
    }

    public function test_final_notifications_use_the_final_reason_and_return_to_review_after_new_import(): void
    {
        $reason = 'Source exports checked; the required post assessment was not satisfied.';
        $this->actingAs($this->admin)->post(route('admin.webinars.eligibility.finalize', [$this->webinar, $this->participant]), ['reason' => $reason, 'verification_complete' => '1'])->assertRedirect();
        $this->notify()->assertSessionHasNoErrors();
        $delivery = EmailDelivery::query()->firstOrFail();
        $this->assertStringContainsString('Not Eligible', $delivery->payload['html']);
        $this->assertStringContainsString($reason, $delivery->payload['html']);
        $this->issue();
        $this->assertSame('incomplete', $this->summary()['status']);
        $mailer = \Mockery::mock(TransactionalMailer::class)->shouldNotReceive('send')->getMock();
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('cancelled', $delivery->fresh()->status);
    }

    public function test_successful_notification_uses_the_existing_delivery_log_without_claiming_inbox_receipt(): void
    {
        $this->notify();
        $delivery = EmailDelivery::query()->firstOrFail();
        $mailer = \Mockery::mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->withArgs(fn ($recipient, $subject, $html, $attachments, $key) => $recipient === $this->participant->email && $attachments === [] && $key === $delivery->idempotency_key)->andReturn('synthetic-provider-message');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $delivery->refresh();
        $this->assertSame('sent', $delivery->status);
        $this->assertNull($delivery->payload);
        $this->assertSame('Captured locally', $delivery->statusLabel());
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertDatabaseHas('audit_logs', ['action' => 'email.transport_accepted', 'actor_user_id' => $this->admin->id]);
    }

    public function test_expired_notifications_and_revoked_sender_permissions_stop_before_transport(): void
    {
        $this->notify();
        $delivery = EmailDelivery::query()->firstOrFail();
        $delivery->update(['expires_at' => now()->subSecond()]);
        $mailer = \Mockery::mock(TransactionalMailer::class)->shouldNotReceive('send')->getMock();
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->travel(3)->minutes();
        $this->notify(['resend' => '1']);
        $next = EmailDelivery::query()->latest('id')->firstOrFail();
        $this->admin->update(['is_active' => false]);
        (new SendTransactionalEmail($next->id))->handle($mailer);
        $this->assertSame('cancelled', $next->fresh()->status);
    }

    public function test_bulk_notifications_are_bounded_webinar_scoped_and_idempotent(): void
    {
        $second = $this->webinar->participants()->create(['email' => 'second@example.test', 'full_name' => 'Second Participant']);
        $key = (string) Str::uuid();
        $selection = ['participants' => [$this->participant->public_id, $second->public_id]];
        $this->notify($selection, $key)->assertSessionHasNoErrors();
        $this->notify($selection, $key)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('email_deliveries', 2);
        $otherWebinar = Webinar::factory()->create();
        $foreign = $otherWebinar->participants()->create(['email' => 'foreign@example.test']);
        $this->notify(['participants' => [$foreign->public_id]])->assertUnprocessable();
        $this->assertDatabaseCount('email_deliveries', 2);
        $this->notify(['participants' => [$this->participant->public_id]], $key)->assertConflict();
        $this->assertDatabaseCount('email_deliveries', 2);
    }

    public function test_evidence_storage_failure_keeps_the_review_and_a_private_cleanup_inventory(): void
    {
        $this->access();
        $adapter = \Mockery::mock(FilesystemAdapter::class);
        $adapter->shouldReceive('putFileAs')->once()->andReturn(false);
        Storage::shouldReceive('disk')->with('local')->andReturn($adapter);
        $this->post(route('forms.public.status.review.store', $this->forms['registration']->public_token), [
            'submission_key' => (string) Str::uuid(), 'explanation' => 'Please check my original source records.',
            'evidence' => UploadedFile::fake()->createWithContent('evidence.pdf', "%PDF-1.4\nsynthetic evidence"),
        ])->assertRedirect()->assertSessionHas('success', fn ($value) => str_contains($value, 'supporting file could not be confirmed'));
        $review = EligibilityReview::query()->firstOrFail();
        $this->assertSame('failed', $review->evidence_status);
        $this->assertNotNull($review->file_path);
        $this->assertSame('pending', $review->status);
        $this->get(route('forms.public.status.review.evidence', [$this->forms['registration']->public_token, $review->public_id]))->assertNotFound();
    }

    public function test_webinar_switcher_checks_assignment_and_retains_existing_webinar_access_gate(): void
    {
        $this->actingAs($this->admin)->get(route('admin.eligibility.select', ['webinar' => $this->webinar->id]))->assertRedirect(route('admin.webinars.eligibility.index', $this->webinar));
        $other = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $this->actingAs($other)->get(route('admin.eligibility.select', ['webinar' => $this->webinar->id]))->assertNotFound();
    }

    public function test_explicit_overrides_do_not_hide_or_fabricate_the_activity_evidence(): void
    {
        $this->submit('pretest');
        $this->participant->eligibilityOverrides()->create(['decision' => 'eligible', 'reason' => 'Existing verified organizer exception.', 'overridden_by' => $this->admin->id]);
        $summary = $this->summary();
        $this->assertSame('eligible', $summary['status']);
        $this->assertTrue(collect($summary['activities'])->firstWhere('type', 'pretest')['met']);
        $this->assertFalse(collect($summary['activities'])->firstWhere('type', 'posttest')['met']);
        $this->assertSame('Not Found', collect($summary['activities'])->firstWhere('type', 'posttest')['state']);
        $this->assertStringContainsString('explicit eligibility decision', $summary['explanation']);
    }

    public function test_private_search_is_session_scoped_and_does_not_enter_pagination_urls(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.webinars.eligibility.filter', $this->webinar), ['search' => $this->participant->email, 'status' => 'incomplete']);
        $response->assertRedirect(route('admin.webinars.eligibility.index', $this->webinar));
        $this->assertStringNotContainsString($this->participant->email, $response->headers->get('Location'));
        $this->get(route('admin.webinars.eligibility.index', $this->webinar))->assertOk()->assertViewHas('participants', fn ($rows) => $rows->total() === 1 && ! str_contains($rows->url(2), $this->participant->email));
    }
}
