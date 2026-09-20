<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\CertificateTemplate;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\TestCase;

class WebinarPrivacyLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create();
    }

    public function test_a_committed_retention_deadline_cannot_be_cleared_or_extended(): void
    {
        $endsAt = CarbonImmutable::now()->addDays(10)->startOfMinute();

        $this->actingAs($this->administrator)->post(route('admin.webinars.store'), [
            'title' => 'Privacy Deadline',
            'status' => 'published',
            'timezone' => 'UTC',
            'ends_at' => $endsAt->format('Y-m-d H:i:s'),
            'data_retention_days' => 7,
        ])->assertRedirect();

        $webinar = Webinar::query()->where('slug', 'privacy-deadline')->firstOrFail();
        $committedDeadline = $endsAt->addDays(7);
        $this->assertTrue($webinar->retention_due_at->equalTo($committedDeadline));

        $this->actingAs($this->administrator)
            ->get(route('admin.webinars.edit', $webinar))
            ->assertOk()
            ->assertSee($committedDeadline->format('F j, Y \a\t g:i A T'))
            ->assertSee('This deadline can be shortened but never extended.');

        $this->actingAs($this->administrator)
            ->from(route('admin.webinars.edit', $webinar))
            ->put(route('admin.webinars.update', $webinar), $this->updatePayload($webinar, [
                'data_retention_days' => 8,
            ]))
            ->assertSessionHasErrors('data_retention_days');

        $this->assertTrue($webinar->fresh()->retention_due_at->equalTo($committedDeadline));

        $this->actingAs($this->administrator)
            ->from(route('admin.webinars.edit', $webinar))
            ->put(route('admin.webinars.update', $webinar), $this->updatePayload($webinar, [
                'status' => 'draft',
                'ends_at' => '',
            ]))
            ->assertSessionHasErrors('ends_at');

        $webinar->refresh()->forceFill(['retention_due_at' => null])->save();
        $this->assertTrue($webinar->fresh()->retention_due_at->equalTo($committedDeadline));

        // Reducing retention is privacy-safe and moves the deadline only earlier.
        $this->actingAs($this->administrator)
            ->put(route('admin.webinars.update', $webinar), $this->updatePayload($webinar, [
                'data_retention_days' => 3,
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($webinar->fresh()->retention_due_at->equalTo($endsAt->addDays(3)));
    }

    public function test_data_bearing_webinar_without_a_deadline_fails_and_alerts(): void
    {
        Log::spy();

        $webinar = Webinar::query()->create([
            'title' => 'Legacy Event',
            'slug' => 'legacy-event',
            'created_by' => $this->administrator->id,
        ]);
        $participant = Participant::query()->create([
            'webinar_id' => $webinar->id,
            'full_name' => 'Deadline Missing',
            'email' => 'missing@example.com',
        ]);

        $this->artisan('privacy:erase-expired-participants')
            ->expectsOutput("Webinar {$webinar->id} contains participant data but has no retention deadline.")
            ->assertExitCode(Command::FAILURE);

        $this->assertNull($participant->fresh()->privacy_erased_at);
        Log::shouldHaveReceived('critical')->once()->with(
            'Participant data has no committed retention deadline.',
            [
                'webinar_id' => $webinar->id,
                'command' => 'privacy:erase-expired-participants',
            ],
        );
    }

    public function test_one_storage_failure_does_not_starve_later_overdue_participants(): void
    {
        Log::spy();

        $webinar = $this->expiredWebinar('isolated-erasure');
        $template = CertificateTemplate::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'Default',
            'layout' => [],
        ]);
        $blocked = Participant::query()->create([
            'webinar_id' => $webinar->id,
            'full_name' => 'Blocked Record',
            'email' => 'blocked@example.com',
        ]);
        $later = Participant::query()->create([
            'webinar_id' => $webinar->id,
            'full_name' => 'Later Record',
            'email' => 'later@example.com',
        ]);
        Certificate::query()->create([
            'verification_code' => 'CERT-ISOLATED-FAILURE',
            'webinar_id' => $webinar->id,
            'participant_id' => $blocked->id,
            'certificate_template_id' => $template->id,
            'recipient_name' => $blocked->full_name,
            'storage_disk' => 'local',
            'file_path' => 'certificates/not-the-public-id.pdf',
            'status' => 'issued',
            'issued_at' => now()->subDays(8),
        ]);

        $this->artisan('privacy:erase-expired-participants')
            ->expectsOutput("Participant {$blocked->id} in webinar {$webinar->id} could not be erased.")
            ->assertExitCode(Command::FAILURE);

        $this->assertNull($blocked->fresh()->privacy_erased_at);
        $this->assertNotNull($later->fresh()->privacy_erased_at);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'privacy.retention_erasure_completed',
            'auditable_id' => null,
        ]);
        Log::shouldHaveReceived('critical')->once()->with(
            'An overdue participant record could not be erased.',
            ['webinar_id' => $webinar->id, 'participant_id' => $blocked->id],
        );
    }

    public function test_webinar_deletion_is_tombstoned_and_work_is_cancelled_before_file_cleanup(): void
    {
        $webinar = $this->expiredWebinar('interrupted-deletion');
        $template = CertificateTemplate::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'Default',
            'layout' => [],
        ]);
        $participant = Participant::query()->create([
            'webinar_id' => $webinar->id,
            'full_name' => 'Delete Me',
            'email' => 'delete@example.com',
        ]);
        $certificate = Certificate::query()->create([
            'verification_code' => 'CERT-DELETION-TOMBSTONE',
            'webinar_id' => $webinar->id,
            'participant_id' => $participant->id,
            'certificate_template_id' => $template->id,
            'recipient_name' => $participant->full_name,
            'storage_disk' => 'local',
            'file_path' => 'certificates/untrusted-path.pdf',
            'status' => 'issued',
            'issued_at' => now(),
        ]);
        $batch = CertificateBatch::query()->create([
            'webinar_id' => $webinar->id,
            'certificate_template_id' => $template->id,
            'created_by' => $this->administrator->id,
            'status' => 'processing',
        ]);
        $delivery = EmailDelivery::query()->create([
            'webinar_id' => $webinar->id,
            'participant_id' => $participant->id,
            'certificate_id' => $certificate->id,
            'type' => 'certificate',
            'recipient_email' => $participant->email,
            'subject' => 'Private certificate',
            'payload' => ['html' => 'Private message'],
            'status' => 'pending',
        ]);

        $this->withoutExceptionHandling();

        try {
            $this->actingAs($this->administrator)->delete(
                route('admin.webinars.destroy', $webinar),
                ['confirm' => $webinar->title],
            );
            $this->fail('The malformed file inventory should have interrupted cleanup.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Refusing to delete an unexpected certificate file path.', $exception->getMessage());
        }

        $webinar->refresh();
        $this->assertNotNull($webinar->deletion_started_at);
        $this->assertSame('archived', $webinar->status);
        $this->assertSame('cancelled', $batch->fresh()->status);
        $this->assertNotNull($batch->fresh()->completed_at);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertNull($delivery->fresh()->recipient_email);
        $this->assertNull($delivery->fresh()->payload);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'webinar.deletion_started',
            'auditable_id' => $webinar->id,
        ]);
        $this->assertDatabaseMissing('audit_logs', ['action' => 'webinar.deleted']);
    }

    private function expiredWebinar(string $slug): Webinar
    {
        return Webinar::query()->create([
            'title' => str($slug)->headline()->value(),
            'slug' => $slug,
            'status' => 'completed',
            'ends_at' => now()->subDays(10),
            'data_retention_days' => 7,
            'timezone' => 'UTC',
            'created_by' => $this->administrator->id,
        ]);
    }

    /** @param  array<string, mixed>  $overrides */
    private function updatePayload(Webinar $webinar, array $overrides = []): array
    {
        return array_merge([
            'title' => $webinar->title,
            'description' => $webinar->description,
            'status' => $webinar->status,
            'starts_at' => $webinar->starts_at?->format('Y-m-d H:i:s'),
            'ends_at' => $webinar->ends_at?->format('Y-m-d H:i:s'),
            'registration_opens_at' => $webinar->registration_opens_at?->format('Y-m-d H:i:s'),
            'registration_closes_at' => $webinar->registration_closes_at?->format('Y-m-d H:i:s'),
            'timezone' => $webinar->timezone,
            'data_retention_days' => $webinar->data_retention_days,
        ], $overrides);
    }
}
