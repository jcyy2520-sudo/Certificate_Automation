<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\EligibilityOverride;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SensitiveDataEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_queryable_participant_and_operational_fields_are_encrypted_at_rest(): void
    {
        $administrator = User::factory()->create();
        $webinar = Webinar::query()->create([
            'title' => 'Private Event',
            'slug' => 'private-event',
            'created_by' => $administrator->id,
        ]);
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Registration']);
        $field = $form->fields()->create(['key' => 'private_note', 'label' => 'Private note', 'field_type' => 'text']);
        $participant = Participant::query()->create([
            'webinar_id' => $webinar->id,
            'full_name' => 'Private Person',
            'email' => 'private@example.com',
        ]);
        $submission = Submission::query()->create([
            'form_id' => $form->id,
            'participant_id' => $participant->id,
        ]);
        $answer = SubmissionAnswer::query()->create([
            'submission_id' => $submission->id,
            'form_field_id' => $field->id,
            'value' => ['Highly sensitive response'],
        ]);
        $template = $webinar->certificateTemplates()->create([
            'name' => 'Private template',
            'storage_disk' => 'local',
            'layout' => [],
        ]);
        $certificate = Certificate::query()->create([
            'verification_code' => 'CERT-PRIVATE-ENCRYPTED',
            'webinar_id' => $webinar->id,
            'participant_id' => $participant->id,
            'certificate_template_id' => $template->id,
            'recipient_name' => 'Private Person',
            'revocation_reason' => 'Sensitive revocation detail',
            'storage_disk' => 'local',
        ]);
        $delivery = EmailDelivery::query()->create([
            'webinar_id' => $webinar->id,
            'participant_id' => $participant->id,
            'certificate_id' => $certificate->id,
            'type' => 'certificate',
            'provider' => 'log',
            'recipient_email' => 'private@example.com',
            'subject' => 'Private delivery',
            'payload' => ['html' => 'Highly private email body'],
            'last_error' => 'Private provider diagnostic',
        ]);
        $override = EligibilityOverride::query()->create([
            'participant_id' => $participant->id,
            'decision' => 'ineligible',
            'reason' => 'Sensitive eligibility reason',
            'overridden_by' => $administrator->id,
        ]);

        $this->assertSame(['Highly sensitive response'], $answer->fresh()->value);
        $this->assertSame('Private Person', $certificate->fresh()->recipient_name);
        $this->assertSame('Sensitive revocation detail', $certificate->fresh()->revocation_reason);
        $this->assertSame('private@example.com', $delivery->fresh()->recipient_email);
        $this->assertSame(['html' => 'Highly private email body'], $delivery->fresh()->payload);
        $this->assertSame('Private provider diagnostic', $delivery->fresh()->last_error);
        $this->assertSame('Sensitive eligibility reason', $override->fresh()->reason);

        $raw = implode('|', [
            DB::table('submission_answers')->where('id', $answer->id)->value('value'),
            DB::table('certificates')->where('id', $certificate->id)->value('recipient_name'),
            DB::table('certificates')->where('id', $certificate->id)->value('revocation_reason'),
            DB::table('email_deliveries')->where('id', $delivery->id)->value('recipient_email'),
            DB::table('email_deliveries')->where('id', $delivery->id)->value('payload'),
            DB::table('email_deliveries')->where('id', $delivery->id)->value('last_error'),
            DB::table('eligibility_overrides')->where('id', $override->id)->value('reason'),
        ]);

        foreach (['sensitive', 'private person', 'private@example.com', 'provider diagnostic'] as $plaintext) {
            $this->assertStringNotContainsString($plaintext, strtolower($raw));
        }
    }
}
