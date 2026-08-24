<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureRecentPassword;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ParticipantExportReauthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_participant_export_requires_a_recent_password_confirmation(): void
    {
        config()->set('security.require_sensitive_action_password_confirmation', true);
        $user = User::factory()->create();
        $webinar = Webinar::query()->create([
            'title' => 'Private Event',
            'slug' => 'private-event',
            'created_by' => $user->id,
        ]);

        $export = route('admin.participants.export', $webinar);
        $this->actingAs($user)->get($export)
            ->assertRedirect(route('admin.password.confirm'));

        $this->from(route('admin.password.confirm'))
            ->post(route('admin.password.confirm.store'), ['password' => 'wrong-password'])
            ->assertSessionHasErrors('password');

        $this->post(route('admin.password.confirm.store'), ['password' => 'password'])
            ->assertRedirect($export);

        $this->get($export)
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertDatabaseHas('audit_logs', ['action' => 'administrator.password_confirmed']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'participants.exported']);
    }

    public function test_every_high_impact_route_carries_the_recent_password_gate(): void
    {
        $names = [
            'admin.webinars.destroy',
            'admin.webinars.archive',
            'admin.forms.rotate-link',
            'admin.forms.fields.destroy',
            'admin.forms.questions.destroy',
            'admin.certification.rules',
            'admin.certification.template',
            'admin.participants.store',
            'admin.participants.export',
            'admin.participants.name',
            'admin.participants.override',
            'admin.participants.destroy',
            'admin.certificates.batch',
            'admin.certificates.store',
            'admin.certificates.download',
            'admin.certificates.revoke',
        ];

        foreach ($names as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing sensitive route {$name}.");
            $this->assertContains(
                EnsureRecentPassword::class,
                $route->middleware(),
                "Sensitive route {$name} is missing recent-password confirmation.",
            );
        }

        foreach (['admin.participants.attendance', 'admin.certification.design'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing deliberately ungated route {$name}.");
            $this->assertNotContains(
                EnsureRecentPassword::class,
                $route->middleware(),
                "Operational route {$name} should remain outside recent-password confirmation.",
            );
        }
    }
}
