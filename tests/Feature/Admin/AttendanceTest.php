<?php

namespace Tests\Feature\Admin;

use App\Models\AuditLog;
use App\Models\EligibilityRule;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\EligibilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AttendanceTest extends TestCase
{
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->administrator = User::factory()->create(['is_active' => true]);
        $this->webinar = Webinar::query()->create([
            'title' => 'Attendance Workshop',
            'slug' => 'attendance-workshop',
            'status' => 'published',
            'timezone' => 'UTC',
            'created_by' => $this->administrator->id,
        ]);
        $this->participant = Participant::query()->create([
            'webinar_id' => $this->webinar->id,
            'full_name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'verified_at' => now(),
        ]);
    }

    public function test_an_administrator_can_toggle_attendance_on_and_off(): void
    {
        $route = route('admin.participants.attendance', [$this->webinar, $this->participant]);

        $this->actingAs($this->administrator)->post($route)
            ->assertRedirect()
            ->assertSessionHas('success', 'Participant marked present.');

        $this->assertNotNull($this->participant->fresh()->checked_in_at);
        $firstAudit = AuditLog::query()->where('action', 'participant.attendance_toggled')->firstOrFail();
        $this->assertTrue($firstAudit->metadata['checked_in']);

        $this->actingAs($this->administrator)->post($route)
            ->assertRedirect()
            ->assertSessionHas('success', 'Attendance mark removed.');

        $this->assertNull($this->participant->fresh()->checked_in_at);
        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertFalse(
            AuditLog::query()->where('action', 'participant.attendance_toggled')->latest('id')->firstOrFail()->metadata['checked_in'],
        );
    }

    public function test_attendance_does_not_change_eligibility_without_an_attendance_rule(): void
    {
        EligibilityRule::query()->create([
            'webinar_id' => $this->webinar->id,
            'requirement' => 'registration',
            'is_required' => true,
        ]);
        $service = app(EligibilityService::class);

        $this->assertTrue($service->evaluate($this->participant->fresh())['eligible']);

        $this->actingAs($this->administrator)
            ->post(route('admin.participants.attendance', [$this->webinar, $this->participant]));

        $this->assertTrue($service->evaluate($this->participant->fresh())['eligible']);
        $this->assertSame([$this->participant->id], $service->eligibleParticipantsQuery($this->webinar->fresh())->pluck('participants.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_attendance_rule_keeps_single_and_set_based_eligibility_consistent(): void
    {
        EligibilityRule::query()->create([
            'webinar_id' => $this->webinar->id,
            'requirement' => 'attendance',
            'is_required' => true,
        ]);
        $service = app(EligibilityService::class);

        $this->assertFalse($service->evaluate($this->participant->fresh())['eligible']);
        $this->assertSame([], $service->eligibleParticipantsQuery($this->webinar->fresh())->pluck('participants.id')->map(fn ($id) => (int) $id)->all());

        $route = route('admin.participants.attendance', [$this->webinar, $this->participant]);
        $this->actingAs($this->administrator)->post($route);

        $this->assertTrue($service->evaluate($this->participant->fresh())['eligible']);
        $this->assertSame([$this->participant->id], $service->eligibleParticipantsQuery($this->webinar->fresh())->pluck('participants.id')->map(fn ($id) => (int) $id)->all());

        $this->actingAs($this->administrator)->post($route);

        $this->assertFalse($service->evaluate($this->participant->fresh())['eligible']);
        $this->assertSame([], $service->eligibleParticipantsQuery($this->webinar->fresh())->pluck('participants.id')->map(fn ($id) => (int) $id)->all());
    }

    public function test_attendance_is_opt_in_on_the_existing_requirements_screen(): void
    {
        $this->assertDatabaseMissing('eligibility_rules', [
            'webinar_id' => $this->webinar->id,
            'requirement' => 'attendance',
        ]);

        $this->actingAs($this->administrator)
            ->get(route('admin.certification.edit', $this->webinar))
            ->assertOk()
            ->assertSee('Attendance recorded');

        $this->actingAs($this->administrator)
            ->put(route('admin.certification.rules', $this->webinar), [
                'rules' => ['attendance' => ['enabled' => '1', 'minimum_score' => 99]],
            ])
            ->assertRedirect();

        $rule = EligibilityRule::query()->where('webinar_id', $this->webinar->id)
            ->where('requirement', 'attendance')
            ->firstOrFail();
        $this->assertTrue($rule->is_required);
        $this->assertNull($rule->minimum_score);
    }

    public function test_attendance_is_visible_on_admin_pages_and_csv_export(): void
    {
        $this->participant->update(['checked_in_at' => now()]);

        $response = $this->actingAs($this->administrator)
            ->get(route('admin.participants.index', $this->webinar));

        $response
            ->assertOk()
            ->assertSee('Attendance')
            ->assertSee('Present')
            ->assertSee('form="attendance-'.$this->participant->id.'"', false)
            ->assertSee('id="attendance-'.$this->participant->id.'"', false);

        $table = str($response->getContent())->between('<table', '</table>');
        $this->assertStringNotContainsString('<form', (string) $table);

        $this->actingAs($this->administrator)
            ->get(route('admin.participants.show', [$this->webinar, $this->participant]))
            ->assertOk()
            ->assertSee('Marked present');

        $response = $this->actingAs($this->administrator)
            ->get(route('admin.participants.export', $this->webinar));
        $rows = array_map('str_getcsv', array_filter(preg_split('/\R/', trim($response->streamedContent()))));
        $record = array_combine($rows[0], $rows[1]);

        $this->assertSame($this->participant->checked_in_at->toDateString(), $record['Attendance']);
    }
}
