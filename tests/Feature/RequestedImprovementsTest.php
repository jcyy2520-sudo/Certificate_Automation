<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarDocument;
use App\Services\Email\PermanentDeliveryException;
use App\Services\Email\ProviderRateLimitException;
use App\Services\PendingSensitiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class RequestedImprovementsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        config(['webinar.document_disk' => 'local', 'accounts.email_per_minute' => 1000, 'accounts.email_per_day' => 0]);
        $this->owner = User::factory()->create(['role' => 'user']);
        $this->webinar = Webinar::query()->create(['title' => 'Synthetic event', 'slug' => 'synthetic', 'created_by' => $this->owner->id, 'status' => 'published', 'ends_at' => now()->addDay(), 'data_retention_days' => 30]);
        $this->actingAs($this->owner);
    }

    private function participant(array $data = []): Participant
    {
        return $this->webinar->participants()->create($data + ['full_name' => 'Synthetic Person', 'email' => Str::uuid().'@example.test', 'verified_at' => now()]);
    }

    private function message(array $participants, array $extra = []): array
    {
        return $extra + ['submission_key' => (string) Str::uuid(), 'participants' => array_map(fn ($p) => $p->public_id, $participants), 'subject' => 'Certificate update', 'body' => "We are waiting for approval.\nThank you. <script>unsafe()</script>"];
    }

    private function queueMessage(?Participant $participant = null): EmailDelivery
    {
        $this->post(route('admin.webinars.messages.store', $this->webinar), $this->message([$participant ?? $this->participant()]))->assertSessionHasNoErrors()->assertRedirect();

        return EmailDelivery::query()->latest('id')->firstOrFail();
    }

    public function test_single_and_bulk_messages_are_independent_encrypted_and_idempotent(): void
    {
        $data = $this->message([$this->participant(), $this->participant()]);
        $url = route('admin.webinars.messages.store', $this->webinar);
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('email_deliveries', 2);
        $this->assertDatabaseCount('participant_message_batches', 1);
        $this->assertDatabaseCount('certificates', 0);
        $delivery = EmailDelivery::first();
        $this->assertNull($delivery->certificate_id);
        $this->assertSame([], $delivery->payload['attachments']);
        $this->assertStringContainsString('&lt;script&gt;', $delivery->payload['html']);
        $this->assertStringNotContainsString('Certificate update', DB::table('email_deliveries')->value('subject'));
        $this->post($url, array_replace($data, ['body' => 'Changed']))->assertStatus(409);
        $this->get(route('admin.webinars.email-logs.index', $this->webinar))->assertOk()->assertSee('Message only');
        $this->get(route('admin.webinars.messages.index', $this->webinar))->assertOk()->assertSee('data-message-count', false);
    }

    public function test_invalid_ineligible_erased_cross_webinar_and_duplicate_recipients_are_rejected_atomically(): void
    {
        $p = $this->participant();
        $ineligible = $this->participant();
        $ineligible->eligibilityOverrides()->create(['decision' => 'ineligible', 'reason' => 'Synthetic', 'overridden_by' => $this->owner->id]);
        $erased = $this->participant(['privacy_erased_at' => now()]);
        $other = Webinar::query()->create(['title' => 'Other', 'slug' => 'other', 'created_by' => $this->owner->id]);
        $foreign = $other->participants()->create(['full_name' => 'Other', 'email' => 'other@example.test']);
        foreach ([[$p, $ineligible], [$erased], [$foreign], [$p, $p]] as $roster) {
            $this->post(route('admin.webinars.messages.store', $this->webinar), $this->message($roster))->assertSessionHasErrors();
        }
        $this->post(route('admin.webinars.messages.store', $this->webinar), $this->message([$p], ['subject' => "Bad\r\nBcc: x@example.test"]))->assertSessionHasErrors('subject');
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseCount('participant_message_batches', 0);
    }

    public function test_send_job_rechecks_eligibility_and_mailbox_and_actor_and_lifecycle(): void
    {
        foreach (['eligibility', 'mailbox', 'actor', 'archive', 'relationship'] as $change) {
            $p = $this->participant();
            $delivery = $this->queueMessage($p);
            if ($change === 'eligibility') {
                $p->eligibilityOverrides()->create(['decision' => 'ineligible', 'reason' => 'Changed', 'overridden_by' => $this->owner->id]);
            }
            if ($change === 'mailbox') {
                $p->update(['email' => 'changed@example.test']);
            }
            if ($change === 'actor') {
                $this->owner->update(['is_active' => false]);
            }
            if ($change === 'archive') {
                $this->webinar->update(['archived_at' => now()]);
            }
            if ($change === 'relationship') {
                DB::table('participants')->where('id', $p->id)->update(['webinar_id' => Webinar::query()->create(['title' => 'Other', 'slug' => Str::uuid(), 'created_by' => $this->owner->id])->id]);
            }
            $mailer = $this->mock(TransactionalMailer::class);
            $mailer->shouldNotReceive('send');
            (new SendTransactionalEmail($delivery->id))->handle($mailer);
            $this->assertSame('cancelled', $delivery->fresh()->status, $change);
            $this->owner->update(['is_active' => true]);
            $this->webinar->update(['archived_at' => null]);
        }
    }

    public function test_provider_failure_retry_and_repeated_delivery_use_the_existing_delivery_envelope(): void
    {
        $delivery = $this->queueMessage();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new ProviderRateLimitException(2));
        $job = new SendTransactionalEmail($delivery->id);
        $job->handle($mailer);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->assertNotNull($delivery->fresh()->last_error);
        $key = $delivery->idempotency_key;
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->withArgs(fn ($to, $subject, $html, $attachments, $idempotency) => $attachments === [] && $idempotency === $key)->andReturn('synthetic-message-id');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('sent', $delivery->fresh()->status);
        $this->assertSame(2, $delivery->fresh()->attempts);
        $this->assertNull($delivery->fresh()->payload);
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_permanent_failure_and_explicit_retry_are_logged_once_and_unknown_outcome_needs_reconciliation(): void
    {
        $delivery = $this->queueMessage();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->andThrow(new PermanentDeliveryException('Rejected'));
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('failed', $delivery->fresh()->status);
        $delivery->update(['last_error' => 'Provider outcome unknown: test']);
        $url = route('admin.webinars.messages.retry', [$this->webinar, $delivery->public_id]);
        $this->post($url)->assertSessionHasErrors('provider_reconciled');
        $this->post($url, ['provider_reconciled' => '1'])->assertSessionHasNoErrors();
        $this->post($url, ['provider_reconciled' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('email_deliveries', 2);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertDatabaseHas('email_deliveries', ['retry_of_delivery_id' => $delivery->id, 'status' => 'pending']);
    }

    public function test_creation_modes_preserve_standard_defaults_and_upload_mode_requires_only_title(): void
    {
        $this->post(route('admin.webinars.store'), ['title' => 'Standard', 'data_retention_days' => 30])->assertSessionHasNoErrors();
        $standard = Webinar::where('title', 'Standard')->firstOrFail();
        $this->assertCount(4, $standard->forms);
        $this->assertCount(3, $standard->eligibilityRules);
        $this->assertCount(1, $standard->certificateTemplates);
        $this->post(route('admin.webinars.store'), ['title' => 'Documents', 'creation_mode' => 'uploads', 'is_open' => '1', 'requires_verification' => '0'])->assertSessionHasNoErrors();
        $upload = Webinar::where('title', 'Documents')->firstOrFail();
        $this->assertTrue($upload->isUploadOnly());
        $this->assertSame('draft', $upload->status);
        $this->assertNull($upload->management_password);
        $this->assertCount(0, $upload->forms);
        $this->assertCount(0, $upload->certificateTemplates);
        $this->assertCount(0, $upload->eligibilityRules);
        $this->get(route('admin.webinars.show', $upload))->assertOk()->assertSee('No documents yet');
        $this->get(route('admin.certification.edit', $upload))->assertNotFound();
        $this->put(route('admin.webinars.update', $upload), ['title' => 'Renamed', 'creation_mode' => 'standard'])->assertSessionHasNoErrors();
        $this->assertTrue($upload->fresh()->isUploadOnly());
        $this->put(route('admin.webinars.update', $standard), ['title' => 'Standard edited', 'data_retention_days' => 30, 'creation_mode' => 'uploads'])->assertSessionHasNoErrors();
        $this->assertFalse($standard->fresh()->isUploadOnly());
    }

    public function test_documents_can_be_added_downloaded_replaced_and_removed_without_losing_previous_files(): void
    {
        $url = route('admin.webinars.documents.store', $this->webinar);
        $this->post($url, ['title' => 'Agenda', 'file' => UploadedFile::fake()->createWithContent('agenda.pdf', "%PDF-1.4\nSynthetic PDF")])->assertSessionHasNoErrors();
        $doc = WebinarDocument::firstOrFail();
        Storage::disk('local')->assertExists($doc->file_path);
        $this->get(route('admin.webinars.documents.download', [$this->webinar, $doc]))->assertOk()->assertHeader('content-type', 'application/octet-stream')->assertHeader('x-content-type-options', 'nosniff');
        $this->put(route('admin.webinars.documents.update', [$this->webinar, $doc]), ['title' => 'New', 'version' => 1, 'file' => UploadedFile::fake()->createWithContent('new.pdf', "%PDF-1.4\nNew PDF")])->assertSessionHasNoErrors();
        $new = WebinarDocument::latest('id')->firstOrFail();
        $this->assertSame(2, $new->version);
        $this->assertSoftDeleted($doc);
        Storage::disk('local')->assertExists($doc->file_path);
        $this->get(route('admin.webinars.documents.download', [$this->webinar, $doc]))->assertNotFound();
        $this->delete(route('admin.webinars.documents.destroy', [$this->webinar, $new]))->assertSessionHasNoErrors();
        $this->assertSoftDeleted($new);
        Storage::disk('local')->assertExists($new->file_path);
    }

    public function test_upload_validation_rejects_scripts_spoofed_extensions_oversized_and_macro_documents(): void
    {
        $url = route('admin.webinars.documents.store', $this->webinar);
        foreach (['attack.php' => '<?php phpinfo();', 'spoof.pdf' => '<script>alert(1)</script>', 'bad.docx' => 'not a zip', 'image.svg' => '<svg/>'] as $name => $body) {
            $this->post($url, ['title' => 'Invalid', 'file' => UploadedFile::fake()->createWithContent($name, $body)])->assertSessionHasErrors('file');
        }
        $this->post($url, ['title' => 'Too big', 'file' => UploadedFile::fake()->create('huge.pdf', 8193, 'application/pdf')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('webinar_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_other_users_cannot_access_foreign_webinars_messages_uploads_downloads_or_retries(): void
    {
        $delivery = $this->queueMessage();
        $doc = $this->webinar->documents()->create(['title' => 'Private', 'storage_disk' => 'local', 'file_path' => 'private', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size' => 10]);
        $stranger = User::factory()->create(['role' => 'user']);
        $this->actingAs($stranger);
        foreach (['admin.webinars.show', 'admin.webinars.documents.index', 'admin.webinars.messages.index'] as $route) {
            $this->get(route($route, $this->webinar))->assertNotFound();
        }
        $this->post(route('admin.webinars.messages.store', $this->webinar), [])->assertNotFound();
        $this->post(route('admin.webinars.messages.retry', [$this->webinar, $delivery->public_id]))->assertNotFound();
        $this->get(route('admin.webinars.documents.download', [$this->webinar, $doc]))->assertNotFound();
        $this->delete(route('admin.webinars.documents.destroy', [$this->webinar, $doc]))->assertNotFound();
        $this->post(route('admin.webinars.documents.store', $this->webinar), [])->assertNotFound();
        $this->get(route('admin.webinars.index'))->assertDontSee('Synthetic event');
        $admin = User::factory()->create();
        $this->actingAs($admin)->withSession(['administrative_webinars.'.$this->webinar->id => now()->addHour()->timestamp])->get(route('admin.webinars.documents.index', $this->webinar))->assertOk();
    }

    public function test_document_binding_cannot_cross_webinars(): void
    {
        $other = Webinar::query()->create(['title' => 'Other', 'slug' => 'other', 'created_by' => $this->owner->id]);
        $doc = $other->documents()->create(['title' => 'Other file', 'storage_disk' => 'local', 'file_path' => 'other', 'extension' => 'pdf', 'mime_type' => 'application/pdf', 'size' => 10]);
        $this->get(route('admin.webinars.documents.download', [$this->webinar, $doc]))->assertNotFound();
        $this->delete(route('admin.webinars.documents.destroy', [$this->webinar, $doc]))->assertNotFound();
        $this->assertNotSoftDeleted($doc);
    }

    public function test_docx_structure_and_private_storage_are_enforced(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'synthetic-docx-');
        try {
            $zip = new \ZipArchive;
            $zip->open($path, \ZipArchive::OVERWRITE);
            $zip->addFromString('[Content_Types].xml', '<Types/>');
            $zip->addFromString('word/document.xml', '<document>Synthetic</document>');
            $zip->addFromString('word/vbaProject.bin', 'Synthetic forbidden macro');
            $zip->close();
            $this->post(route('admin.webinars.documents.store', $this->webinar), ['title' => 'Macro', 'file' => UploadedFile::fake()->createWithContent('macro.docx', file_get_contents($path))])->assertSessionHasErrors('file');
            $zip->open($path);
            $zip->deleteName('word/vbaProject.bin');
            $zip->close();
            $this->post(route('admin.webinars.documents.store', $this->webinar), ['title' => 'Plain', 'file' => UploadedFile::fake()->createWithContent('plain.docx', file_get_contents($path))])->assertSessionHasNoErrors();
            $this->assertDatabaseCount('webinar_documents', 1);
            config(['webinar.document_disk' => 'public']);
            $this->post(route('admin.webinars.documents.store', $this->webinar), ['title' => 'Unsafe storage', 'file' => UploadedFile::fake()->createWithContent('file.pdf', "%PDF-1.4\nSynthetic")])->assertStatus(503);
            $this->assertDatabaseCount('webinar_documents', 1);
        } finally {
            unlink($path);
        }
    }

    public function test_pending_accounts_cannot_access_features_and_passwords_never_enter_step_up_replay(): void
    {
        $pending = User::factory()->create(['role' => 'user', 'approval_status' => 'pending_approval']);
        $this->actingAs($pending)->get(route('admin.webinars.index'))->assertRedirect(route('login'));
        $this->actingAs($this->owner);
        config(['security.require_sensitive_action_password_confirmation' => true]);
        $this->put(route('admin.profile.password'), ['current_password' => 'private-current-fixture', 'password' => 'private-new-fixture-password', 'password_confirmation' => 'private-new-fixture-password'])->assertRedirect(route('admin.password.confirm'));
        $this->assertFalse(session()->has(PendingSensitiveAction::SESSION_KEY));
        $this->assertStringNotContainsString('private-new-fixture-password', json_encode(session()->all()));
    }

    public function test_password_change_invalidates_existing_sessions_and_reset_links(): void
    {
        $this->owner->update(['password' => 'Existing-password-fixture!']);
        $oldHash = $this->owner->fresh()->password;
        $token = Password::createToken($this->owner);
        $this->put(route('admin.profile.password'), ['current_password' => 'Existing-password-fixture!', 'password' => 'New-password-fixture-2026!', 'password_confirmation' => 'New-password-fixture-2026!'])->assertRedirect(route('login'));
        $this->assertFalse(Password::tokenExists($this->owner->fresh(), $token));
        $this->actingAs($this->owner->fresh())->withSession(['password_hash_web' => $oldHash])->get(route('admin.profile'))->assertRedirect(route('login'));
    }

    public function test_password_change_requires_current_password_confirmation_and_strength_and_signs_out(): void
    {
        config(['security.require_sensitive_action_password_confirmation' => true]);
        $this->withSession(['auth.password_confirmed_at' => now()->timestamp]);
        $this->owner->update(['password' => 'Existing-password-fixture!']);
        $url = route('admin.profile.password');
        $this->put($url, ['current_password' => 'wrong', 'password' => 'New-password-fixture-2026!', 'password_confirmation' => 'New-password-fixture-2026!'])->assertSessionHasErrors('current_password');
        $this->put($url, ['current_password' => 'Existing-password-fixture!', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->put($url, ['current_password' => 'Existing-password-fixture!', 'password' => 'New-password-fixture-2026!', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');
        $mfa = now();
        $this->owner->update(['two_factor_confirmed_at' => $mfa]);
        $this->put($url, ['current_password' => 'Existing-password-fixture!', 'password' => 'New-password-fixture-2026!', 'password_confirmation' => 'New-password-fixture-2026!'])->assertRedirect(route('login'));
        $this->assertGuest();
        $this->assertTrue(Hash::check('New-password-fixture-2026!', $this->owner->fresh()->password));
        $this->assertFalse(Hash::check('Existing-password-fixture!', $this->owner->fresh()->password));
        $this->assertSame(2, $this->owner->fresh()->auth_version);
        $this->assertEquals($mfa->timestamp, $this->owner->fresh()->two_factor_confirmed_at->timestamp);
    }
}
