<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Form;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class DeletionTombstoneSecurityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Webinar $webinar;

    private CertificateTemplate $template;

    private Form $registrationForm;

    private Participant $participant;

    private Certificate $certificate;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'password' => bcrypt('AdminPassword123!'),
            'two_factor_confirmed_at' => now(),
        ]);

        $this->webinar = Webinar::query()->create([
            'title' => 'Tombstoned Security Webinar',
            'slug' => 'tombstoned-security-webinar',
            'status' => 'published',
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->subDay(),
            'data_retention_days' => 7,
            'timezone' => 'UTC',
            'created_by' => $this->admin->id,
        ]);
        $this->webinar->forceFill(['deletion_started_at' => now()])->save();

        $this->template = CertificateTemplate::query()->create([
            'webinar_id' => $this->webinar->id,
            'name' => 'Default template',
            'storage_disk' => 'local',
            'layout' => [],
            'is_active' => true,
        ]);

        $this->registrationForm = Form::query()->create([
            'webinar_id' => $this->webinar->id,
            'type' => 'registration',
            'title' => 'Registration',
            'public_token' => 'tombstonedregtoken123456',
        ]);

        $this->participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'email' => 'attendee@example.com',
            'full_name' => 'Attendee One',
        ]);

        $this->certificate = Certificate::query()->create([
            'webinar_id' => $this->webinar->id,
            'certificate_template_id' => $this->template->id,
            'participant_id' => $this->participant->id,
            'verification_code' => 'CERT-TOMBSTONE-1234',
            'verification_code_hash' => Certificate::verificationCodeHash('CERT-TOMBSTONE-1234'),
            'status' => 'issued',
            'issued_at' => now(),
            'storage_disk' => 'local',
            'file_path' => 'certificates/tombstone.pdf',
        ]);
    }

    #[Test]
    public function tombstoned_webinar_routes_abort_with_conflict(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.webinars.show', $this->webinar))
            ->assertStatus(409);

        $this->get(route('admin.webinars.edit', $this->webinar))
            ->assertStatus(409);

        $this->put(route('admin.webinars.update', $this->webinar), [
            'title' => 'Updated Title',
        ])->assertStatus(409);

        $this->get(route('admin.webinars.reports', $this->webinar))
            ->assertStatus(409);

        $this->get(route('admin.participants.index', $this->webinar))
            ->assertStatus(409);

        $this->get(route('admin.forms.edit', [$this->webinar, $this->registrationForm]))
            ->assertStatus(409);

        $this->get(route('admin.certificates.studio', $this->webinar))
            ->assertStatus(409);
    }

    #[Test]
    public function certificate_operations_on_tombstoned_webinar_abort_with_conflict(): void
    {
        $this->actingAs($this->admin);

        $this->get(route('admin.certificates.download', $this->certificate))
            ->assertStatus(409);

        $this->post(route('admin.certificates.revoke', $this->certificate), [
            'reason' => 'Revoke after tombstone started',
        ])->assertStatus(409);

        $this->post(route('admin.certificates.resend', $this->certificate))
            ->assertStatus(409);
    }

    #[Test]
    public function public_verification_fails_closed_for_tombstoned_webinar(): void
    {
        $this->get(route('certificates.verify', 'CERT-TOMBSTONE-1234'))
            ->assertNotFound();
    }

    #[Test]
    public function destroy_route_is_not_blocked_by_tombstone_middleware(): void
    {
        $this->actingAs($this->admin);

        // Submitting with wrong confirmation triggers validation failure (302/redirect with errors), not 409
        $response = $this->delete(route('admin.webinars.destroy', $this->webinar), [
            'confirm' => 'Wrong confirmation string',
        ]);

        $this->assertNotSame(409, $response->getStatusCode());
        $response->assertSessionHasErrors('confirm');
    }
}
