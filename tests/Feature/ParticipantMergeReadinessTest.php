<?php

namespace Tests\Feature;

use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantMergeReadinessTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();

        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($owner);
        $this->webinar = Webinar::query()->create([
            'title' => 'Merge readiness', 'slug' => 'merge-readiness',
            'status' => 'published', 'timezone' => 'UTC', 'created_by' => $owner->id,
            'ends_at' => now()->addDay(),
        ]);
        foreach (['registration', 'attendance'] as $requirement) {
            $this->webinar->eligibilityRules()->create(['requirement' => $requirement, 'is_required' => true]);
        }
    }

    public function test_reviewed_merge_preserves_secondary_registration_and_attendance_without_verifying_the_primary_mailbox(): void
    {
        $registeredAt = now()->subDays(2)->startOfSecond();
        $attendedAt = now()->subDay()->startOfSecond();
        $primary = $this->participant('primary@example.test');
        $secondary = $this->participant('secondary@example.test', [
            'verified_at' => $registeredAt, 'checked_in_at' => $attendedAt,
            'email_verified_at' => now()->subDays(3),
        ]);
        $form = $this->webinar->forms()->create(['type' => 'registration', 'title' => 'Registration']);
        $submission = $secondary->submissions()->create(['form_id' => $form->id, 'status' => 'submitted']);
        $this->assertFalse(app(EligibilityService::class)->evaluate($primary)['eligible']);

        $this->merge($primary, $secondary)->assertRedirect(route('admin.participants.show', [$this->webinar, $primary]));

        $primary = $primary->fresh();
        $this->assertTrue($primary->verified_at?->equalTo($registeredAt) ?? false);
        $this->assertTrue($primary->checked_in_at?->equalTo($attendedAt) ?? false);
        $this->assertNull($primary->email_verified_at);
        $this->assertSame('primary@example.test', $primary->email);
        $this->assertSame($primary->id, $submission->fresh()->participant_id);
        $this->assertTrue(app(EligibilityService::class)->evaluate($primary)['eligible']);
        $this->assertSoftDeleted($secondary);
        $this->assertDatabaseHas('audit_logs', ['action' => 'participant.merged', 'auditable_id' => $primary->id]);
    }

    public function test_merge_keeps_existing_primary_registration_attendance_and_mailbox_verification(): void
    {
        $registeredAt = now()->subDays(4)->startOfSecond();
        $attendedAt = now()->subDays(3)->startOfSecond();
        $mailboxVerifiedAt = now()->subDays(5)->startOfSecond();
        $primary = $this->participant('primary@example.test', [
            'verified_at' => $registeredAt, 'checked_in_at' => $attendedAt,
            'email_verified_at' => $mailboxVerifiedAt,
        ]);
        $secondary = $this->participant('secondary@example.test', [
            'verified_at' => now()->subDays(2), 'checked_in_at' => now()->subDay(),
            'email_verified_at' => now(),
        ]);

        $this->merge($primary, $secondary)->assertRedirect();

        $primary = $primary->fresh();
        $this->assertTrue($primary->verified_at->equalTo($registeredAt));
        $this->assertTrue($primary->checked_in_at->equalTo($attendedAt));
        $this->assertTrue($primary->email_verified_at->equalTo($mailboxVerifiedAt));
        $this->assertTrue(app(EligibilityService::class)->evaluate($primary)['eligible']);
    }

    public function test_merge_does_not_invent_registration_or_attendance_evidence(): void
    {
        $primary = $this->participant('primary@example.test');
        $secondary = $this->participant('secondary@example.test', ['email_verified_at' => now()]);

        $this->merge($primary, $secondary)->assertRedirect();

        $primary = $primary->fresh();
        $this->assertNull($primary->verified_at);
        $this->assertNull($primary->checked_in_at);
        $this->assertNull($primary->email_verified_at);
        $this->assertFalse(app(EligibilityService::class)->evaluate($primary)['eligible']);
    }

    private function participant(string $email, array $attributes = []): Participant
    {
        return $this->webinar->participants()->create(['email' => $email, 'full_name' => 'Synthetic Same Person', ...$attributes]);
    }

    private function merge(Participant $primary, Participant $secondary)
    {
        return $this->post(route('admin.participants.merge', [$this->webinar, $primary]), [
            'secondary_email' => $secondary->email,
            'reason' => 'Organizer reviewed both synthetic records and confirmed the same person.',
            'merge_confirmed' => '1',
        ]);
    }
}
