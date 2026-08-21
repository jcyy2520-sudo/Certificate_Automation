<?php

namespace Tests\Feature;

use App\Models\CertificateTemplate;
use App\Models\EligibilityRule;
use App\Models\Participant;
use App\Models\Submission;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Throwaway: renders the redesigned admin pages as authenticated HTML snapshots
 * into public/_preview so they can be viewed through the dev server. Not a real
 * assertion suite — delete after visual review.
 */
class ZzPreviewSnapshotTest extends TestCase
{
    use RefreshDatabase;

    public function test_render_snapshots(): void
    {
        $admin = User::factory()->create(['is_active' => true, 'name' => 'Alex Rivera']);

        $webinar = Webinar::query()->create([
            'title' => 'Advanced Cardiac Life Support 2026',
            'slug' => 'acls-2026',
            'status' => 'published',
            'timezone' => 'UTC',
            'starts_at' => now()->subDays(3),
            'ends_at' => now()->subDays(3)->addHours(4),
            'created_by' => $admin->id,
            'data_retention_days' => 30,
        ]);

        collect([
            ['type' => 'registration', 'title' => 'Registration', 'status' => 'published'],
            ['type' => 'pretest', 'title' => 'Pre-assessment', 'status' => 'published', 'show_score' => true],
            ['type' => 'posttest', 'title' => 'Post-assessment', 'status' => 'published', 'show_score' => true],
            ['type' => 'evaluation', 'title' => 'Event evaluation', 'status' => 'published'],
        ])->each(fn ($f) => $webinar->forms()->create($f));

        EligibilityRule::query()->create([
            'webinar_id' => $webinar->id, 'requirement' => 'registration', 'is_required' => true,
        ]);

        CertificateTemplate::query()->create([
            'webinar_id' => $webinar->id,
            'name' => 'ACLS completion certificate',
            'storage_disk' => config('webinar.certificate_disk'),
            'template_path' => 'generated/classic',
            'layout' => [
                'accent' => '#1d4ed8',
                'heading' => 'Certificate of Completion',
                'body' => 'has successfully completed all requirements of',
                'signatory_name' => 'Dr. Morgan Lee',
                'signatory_title' => 'Program Director',
            ],
            'is_active' => true,
        ]);

        $forms = $webinar->forms->keyBy('type');

        $people = [
            ['Maria Santos', 'maria.santos@example.com', 'St. Luke’s Medical Center'],
            ['James Okoro', 'james.okoro@example.com', 'City General Hospital'],
            ['Wei Chen', 'wei.chen@example.com', 'Riverside Clinic'],
            ['Priya Nair', 'priya.nair@example.com', 'National Health Institute'],
            ['Diego Fernández', 'diego.fernandez@example.com', 'Andes Regional Hospital'],
            ['Fatima Al-Sayed', 'fatima.alsayed@example.com', 'Gulf Medical College'],
            ['Sofia Rossi', 'sofia.rossi@example.com', 'Milano Cardiology Unit'],
            ['Kwame Mensah', 'kwame.mensah@example.com', 'Accra Teaching Hospital'],
        ];

        foreach ($people as $i => [$name, $email, $org]) {
            $participant = $webinar->participants()->create([
                'full_name' => $name,
                'email' => $email,
                'organization' => $org,
                'verified_at' => now()->subDays(3),
                'created_at' => now()->subDays(3 + $i),
            ]);

            // Give most of them assessment submissions so attendance + reports show data.
            if ($i < 6) {
                foreach (['pretest' => [6, 10], 'posttest' => [8, 10]] as $type => [$score, $max]) {
                    Submission::query()->create([
                        'form_id' => $forms[$type]->id,
                        'participant_id' => $participant->id,
                        'attempt_number' => 1,
                        'status' => 'submitted',
                        'score' => $score + ($i % 3),
                        'maximum_score' => $max,
                        'submitted_at' => now()->subDays(2),
                    ]);
                }
            }
        }

        $dir = public_path('_preview');
        if (! is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        $pages = [
            'overview' => route('admin.webinars.show', $webinar),
            'participants' => route('admin.participants.index', $webinar),
            'studio' => route('admin.certificates.studio', $webinar),
            'reports' => route('admin.webinars.reports', $webinar),
            'template' => route('admin.certification.edit', $webinar),
        ];

        foreach ($pages as $key => $url) {
            $this->withoutExceptionHandling();
            $response = $this->actingAs($admin)->get($url);
            file_put_contents($dir.DIRECTORY_SEPARATOR.$key.'.html', $response->getContent());
        }

        $this->assertTrue(true);
    }
}
