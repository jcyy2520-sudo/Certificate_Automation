<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\IssueSelectedCertificate;
use App\Jobs\SendTransactionalEmail;
use App\Models\AuditLog;
use App\Models\User;
use App\Models\Webinar;
use App\Policies\WebinarPolicy;
use App\Services\CertificateService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdministratorWebinarLockTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $owner;

    private User $manager;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create();
        $this->owner = User::factory()->create(['role' => User::USER_ROLE, 'account_source' => 'public_request']);
        $this->manager = User::factory()->create(['role' => User::MANAGER_ROLE, 'account_source' => 'admin_invitation']);
        $this->webinar = Webinar::query()->create([
            'title' => 'Restricted webinar fixture', 'slug' => (string) Str::uuid(),
            'description' => 'Private description must not leak after locking.',
            'created_by' => $this->owner->id, 'status' => 'published', 'timezone' => 'Asia/Manila',
            'ends_at' => now()->addDay(), 'data_retention_days' => 30, 'requires_verification' => false,
            'settings' => ['original' => 'preserved'],
        ]);
        $this->manager->assignedWebinars()->attach($this->webinar, [
            'assigned_by' => $this->admin->id, 'manage_participants' => true, 'manage_certificates' => true, 'send_emails' => true,
        ]);
        $this->webinar->forms()->create(['type' => 'registration', 'title' => 'Public registration', 'status' => 'published']);
        $this->webinar->certificateTemplates()->create(['name' => 'Synthetic artwork', 'storage_disk' => 'local', 'background_path' => 'synthetic.png', 'layout' => [], 'is_active' => true]);
    }

    private function lockUrl(): string
    {
        return '/admin/webinars/'.$this->webinar->id.'/administrator-lock';
    }

    private function lock(): void
    {
        $this->actingAs($this->admin)->patch($this->lockUrl(), ['locked' => true])
            ->assertRedirect(route('admin.webinars.index'))->assertSessionHasNoErrors();
        $this->webinar->refresh();
    }

    public function test_administrator_can_lock_and_unlock_without_changing_existing_business_data(): void
    {
        $participant = $this->webinar->participants()->create(['full_name' => 'Preserved participant', 'email' => 'preserved@example.test', 'verified_at' => now()]);
        $this->actingAs($this->manager);
        app(CertificateService::class)->queue($participant);
        app(NotificationService::class)->queue($this->webinar, $participant, 'certificate_test', $participant->email, 'Preserved message', '<p>Original content</p>');
        $attributes = $this->webinar->fresh()->getAttributes();
        $tables = ['users', 'participants', 'forms', 'certificate_templates', 'certificates', 'jobs', 'email_deliveries', 'webinar_user', 'eligibility_rules'];
        $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();

        $this->lock();
        $this->assertNotNull($this->webinar->admin_locked_at);
        $this->assertSame($this->admin->id, $this->webinar->admin_locked_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webinar.admin_locked', 'actor_user_id' => $this->admin->id, 'webinar_id' => $this->webinar->id]);

        $this->actingAs($this->admin)->patch($this->lockUrl(), ['locked' => false])->assertRedirect(route('admin.webinars.index'));
        $this->webinar->refresh();
        $this->assertNull($this->webinar->admin_locked_at);
        $this->assertNull($this->webinar->admin_locked_by);
        $this->assertDatabaseHas('audit_logs', ['action' => 'webinar.admin_unlocked', 'actor_user_id' => $this->admin->id]);
        $except = ['admin_locked_at', 'admin_locked_by', 'updated_at'];
        $this->assertSame(collect($attributes)->except($except)->all(), collect($this->webinar->getAttributes())->except($except)->all());
        foreach ($before as $table => $rows) {
            $this->assertSame($rows, DB::table($table)->get()->toJson(), $table.' changed during lock/unlock');
        }
    }

    public function test_only_administrators_can_mutate_the_lock_and_repeating_a_state_is_idempotent(): void
    {
        foreach ([$this->owner, $this->manager] as $user) {
            $this->actingAs($user)->patchJson($this->lockUrl(), ['locked' => true])->assertForbidden();
        }
        $this->lock();
        $firstTime = $this->webinar->getRawOriginal('admin_locked_at');
        $this->travel(5)->minutes();
        $this->patchJson($this->lockUrl(), ['locked' => true])->assertOk()->assertJsonPath('locked', true);
        $this->assertSame($firstTime, $this->webinar->fresh()->getRawOriginal('admin_locked_at'));
        $this->assertSame(1, AuditLog::query()->where('action', 'webinar.admin_locked')->count());
        $this->patchJson($this->lockUrl(), ['locked' => false])->assertOk()->assertJsonPath('locked', false);
        $this->patchJson($this->lockUrl(), ['locked' => false])->assertOk();
        $this->assertSame(1, AuditLog::query()->where('action', 'webinar.admin_unlocked')->count());
    }

    public function test_lock_requires_an_explicit_boolean_and_cannot_be_mass_assigned(): void
    {
        $this->actingAs($this->admin)->patchJson($this->lockUrl(), [])->assertUnprocessable()->assertJsonValidationErrors('locked');
        $this->patchJson($this->lockUrl(), ['locked' => 'unexpected'])->assertUnprocessable();
        $this->webinar->fill(['admin_locked_at' => now(), 'admin_locked_by' => $this->manager->id])->save();
        $this->assertNull($this->webinar->fresh()->admin_locked_at);
        $this->assertNull($this->webinar->fresh()->admin_locked_by);
    }

    public function test_assigned_manager_is_blocked_on_all_management_entry_points_even_with_a_prior_password_grant(): void
    {
        $participant = $this->webinar->participants()->create(['full_name' => 'Private fixture person', 'email' => 'private-person@example.test', 'verified_at' => now()]);
        $this->actingAs($this->manager);
        $certificate = app(CertificateService::class)->queue($participant);
        $this->webinar->forceFill(['management_password' => Hash::make('Previously unlocked webinar'), 'management_lock_version' => (string) Str::uuid()])->save();
        $this->get(route('admin.webinars.show', $this->webinar))->assertRedirect(route('admin.webinars.unlock', $this->webinar));
        $this->withSession(['webinar_unlocks.'.$this->webinar->id => ['version' => $this->webinar->management_lock_version, 'until' => now()->addHour()->timestamp]])
            ->get(route('admin.webinars.show', $this->webinar))->assertOk();
        $this->lock();
        $this->actingAs($this->manager)->withSession(['webinar_unlocks.'.$this->webinar->id => ['version' => $this->webinar->management_lock_version, 'until' => now()->addHour()->timestamp]]);
        $paths = [
            '/admin/webinars/'.$this->webinar->id,
            '/admin/webinars/'.$this->webinar->id.'/edit',
            '/admin/webinars/'.$this->webinar->id.'/unlock',
            '/admin/webinars/'.$this->webinar->id.'/forms/'.$this->webinar->forms->first()->id.'/edit',
            '/admin/webinars/'.$this->webinar->id.'/participants',
            '/admin/webinars/'.$this->webinar->id.'/participants/export',
            '/admin/webinars/'.$this->webinar->id.'/participants/'.$participant->id,
            '/admin/webinars/'.$this->webinar->id.'/responses',
            '/admin/webinars/'.$this->webinar->id.'/responses/export',
            '/admin/webinars/'.$this->webinar->id.'/eligibility',
            '/admin/webinars/'.$this->webinar->id.'/documents',
            '/admin/webinars/'.$this->webinar->id.'/imports',
            '/admin/webinars/'.$this->webinar->id.'/reports',
            '/admin/webinars/'.$this->webinar->id.'/certification',
            '/admin/webinars/'.$this->webinar->id.'/certification/background',
            '/admin/webinars/'.$this->webinar->id.'/certificates/studio',
            '/admin/webinars/'.$this->webinar->id.'/certificates/studio/status',
            '/admin/webinars/'.$this->webinar->id.'/certificates/export-zip',
            '/admin/webinars/'.$this->webinar->id.'/messages',
            '/admin/webinars/'.$this->webinar->id.'/email-logs',
            '/admin/webinars/'.$this->webinar->id.'/audit',
            '/admin/certificates/'.$certificate->id.'/download',
        ];
        foreach ($paths as $path) {
            $this->get($path)->assertStatus(423)->assertSee('restricted by an administrator')->assertDontSee('private-person@example.test');
        }
        foreach (['/certificates/issue-selected', '/certificates/send-plan', '/certificates/send-step', '/documents', '/messages', '/unlock'] as $suffix) {
            $this->postJson('/admin/webinars/'.$this->webinar->id.$suffix, [])->assertStatus(423)->assertJsonPath('message', 'Access to this webinar has been restricted by an administrator. Contact your administrator if you need access.');
        }
        $this->postJson('/admin/certificates/'.$certificate->id.'/resend', [])->assertStatus(423);
        $this->postJson('/admin/certificates/'.$certificate->id.'/revoke', [])->assertStatus(423);
    }

    public function test_owner_is_blocked_but_an_unrelated_account_still_receives_404(): void
    {
        $this->lock();
        $this->actingAs($this->owner)->get(route('admin.webinars.show', $this->webinar))->assertStatus(423)->assertSee('restricted by an administrator');
        $unrelated = User::factory()->create(['role' => User::MANAGER_ROLE]);
        $this->actingAs($unrelated)->get(route('admin.webinars.show', $this->webinar))->assertNotFound()->assertDontSee($this->webinar->title);
        $this->getJson(route('admin.webinars.show', $this->webinar))->assertNotFound();
    }

    public function test_administrator_retains_access_and_can_unlock_without_cross_owner_grant(): void
    {
        $this->lock();
        $this->withSession(['administrative_webinars.'.$this->webinar->id => now()->addHour()->timestamp])
            ->get(route('admin.webinars.show', $this->webinar))->assertOk()->assertSee('Admin locked')->assertSee('Unlock webinar');
        $this->withSession(['administrative_webinars' => []])->patch($this->lockUrl(), ['locked' => false])->assertRedirect(route('admin.webinars.index'));
        $this->actingAs($this->manager)->get(route('admin.webinars.show', $this->webinar))->assertOk();
        $this->manager->assignedWebinars()->updateExistingPivot($this->webinar->id, ['manage_participants' => false]);
        $this->get(route('admin.participants.index', $this->webinar))->assertForbidden();
    }

    public function test_locked_content_is_absent_from_non_admin_dashboard_and_minimal_in_webinar_list(): void
    {
        $person = $this->webinar->participants()->create(['full_name' => 'Fixture person', 'email' => 'person@example.test', 'verified_at' => now()]);
        $this->actingAs($this->manager);
        $delivery = app(NotificationService::class)->queue($this->webinar, $person, 'certificate_test', $person->email, 'Secret delivery preview', '<p>Fixture</p>');
        $delivery->forceFill(['status' => 'sent', 'sent_at' => now()])->save();
        $this->lock();
        $this->actingAs($this->manager)->get(route('admin.dashboard'))->assertOk()->assertDontSee('Secret delivery preview')->assertDontSee($this->webinar->title);
        $this->get(route('admin.webinars.index'))->assertOk()->assertSee($this->webinar->title)->assertSee('Admin locked')
            ->assertDontSee($this->webinar->description)->assertDontSee('href="'.route('admin.webinars.show', $this->webinar).'"', false)
            ->assertDontSee('Lock webinar')->assertDontSee('Unlock webinar');
        $this->getJson(route('admin.webinars.show', $this->webinar))->assertStatus(423);
        $this->get(route('admin.webinars.show', $this->webinar), ['X-Frontend-Navigation' => '1'])->assertStatus(423);
        $this->get(route('admin.eligibility.select', ['webinar' => $this->webinar->id]))->assertStatus(423);
    }

    public function test_lock_does_not_change_public_form_availability_or_ordinary_webinar_permissions(): void
    {
        $form = $this->webinar->forms()->first();
        $this->lock();
        auth()->logout();
        $this->get(route('forms.public', $form->public_token))->assertOk()->assertSee('Public registration');
        $policy = app(WebinarPolicy::class);
        $this->assertFalse($policy->manage($this->manager, $this->webinar->fresh()));
        $this->assertFalse($policy->manage($this->owner, $this->webinar->fresh()));
        $this->assertTrue($policy->manage($this->admin, $this->webinar->fresh()));
        $this->actingAs($this->admin)->patch($this->lockUrl(), ['locked' => false])->assertRedirect();
        $this->assertTrue($policy->manage($this->owner, $this->webinar->fresh()));
        $this->assertTrue($policy->permission($this->manager, $this->webinar->fresh(), 'send_emails'));
    }

    public function test_lock_revokes_previously_queued_manager_certificate_and_email_authority(): void
    {
        $person = $this->webinar->participants()->create(['full_name' => 'Queued person', 'email' => 'queued@example.test', 'verified_at' => now()]);
        $this->actingAs($this->manager);
        $certificate = app(CertificateService::class)->queue($person);
        $delivery = app(NotificationService::class)->queue($this->webinar, $person, 'certificate_test', $person->email, 'Queued fixture', '<p>Fixture</p>');
        $this->lock();
        auth()->logout();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldNotReceive('send');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('cancelled', $delivery->fresh()->status);
        try {
            (new IssueSelectedCertificate($person->id))->handle(app(CertificateService::class));
            $this->fail('A locked webinar must reject the non-admin requester.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no longer has permission', $exception->getMessage());
        }
        $this->assertNull($certificate->fresh()->issued_at);
    }
}
