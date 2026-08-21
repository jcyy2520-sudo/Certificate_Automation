<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\EligibilityOverride;
use App\Models\EmailDelivery;
use App\Models\Form;
use App\Models\FormField;
use App\Models\Participant;
use App\Models\ParticipantAccessToken;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrivacyErasureTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_personal_data_is_erased_and_public_certificate_details_are_hidden(): void
    {
        Storage::fake('local');

        $administrator = User::factory()->create();
        $webinar = Webinar::query()->create([
            'title' => 'Completed Event', 'slug' => 'completed-event', 'created_by' => $administrator->id,
            'status' => 'completed', 'ends_at' => now()->subDays(10), 'data_retention_days' => 7,
        ]);
        $participant = Participant::query()->create([
            'webinar_id' => $webinar->id, 'full_name' => 'Private Person',
            'email' => 'private@example.com', 'organization' => 'Example Org', 'verified_at' => now()->subDays(10),
        ]);
        ParticipantAccessToken::query()->create([
            'participant_id' => $participant->id, 'token_hash' => hash('sha256', 'secret'), 'expires_at' => now()->addHour(),
        ]);
        $form = Form::query()->create(['webinar_id' => $webinar->id, 'type' => 'evaluation', 'title' => 'Evaluation']);
        $field = FormField::query()->create(['form_id' => $form->id, 'key' => 'comments', 'label' => 'Comments', 'field_type' => 'textarea']);
        $submission = Submission::query()->create([
            'form_id' => $form->id, 'participant_id' => $participant->id,
            'status' => 'submitted', 'score' => 5, 'maximum_score' => 5, 'submitted_at' => now()->subDays(9),
        ]);
        SubmissionAnswer::query()->create(['submission_id' => $submission->id, 'form_field_id' => $field->id, 'value' => ['Great event']]);
        $template = CertificateTemplate::query()->create([
            'webinar_id' => $webinar->id, 'name' => 'Default', 'template_path' => 'templates/default.png', 'layout' => [],
        ]);
        $issuedAt = now()->subDays(9);
        $certificate = Certificate::query()->create([
            'verification_code' => 'CERT-PRIVACY-001', 'webinar_id' => $webinar->id,
            'participant_id' => $participant->id, 'certificate_template_id' => $template->id,
            'recipient_name' => $participant->full_name, 'storage_disk' => 'local',
            'status' => 'issued', 'issued_at' => $issuedAt,
            'revocation_reason' => 'The private participant record did not match.',
        ]);
        $certificatePath = 'certificates/'.$certificate->public_id.'.pdf';
        Storage::disk('local')->put($certificatePath, 'PRIVATE PDF FOR Private Person');
        $certificate->update(['file_path' => $certificatePath]);

        EmailDelivery::query()->create([
            'webinar_id' => $webinar->id, 'participant_id' => $participant->id, 'certificate_id' => $certificate->id,
            'type' => 'certificate', 'recipient_email' => $participant->email, 'subject' => 'Certificate',
            'payload' => ['html' => 'Hello Private Person'], 'provider_message_id' => 'provider-private-id',
            'last_error' => 'Mailbox private@example.com rejected the message.',
        ]);
        EligibilityOverride::query()->create([
            'participant_id' => $participant->id, 'decision' => 'eligible',
            'reason' => 'Private Person attended manually.', 'overridden_by' => $administrator->id,
        ]);
        $legacyAudit = AuditLog::query()->create([
            'action' => 'legacy.participant_action', 'auditable_type' => Participant::class,
            'auditable_id' => $participant->id, 'metadata' => ['email' => 'private@example.com'],
        ]);

        $this->artisan('privacy:erase-expired-participants')->assertSuccessful();

        $participant->refresh();
        $this->assertNull($participant->full_name);
        $this->assertNull($participant->email);
        $this->assertNotNull($participant->privacy_erased_at);
        $this->assertDatabaseMissing('submission_answers', ['submission_id' => $submission->id]);
        $this->assertDatabaseCount('participant_access_tokens', 0);
        $this->assertNull($certificate->fresh()->recipient_name);
        $this->assertNull($certificate->fresh()->participant_id);
        $this->assertNull($certificate->fresh()->file_path);
        $this->assertNull($certificate->fresh()->revocation_reason);
        $this->assertSame('CERT-PRIVACY-001', $certificate->fresh()->verification_code);
        Storage::disk('local')->assertMissing($certificatePath);

        $delivery = EmailDelivery::query()->firstOrFail();
        $this->assertNull($delivery->recipient_email);
        $this->assertNull($delivery->payload);
        $this->assertNull($delivery->provider_message_id);
        $this->assertNull($delivery->last_error);
        $this->assertDatabaseCount('eligibility_overrides', 0);
        $this->assertNull($legacyAudit->fresh()->auditable_type);
        $this->assertNull($legacyAudit->fresh()->auditable_id);
        $this->assertNull($legacyAudit->fresh()->metadata);

        $this->get(route('certificates.verify', 'CERT-PRIVACY-001'))
            ->assertOk()
            ->assertSee('Certificate record unavailable')
            ->assertDontSee('Completed Event')
            ->assertDontSee('CERT-PRIVACY-001')
            ->assertDontSee($issuedAt->toFormattedDateString())
            ->assertDontSee('private@example.com');
    }

    public function test_expired_audit_identifiers_are_purged_separately(): void
    {
        $old = AuditLog::query()->create(['action' => 'old.security.event', 'created_at' => now()->subDays(91)]);
        $recent = AuditLog::query()->create(['action' => 'recent.security.event', 'created_at' => now()->subDays(89)]);

        $this->artisan('security:prune-audit-logs', ['--days' => 90])->assertSuccessful();

        $this->assertDatabaseMissing('audit_logs', ['id' => $old->id]);
        $this->assertDatabaseHas('audit_logs', ['id' => $recent->id]);
    }
}
