<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\EligibilityOverride;
use App\Models\EligibilityRule;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\ApprovesCertificateFixtures;
use Tests\TestCase;

/**
 * The password-confirmation step-up used to swallow the action it interrupted.
 *
 * The administrator pressed a button, the application asked for the password
 * first, and then dropped the request. Confirming the password returned the
 * administrator to the same page with nothing done and nothing said — so
 * "bypass the requirement and send a certificate to my own inbox for testing"
 * was impossible to complete.
 */
class PasswordConfirmationReplayTest extends TestCase
{
    use ApprovesCertificateFixtures;
    use RefreshDatabase;

    private User $administrator;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('security.require_sensitive_action_password_confirmation', true);

        $this->administrator = User::factory()->create([
            'is_active' => true,
            'password' => Hash::make('CorrectPassword123!'),
        ]);
        $this->webinar = Webinar::query()->create([
            'title' => 'IT Ethics Webinar', 'slug' => 'it-ethics-webinar',
            'status' => 'published', 'timezone' => 'UTC', 'ends_at' => now()->addDay(),
            'data_retention_days' => 7, 'created_by' => $this->administrator->id,
        ]);
    }

    public function test_confirming_the_password_completes_the_interrupted_eligibility_override(): void
    {
        $participant = $this->participant();
        $this->visitParticipantPage($participant);

        // The administrator presses "Save override". The password is asked for
        // first, so nothing is recorded yet.
        $this->actingAs($this->administrator)
            ->post(route('admin.participants.override', [$this->webinar, $participant]), [
                'decision' => 'eligible',
                'reason' => 'Sending a test certificate to my own inbox.',
            ])
            ->assertRedirect(route('admin.password.confirm'));

        $this->assertSame(0, EligibilityOverride::query()->where('participant_id', $participant->id)->count());

        // Confirming the password completes the action: it is not lost, and
        // the administrator does not have to press the button again.
        $this->actingAs($this->administrator)
            ->post(route('admin.password.confirm.store'), ['password' => 'CorrectPassword123!'])
            ->assertRedirect(route('admin.participants.show', [$this->webinar, $participant]))
            ->assertSessionHas('success', 'Eligibility override recorded.');

        $override = EligibilityOverride::query()->where('participant_id', $participant->id)->sole();
        $this->assertSame('eligible', $override->decision);
        $this->assertSame('Sending a test certificate to my own inbox.', $override->reason);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'eligibility.overridden',
            'user_id' => $this->administrator->id,
        ]);
    }

    public function test_the_override_makes_the_participant_selectable_in_the_studio(): void
    {
        Storage::fake('local');
        $participant = $this->participant();
        $this->uploadedTemplate();
        $this->visitParticipantPage($participant);

        $this->actingAs($this->administrator)
            ->post(route('admin.participants.override', [$this->webinar, $participant]), [
                'decision' => 'eligible',
                'reason' => 'Sending a test certificate to my own inbox.',
            ])
            ->assertRedirect(route('admin.password.confirm'));

        $this->actingAs($this->administrator)
            ->post(route('admin.password.confirm.store'), ['password' => 'CorrectPassword123!'])
            ->assertRedirect(route('admin.participants.show', [$this->webinar, $participant]));

        // The studio opens that one person, already selected.
        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->get(route('admin.certificates.studio', [
                'webinar' => $this->webinar,
                'manual' => $participant->public_id,
            ]))
            ->assertOk()
            ->assertSee('data-send-participant="'.$participant->public_id.'" checked', false)
            ->assertDontSee('cannot be certified yet');
    }

    public function test_confirming_the_password_completes_the_interrupted_certificate_send(): void
    {
        Storage::fake('local');
        $participant = $this->participant();
        $this->uploadedTemplate();
        $this->visitParticipantPage($participant);

        // The send is gated by the same step-up.
        $this->approveCertificateFixtures($this->webinar);
        $this->actingAs($this->administrator)
            ->post(route('admin.certificates.issue-selected', $this->webinar), [
                'participants' => [$participant->public_id],
                'preview_confirmed' => '1',
            ])
            ->assertRedirect(route('admin.password.confirm'));

        $this->assertSame(0, Certificate::query()->count());

        $this->actingAs($this->administrator)
            ->post(route('admin.password.confirm.store'), ['password' => 'CorrectPassword123!'])
            ->assertRedirect(route('admin.certificates.studio', $this->webinar))
            ->assertSessionHas('success');

        $certificate = Certificate::query()->where('participant_id', $participant->id)->sole();
        $delivery = EmailDelivery::query()->where('certificate_id', $certificate->id)->sole();
        $this->assertSame('me@example.com', $delivery->recipient_email);
        $this->assertSame('certificate.pdf', $delivery->payload['attachments'][0]['name']);
    }

    public function test_the_confirmation_screen_names_the_action_that_is_waiting(): void
    {
        $participant = $this->participant();
        $this->visitParticipantPage($participant);

        $this->actingAs($this->administrator)
            ->post(route('admin.participants.override', [$this->webinar, $participant]), [
                'decision' => 'eligible',
                'reason' => 'Sending a test certificate to my own inbox.',
            ])
            ->assertRedirect(route('admin.password.confirm'));

        $this->actingAs($this->administrator)
            ->get(route('admin.password.confirm'))
            ->assertOk()
            ->assertSee('record an eligibility override')
            ->assertSee('Confirm and continue');
    }

    public function test_a_remembered_action_is_replayed_only_once(): void
    {
        $participant = $this->participant();
        $this->visitParticipantPage($participant);

        $this->actingAs($this->administrator)
            ->post(route('admin.participants.override', [$this->webinar, $participant]), [
                'decision' => 'eligible',
                'reason' => 'Sending a test certificate to my own inbox.',
            ])
            ->assertRedirect(route('admin.password.confirm'));

        $this->actingAs($this->administrator)
            ->post(route('admin.password.confirm.store'), ['password' => 'CorrectPassword123!']);

        $this->assertSame(1, EligibilityOverride::query()->where('participant_id', $participant->id)->count());

        // A second confirmation must not repeat the action.
        $this->actingAs($this->administrator)
            ->post(route('admin.password.confirm.store'), ['password' => 'CorrectPassword123!'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame(1, EligibilityOverride::query()->where('participant_id', $participant->id)->count());
    }

    public function test_a_wrong_password_replays_nothing(): void
    {
        $participant = $this->participant();
        $this->visitParticipantPage($participant);

        $this->actingAs($this->administrator)
            ->post(route('admin.participants.override', [$this->webinar, $participant]), [
                'decision' => 'eligible',
                'reason' => 'Sending a test certificate to my own inbox.',
            ])
            ->assertRedirect(route('admin.password.confirm'));

        $this->actingAs($this->administrator)
            ->post(route('admin.password.confirm.store'), ['password' => 'WrongPassword123!'])
            ->assertSessionHasErrors('password');

        $this->assertSame(0, EligibilityOverride::query()->where('participant_id', $participant->id)->count());
    }

    /**
     * One button press must cost one attempt of the sensitive-action budget.
     * The intercepted request, the password confirmation and the replay are
     * three HTTP requests, but only one of them is a new attempt.
     */
    public function test_repeated_gated_actions_are_not_rate_limited_by_their_own_replays(): void
    {
        Storage::fake('local');
        $participant = $this->participant();
        $this->uploadedTemplate();
        $this->approveCertificateFixtures($this->webinar);
        $this->visitParticipantPage($participant);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            // Stand in for the confirmation window having expired, so every
            // action is a fresh step-up.
            session()->forget('auth.password_confirmed_at');

            $this->actingAs($this->administrator)
                ->post(route('admin.certificates.issue-selected', $this->webinar), [
                    'participants' => [$participant->public_id],
                    'preview_confirmed' => '1',
                ])
                ->assertRedirect(route('admin.password.confirm'));

            $this->actingAs($this->administrator)
                ->post(route('admin.password.confirm.store'), ['password' => 'CorrectPassword123!'])
                ->assertRedirect(route('admin.certificates.studio', $this->webinar));
        }

        $this->assertSame(1, Certificate::query()->where('participant_id', $participant->id)->count());
    }

    /** An upload cannot be replayed from a session, so it is not remembered. */
    public function test_an_upload_is_not_remembered_for_replay(): void
    {
        Storage::fake('local');
        $template = $this->uploadedTemplate();

        $this->actingAs($this->administrator)
            ->put(route('admin.certification.template', $this->webinar), [
                'name' => 'Formal award',
                'background' => UploadedFile::fake()->image('certificate.png', 400, 300),
            ])
            ->assertRedirect(route('admin.password.confirm'));

        $this->actingAs($this->administrator)
            ->get(route('admin.password.confirm'))
            ->assertOk()
            ->assertDontSee('replace the certificate design');

        $this->actingAs($this->administrator)
            ->post(route('admin.password.confirm.store'), ['password' => 'CorrectPassword123!'])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertSame('Uploaded certificate', $template->fresh()->name);
    }

    private function participant(string $email = 'me@example.com'): Participant
    {
        EligibilityRule::query()->firstOrCreate(
            ['webinar_id' => $this->webinar->id, 'requirement' => 'registration'],
            ['is_required' => true],
        );

        return $this->webinar->participants()->create([
            'email' => $email, 'full_name' => 'Me Myself', 'verified_at' => now(),
        ]);
    }

    /** A browser is always on the page it is acting from. */
    private function visitParticipantPage(Participant $participant): void
    {
        $this->actingAs($this->administrator)
            ->get(route('admin.participants.show', [$this->webinar, $participant]))
            ->assertOk();
    }

    private function uploadedTemplate(): CertificateTemplate
    {
        $image = UploadedFile::fake()->image('certificate.png', 1000, 700);
        Storage::disk('local')->put('certificate-backgrounds/test.png', file_get_contents($image->getPathname()));

        return $this->webinar->certificateTemplates()->create([
            'name' => 'Uploaded certificate',
            'storage_disk' => 'local',
            'background_path' => 'certificate-backgrounds/test.png',
            'layout' => ['accent' => '#1d4ed8', 'name_top' => 62, 'name_font_size' => 42],
            'is_active' => true,
        ]);
    }
}
