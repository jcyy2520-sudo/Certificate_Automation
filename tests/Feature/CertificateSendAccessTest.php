<?php

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CertificateService;
use App\Services\PendingSensitiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CertificateSendAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webinar $webinar;

    private Participant $participant;

    protected function setUp(): void
    {
        parent::setUp();
        config(['security.require_sensitive_action_password_confirmation' => true]);
        Storage::fake('local');
        Queue::fake();
        $this->owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->webinar = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $this->webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
        $this->participant = $this->webinar->participants()->create([
            'full_name' => 'Synthetic Send Recipient', 'email' => 'send-access@example.test', 'verified_at' => now(),
        ]);
        $image = UploadedFile::fake()->image('certificate.png', 1200, 850);
        Storage::disk('local')->put('certificate-backgrounds/send-access.png', file_get_contents($image->getRealPath()));
        $this->webinar->certificateTemplates()->create([
            'name' => 'Send access fixture', 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/send-access.png', 'is_active' => true,
            'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 36, 'name_font_family' => 'sans'],
        ]);
    }

    public static function sendActions(): array
    {
        return array_combine(['selected', 'individual', 'batch', 'resend', 'sample'], array_map(fn ($action) => [$action], ['selected', 'individual', 'batch', 'resend', 'sample']));
    }

    private function sendRequest(string $action): array
    {
        if ($action === 'resend') {
            $certificate = app(CertificateService::class)->issue($this->participant);
            $certificate->deliveries()->sole()->update(['status' => 'failed']);

            return [route('admin.certificates.resend', $certificate), []];
        }

        return match ($action) {
            'selected' => [route('admin.certificates.issue-selected', $this->webinar), ['participants' => [$this->participant->public_id], 'preview_confirmed' => '1']],
            'individual' => [route('admin.certificates.store', [$this->webinar, $this->participant]), []],
            'batch' => [route('admin.certificates.batch', $this->webinar), []],
            'sample' => [route('admin.certification.email-test', $this->webinar), ['test_key' => (string) Str::uuid(), 'confirmed' => '1']],
        };
    }

    #[DataProvider('sendActions')]
    public function test_authorized_owner_can_send_without_a_confirmed_password(string $action): void
    {
        [$url, $data] = $this->sendRequest($action);
        $this->actingAs($this->owner)->withSession(['auth.password_confirmed_at' => 0])
            ->post($url, $data)->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(0, session('auth.password_confirmed_at'));
        $this->assertNull(session(PendingSensitiveAction::SESSION_KEY));
        if ($action === 'batch') {
            $this->assertDatabaseCount('certificate_batches', 1);
        } elseif ($action === 'sample') {
            $delivery = EmailDelivery::where('type', 'certificate_test')->sole();
            $this->assertSame($this->owner->email, $delivery->recipient_email);
        } else {
            $this->assertDatabaseCount('certificates', 1);
        }
    }

    #[DataProvider('sendActions')]
    public function test_sending_still_requires_sign_in_and_webinar_authorization(string $action): void
    {
        [$url, $data] = $this->sendRequest($action);
        $certificates = Certificate::count();
        $deliveries = EmailDelivery::count();
        $this->post($url, $data)->assertRedirect(route('login'));
        $outsider = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($outsider)->post($url, $data)->assertNotFound();
        $this->assertDatabaseCount('certificates', $certificates);
        $this->assertDatabaseCount('email_deliveries', $deliveries);
        $this->assertDatabaseCount('certificate_batches', 0);
    }
}
