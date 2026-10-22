<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\IssueSelectedCertificate;
use App\Jobs\SendTransactionalEmail;
use App\Models\AuditLog;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Webinar;
use App\Services\AccountService;
use App\Services\AuditService;
use App\Services\CertificateService;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;
use Tests\TestCase;

class InvitationsAndAuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->admin = User::factory()->create(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()]);
        $this->webinar = Webinar::query()->create(['title' => 'Assigned event', 'slug' => Str::uuid(), 'status' => 'published', 'created_by' => $this->admin->id, 'ends_at' => now()->addDay(), 'data_retention_days' => 30]);
    }

    private function data(array $extra = []): array
    {
        return $extra + ['name' => 'Invited groupmate', 'email' => 'groupmate@example.test', 'role' => User::MANAGER_ROLE,
            'webinar_ids' => [$this->webinar->id], 'manage_participants' => true, 'manage_certificates' => true, 'send_emails' => true,
            'password' => 'password', 'code' => app(Google2FA::class)->getCurrentOtp($this->admin->two_factor_secret)];
    }

    private function invitation(User $user): array
    {
        $delivery = EmailDelivery::query()->where('account_user_id', $user->id)->where('type', 'account_setup')->latest('id')->firstOrFail();
        preg_match('/href="([^"]+)"/', $delivery->payload['html'], $match);
        parse_str(parse_url(html_entity_decode($match[1]), PHP_URL_FRAGMENT), $values);

        return $values;
    }

    private function activate(User $user): void
    {
        auth()->logout();
        $this->post(route('password.update'), $this->invitation($user) + ['password' => 'Personal groupmate passphrase!', 'password_confirmation' => 'Personal groupmate passphrase!'])
            ->assertRedirect(route('login'));
    }

    private function invite(): User
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), $this->data())->assertSessionHasNoErrors()->assertRedirect(route('admin.users.index', ['status' => 'invited']));

        return User::query()->where('email', 'groupmate@example.test')->firstOrFail();
    }

    public function test_admin_invitation_uses_shared_activation_without_approval_or_known_password(): void
    {
        $user = $this->invite();
        $this->assertSame('approved', $user->approval_status);
        $this->assertSame('admin_invitation', $user->account_source);
        $this->assertFalse($user->is_active);
        $this->assertFalse($user->canAccessPlatform());
        $this->assertNull($user->email_verified_at);
        $this->assertFalse(Hash::check('password', $user->password));
        $this->assertSame($this->admin->id, $this->webinar->fresh()->created_by);
        $this->assertSame(1, User::query()->where('role', 'administrator')->count());
        $this->get(route('admin.users.index', ['status' => 'invited']))->assertOk()->assertSee('Pending activation')->assertSee('Queued')->assertSee('Assigned event');
        $this->activate($user);
        $this->assertTrue($user->fresh()->canAccessPlatform());
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertNull($user->fresh()->two_factor_secret);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Personal groupmate passphrase!'])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk()->assertSee('Assigned event')->assertDontSee('User Management')->assertDontSee('Audit Trail');
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.invited', 'actor_user_id' => $this->admin->id, 'auditable_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.activated', 'actor_user_id' => $user->id]);
    }

    public function test_public_requests_are_visible_before_approval_and_activate_by_email_afterwards(): void
    {
        $this->post(route('account.store'), ['name' => 'Public requester', 'email' => ' PUBLIC@example.test ', 'role' => 'administrator'])->assertSessionHas('success');
        $user = User::query()->where('email', 'public@example.test')->sole();
        $this->assertSame('user', $user->role);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->actingAs($this->admin)->get(route('admin.users.index'))->assertSee('Public requester')->assertSee('Pending request');
        $this->post(route('admin.users.update', $user), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->assertFalse($user->fresh()->canAccessPlatform());
        $this->assertNull($user->fresh()->email_verified_at);
        $this->activate($user);
        $this->assertTrue($user->fresh()->canAccessPlatform());
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.requested', 'actor_user_id' => null, 'auditable_id' => $user->id]);
    }

    public function test_unavailable_transport_rolls_back_invitation_and_preserves_public_request(): void
    {
        config(['accounts.email_provider' => 'brevo', 'services.brevo.key' => '']);
        $this->actingAs($this->admin)->post(route('admin.users.store'), $this->data())->assertSessionHasErrors('account');
        $this->assertDatabaseCount('users', 1);
        $pending = app(AccountService::class)->request('Pending person', 'pending@example.test');
        $this->post(route('admin.users.update', $pending), ['action' => 'approve'])->assertSessionHasErrors('account');
        $this->assertSame('pending_approval', $pending->fresh()->approval_status);
        $this->assertDatabaseCount('email_deliveries', 0);
    }

    public function test_duplicate_invites_and_administrator_role_injection_are_rejected(): void
    {
        $user = $this->invite();
        $before = $user->getAttributes();
        $this->post(route('admin.users.store'), $this->data(['email' => ' GROUPMATE@example.test ', 'name' => 'Different name']))->assertSessionHasErrors('email');
        $this->post(route('admin.users.store'), $this->data(['email' => 'other@example.test', 'role' => 'administrator']))->assertSessionHasErrors('role');
        $this->assertDatabaseCount('users', 2);
        $this->assertSame($before, $user->fresh()->getAttributes());
        $this->assertDatabaseCount('email_deliveries', 1);
    }

    public function test_invitation_resend_invalidates_old_link_without_creating_an_account(): void
    {
        $user = $this->invite();
        $first = $this->invitation($user);
        $this->travel(3)->minutes();
        $this->post(route('admin.users.update', $user), ['action' => 'resend'])->assertSessionHasNoErrors();
        $second = $this->invitation($user);
        $this->assertNotSame($first['token'], $second['token']);
        $this->assertFalse(Password::tokenExists($user, $first['token']));
        $this->assertTrue(Password::tokenExists($user, $second['token']));
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('email_deliveries', 2);
    }

    public function test_revoked_expired_forged_and_used_links_cannot_activate(): void
    {
        $user = $this->invite();
        $link = $this->invitation($user);
        $this->post(route('admin.users.update', $user), ['action' => 'revoke'])->assertSessionHasNoErrors();
        $this->assertSame('invitation_revoked', $user->fresh()->approval_status);
        auth()->logout();
        $body = $link + ['password' => 'Personal groupmate passphrase!', 'password_confirmation' => 'Personal groupmate passphrase!'];
        $this->post(route('password.update'), $body)->assertSessionHasErrors();
        $this->actingAs($this->admin)->post(route('admin.users.update', $user), ['action' => 'reinvite'])->assertSessionHasNoErrors();
        $newLink = $this->invitation($user);
        auth()->logout();
        $this->post(route('password.update'), array_replace($body, ['token' => str_repeat('x', 64)]))->assertSessionHasErrors();
        $this->travel(61)->minutes();
        $this->post(route('password.update'), array_replace($body, $newLink))->assertSessionHasErrors();
        $this->actingAs($this->admin)->post(route('admin.users.update', $user), ['action' => 'resend'])->assertSessionHasNoErrors();
        $final = $this->invitation($user);
        $this->activate($user);
        $this->post(route('password.update'), array_replace($body, $final))->assertSessionHasErrors();
        $this->assertTrue($user->fresh()->canAccessPlatform());
    }

    public function test_manager_access_is_assignment_scoped_and_cannot_create_or_manage_accounts(): void
    {
        $manager = $this->invite();
        $this->activate($manager);
        $unrelated = Webinar::query()->create(['title' => 'Unrelated confidential event', 'slug' => Str::uuid(), 'created_by' => $this->admin->id]);
        $this->actingAs($manager->fresh())->get(route('admin.webinars.index'))->assertSee('Assigned event')->assertDontSee('Unrelated confidential event')->assertDontSee('New webinar');
        $this->get(route('admin.webinars.show', $this->webinar))->assertOk();
        foreach (['admin.webinars.show', 'admin.webinars.reports', 'admin.webinars.documents.index', 'admin.webinars.messages.index', 'admin.certificates.studio'] as $route) {
            $this->get(route($route, $unrelated))->assertNotFound();
        }
        $this->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.audit.index'))->assertForbidden();
        $this->post(route('admin.users.store'), $this->data())->assertForbidden();
        $this->put(route('admin.users.access', $manager), $this->data(['role' => 'administrator']))->assertForbidden();
        $this->get(route('admin.webinars.create'))->assertForbidden();
        $this->put(route('admin.webinars.update', $this->webinar), ['title' => 'Changed'])->assertForbidden();
        $this->delete(route('admin.webinars.destroy', $this->webinar))->assertForbidden();
        $this->assertSame('Assigned event', $this->webinar->fresh()->title);
    }

    public function test_view_only_assignment_does_not_expose_participant_or_email_data(): void
    {
        $manager = $this->invite();
        $this->activate($manager);
        $this->webinar->participants()->create(['full_name' => 'Sensitive participant name', 'email' => 'private-participant@example.test']);
        $manager->assignedWebinars()->updateExistingPivot($this->webinar->id, ['manage_participants' => false, 'manage_certificates' => false, 'send_emails' => false]);
        $this->actingAs($manager->fresh())->get(route('admin.webinars.show', $this->webinar))->assertOk()->assertDontSee('Sensitive participant name')->assertDontSee('private-participant@example.test')->assertDontSee('Form Responses')->assertDontSee('Certificate Studio');
        foreach (['admin.responses.index', 'admin.participants.export', 'admin.webinars.email-logs.index', 'admin.webinars.documents.index', 'admin.certification.edit', 'admin.webinars.messages.index'] as $route) {
            $this->get(route($route, $this->webinar))->assertForbidden();
        }
        $this->post(route('admin.certificates.issue-selected', $this->webinar), ['send_email' => false])->assertForbidden();
    }

    public function test_edit_access_requires_admin_factor_and_never_changes_ownership_or_admin_role(): void
    {
        $manager = $this->invite();
        $this->travel(31)->seconds();
        $bad = $this->data(['webinar_ids' => [], 'role' => 'user']);
        unset($bad['code']);
        $this->put(route('admin.users.access', $manager), $bad)->assertSessionHasErrors('code');
        $this->assertSame('webinar_manager', $manager->fresh()->role);
        $newCode = app(Google2FA::class)->oathTotp($this->admin->two_factor_secret, app(Google2FA::class)->getTimestamp() + 1);
        $this->put(route('admin.users.access', $manager), $this->data(['webinar_ids' => [], 'role' => 'user', 'code' => $newCode]))->assertSessionHasNoErrors();
        $this->assertSame('user', $manager->fresh()->role);
        $this->assertCount(0, $manager->fresh()->assignedWebinars);
        $this->assertSame($this->admin->id, $this->webinar->fresh()->created_by);
        $this->put(route('admin.users.access', $this->admin), $this->data(['role' => 'user']))->assertForbidden();
        $this->assertSame('administrator', $this->admin->fresh()->role);
        $this->assertDatabaseHas('audit_logs', ['action' => 'account.access_updated', 'actor_user_id' => $this->admin->id, 'auditable_id' => $manager->id]);
    }

    public function test_disabling_revokes_sessions_and_password_tokens_while_preserving_records(): void
    {
        $manager = $this->invite();
        $this->activate($manager);
        $token = Password::createToken($manager);
        $before = $this->webinar->fresh()->getAttributes();
        $this->actingAs($this->admin)->post(route('admin.users.update', $manager), ['action' => 'suspend'])->assertSessionHasNoErrors();
        $this->assertFalse($manager->fresh()->canAccessPlatform());
        $this->assertFalse(Password::tokenExists($manager, $token));
        $this->actingAs($manager->fresh())->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->assertSame($before, $this->webinar->fresh()->getAttributes());
        $this->assertDatabaseCount('webinar_user', 1);
    }

    public function test_removed_access_cancels_queued_email_before_transport(): void
    {
        $manager = $this->invite();
        $this->activate($manager);
        $this->actingAs($manager->fresh());
        $participant = $this->webinar->participants()->create(['full_name' => 'Synthetic participant', 'email' => 'recipient@example.test', 'verified_at' => now()]);
        $delivery = app(NotificationService::class)->queue($this->webinar, $participant, 'certificate_test', $participant->email, 'Synthetic test', '<p>Synthetic</p>');
        $this->assertSame($manager->id, $delivery->initiated_by);
        $manager->assignedWebinars()->detach();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldNotReceive('send');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('cancelled', $delivery->fresh()->status);
    }

    public function test_removed_access_blocks_a_previously_queued_certificate(): void
    {
        $manager = $this->invite();
        $this->activate($manager);
        $participant = $this->webinar->participants()->create(['full_name' => 'Synthetic participant', 'email' => 'recipient@example.test', 'verified_at' => now()]);
        $this->webinar->certificateTemplates()->create(['name' => 'Fixture', 'is_active' => true, 'background_path' => 'synthetic.png', 'storage_disk' => 'local', 'layout' => []]);
        $this->actingAs($manager->fresh());
        $certificate = app(CertificateService::class)->queue($participant);
        $this->assertSame($manager->id, $certificate->requested_by);
        auth()->logout();
        $manager->assignedWebinars()->detach();
        try {
            (new IssueSelectedCertificate($participant->id))->handle(app(CertificateService::class));
            $this->fail('Revoked requester must not issue.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('no longer has permission', $exception->getMessage());
        }
        $this->assertNull($certificate->fresh()->issued_at);
    }

    public function test_audit_attribution_is_encrypted_permanent_and_admin_only_with_filters(): void
    {
        $request = Request::create('https://localhost/admin');
        $request->setUserResolver(fn () => $this->admin);
        app(AuditService::class)->record($request, 'webinar.synthetic_changed', $this->webinar, ['before' => ['status' => 'draft'], 'after' => ['status' => 'published'], 'password' => 'never store this']);
        $log = AuditLog::query()->latest('id')->firstOrFail();
        $originalName = $this->admin->name;
        $this->assertSame($this->admin->id, $log->actor_user_id);
        $this->assertSame($this->webinar->id, $log->webinar_id);
        $this->assertNotSame($originalName, DB::table('audit_logs')->where('id', $log->id)->value('actor_name'));
        $this->assertSame('[redacted]', $log->metadata['password']);
        $this->admin->update(['name' => 'Renamed administrator']);
        $this->assertSame($originalName, $log->fresh()->actor_name);
        $this->actingAs($this->admin->fresh())->get(route('admin.audit.index', ['actor' => $this->admin->id, 'action' => 'synthetic_changed', 'webinar' => $this->webinar->id, 'from' => now()->timezone('Asia/Manila')->format('Y-m-d'), 'to' => now()->timezone('Asia/Manila')->format('Y-m-d'), 'outcome' => 'success']))
            ->assertOk()->assertSee('webinar.synthetic_changed')->assertSee($originalName)->assertDontSee('never store this');
        $this->get(route('admin.audit.index', ['action' => 'does_not_exist']))->assertOk()->assertDontSee('webinar.synthetic_changed');
        $this->get(route('admin.audit.index', ['to' => now()->addDay()->timezone('Asia/Manila')->format('Y-m-d')]))->assertOk();
        $this->expectException(\LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_actor_id_and_snapshot_survive_account_removal_and_failures_are_audited(): void
    {
        $member = User::factory()->create(['role' => 'user']);
        $request = Request::create('https://localhost/admin');
        $request->setUserResolver(fn () => $member);
        app(AuditService::class)->record($request, 'profile.synthetic_changed', $member);
        $log = AuditLog::query()->latest('id')->firstOrFail();
        $id = $member->id;
        $name = $member->name;
        $member->delete();
        $this->assertNull($log->fresh()->user_id);
        $this->assertSame($id, $log->fresh()->actor_user_id);
        $this->assertSame($name, $log->fresh()->actor_name);
        $this->actingAs($this->admin)->post(route('admin.users.store'), $this->data(['code' => 'invalid']))->assertSessionHasErrors();
        $this->assertDatabaseHas('audit_logs', ['actor_user_id' => $this->admin->id, 'action' => 'operation.failed', 'outcome' => 'failure']);
    }
}
