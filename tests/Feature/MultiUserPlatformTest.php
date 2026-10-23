<?php

namespace Tests\Feature;

use App\Jobs\SendTransactionalEmail;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Models\Webinar;
use App\Services\AccountService;
use App\Services\Email\BrevoTransactionalMailer;
use App\Services\Email\PermanentDeliveryException;
use App\Services\PendingSensitiveAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

class MultiUserPlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
    }

    private function member(array $attributes = []): User
    {
        return User::factory()->create(['role' => User::USER_ROLE, ...$attributes]);
    }

    private function webinar(User $owner, array $attributes = []): Webinar
    {
        return Webinar::query()->create(['title' => 'Private webinar '.$owner->id, 'slug' => (string) Str::uuid(), 'status' => 'draft', 'timezone' => 'Asia/Manila', 'created_by' => $owner->id, ...$attributes]);
    }

    private function invitationUrl(string $type): string
    {
        $delivery = EmailDelivery::query()->where('type', $type)->latest('id')->firstOrFail();
        preg_match('/href="([^"]+)"/', $delivery->payload['html'], $match);

        return html_entity_decode($match[1]);
    }

    private function verifyInvitation(string $url)
    {
        parse_str(parse_url($url, PHP_URL_FRAGMENT), $data);

        return $this->post(explode('#', $url)[0], $data);
    }

    public function test_registration_verification_approval_password_setup_and_login(): void
    {
        $admin = User::factory()->create();
        $this->post(route('account.store'), ['name' => 'Student Owner', 'email' => ' STUDENT@example.test ', 'role' => 'administrator', 'is_active' => true])->assertRedirect(route('account.status'));
        $user = User::query()->where('email', 'student@example.test')->firstOrFail();
        $this->assertSame(User::USER_ROLE, $user->role);
        $this->assertSame('pending_approval', $user->approval_status);
        $this->assertFalse($user->is_active);
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk()->assertSee('Student Owner')->assertSee('Pending request');
        $this->post(route('admin.users.update', $user), ['action' => 'approve'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $user->fresh()->approval_status);
        $this->assertFalse($user->fresh()->canAccessPlatform());
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('certificate_batches', 0);
        Queue::assertPushed(SendTransactionalEmail::class, fn ($job) => $job->queue === 'account-emails');
        auth()->logout();
        $url = $this->invitationUrl('account_setup');
        $this->get($url)->assertOk()->assertSee('Set your password');
        parse_str(parse_url($url, PHP_URL_FRAGMENT), $query);
        $token = $query['token'];
        $data = ['email' => $query['email'], 'token' => $token, 'password' => 'A long student passphrase!', 'password_confirmation' => 'A long student passphrase!'];
        $this->post(route('password.update'), $data)->assertRedirect(route('login'));
        $this->assertTrue($user->fresh()->canAccessPlatform());
        $this->post(route('password.update'), $data)->assertSessionHasErrors();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => $data['password']])->assertRedirect(route('admin.dashboard'));
        $this->get(route('admin.dashboard'))->assertOk()->assertDontSee('User Management');
    }

    public function test_repeated_registration_is_neutral_and_does_not_replace_name_or_send_repeated_email(): void
    {
        $data = ['name' => 'Original Owner', 'email' => 'same@example.test'];
        for ($i = 0; $i < 5; $i++) {
            $this->travel(61)->seconds();
            $this->post(route('account.store'), [...$data, 'name' => $i ? 'Attacker' : 'Original Owner'])->assertRedirect();
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertDatabaseHas('users', ['name' => 'Original Owner', 'approval_status' => 'pending_approval']);
        $this->post(route('account.store'), $data)->assertStatus(429);
    }

    public function test_expired_verification_and_setup_links_cannot_activate_accounts(): void
    {
        $legacy = $this->member(['approval_status' => 'pending_verification', 'is_active' => false, 'password_setup_at' => null]);
        $legacy->forceFill(['verification_token_hash' => hash('sha256', str_repeat('a', 64)), 'verification_expires_at' => now()->subMinute()])->save();
        $this->post(route('account.verify.consume', $legacy), ['token' => str_repeat('a', 64)])->assertForbidden();
        $user = $this->member(['password_setup_at' => null]);
        app(AccountService::class)->invite($user, true);
        $setup = $this->invitationUrl('account_setup');
        $this->travel(61)->minutes();
        parse_str(parse_url($setup, PHP_URL_FRAGMENT), $query);
        $this->post(route('password.update'), [...$query, 'password' => 'Expired setup passphrase!', 'password_confirmation' => 'Expired setup passphrase!'])->assertSessionHasErrors();
    }

    public function test_every_webinar_management_route_rejects_another_owner_including_unscoped_certificate_routes(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $webinar = $this->webinar($owner);
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Registration', 'status' => 'draft']);
        $this->actingAs($other);
        $checked = 0;
        foreach (Route::getRoutes() as $route) {
            if (! str_starts_with($route->getName() ?? '', 'admin.') || ! in_array('webinar', $route->parameterNames(), true)
                || str_contains($route->uri(), 'webinar-access')) {
                continue;
            }
            $params = [];
            foreach ($route->parameterNames() as $parameter) {
                $params[$parameter] = match ($parameter) {
                    'webinar' => $webinar->id, 'form' => $form->id, default => 987654
                };
            }
            // Each ownership check gets its own rate-limit window.
            $this->travel(61)->seconds();
            $response = $this->call($route->methods()[0], route($route->getName(), $params), []);
            if ($route->getName() === 'admin.webinars.lock') {
                $response->assertForbidden();
            } else {
                $response->assertNotFound();
            }
            $checked++;
        }
        $this->assertGreaterThan(40, $checked);
        // Start a fresh rate-limit window after enumerating every sensitive route.
        // Otherwise adding a route can mask the ownership assertion with HTTP 429.
        $this->travel(61)->seconds();
        $template = $webinar->certificateTemplates()->create(['name' => 'Synthetic template', 'storage_disk' => 'local', 'is_active' => true, 'layout' => []]);
        $certificate = Certificate::query()->create(['webinar_id' => $webinar->id, 'certificate_template_id' => $template->id, 'participant_id' => $webinar->participants()->create(['full_name' => 'Participant', 'email' => 'participant@example.test'])->id, 'verification_code' => 'CERT-'.Str::upper(Str::random(20)), 'status' => 'issued', 'issued_at' => now(), 'storage_disk' => 'local']);
        foreach (['download' => 'GET', 'resend' => 'POST', 'revoke' => 'POST'] as $action => $method) {
            $this->call($method, route('admin.certificates.'.$action, $certificate))->assertNotFound();
        }
    }

    public function test_dashboard_lists_and_admin_controls_are_isolated(): void
    {
        $member = $this->member();
        $other = $this->member();
        $mine = $this->webinar($member, ['title' => 'My classroom webinar']);
        $theirs = $this->webinar($other, ['title' => 'Other private webinar']);
        $this->actingAs($member)->get(route('admin.webinars.index', ['owner' => $other->id]))->assertOk()->assertSee($mine->title)->assertDontSee($theirs->title);
        $this->get(route('admin.dashboard'))->assertOk()->assertSee($mine->title)->assertDontSee($theirs->title);
        $this->get(route('admin.users.index'))->assertForbidden();
        $this->get(route('admin.email-logs.index'))->assertForbidden();
        $this->post(route('admin.users.update', $other), ['action' => 'suspend'])->assertForbidden();
        $this->put(route('admin.profile.update'), ['name' => 'Updated Member', 'role' => 'administrator', 'email' => $other->email])->assertRedirect();
        $this->assertSame('user', $member->fresh()->role);
        $this->assertNotSame($other->email, $member->fresh()->email);
    }

    public function test_webinar_passwords_are_secondary_independent_session_grants_and_public_forms_stay_public(): void
    {
        $member = $this->member();
        $one = $this->webinar($member, ['status' => 'published', 'ends_at' => now()->addDay(), 'management_password' => Hash::make('Webinar secret phrase'), 'management_lock_version' => (string) Str::uuid(), 'requires_verification' => false]);
        $two = $this->webinar($member, ['management_password' => $one->management_password, 'management_lock_version' => (string) Str::uuid()]);
        $form = $one->forms()->create(['type' => 'registration', 'title' => 'Public Registration', 'status' => 'published']);
        $this->get($form->shareUrl())->assertOk();
        $this->actingAs($member)->get(route('admin.forms.edit', [$one, $form]))->assertRedirect(route('admin.webinars.unlock', $one));
        $this->post(route('admin.webinars.unlock.store', $one), ['password' => 'incorrect'])->assertSessionHasErrors();
        $this->post(route('admin.webinars.unlock.store', $one), ['password' => 'Webinar secret phrase'])->assertRedirect(route('admin.webinars.show', $one));
        $this->get(route('admin.forms.edit', [$one, $form]))->assertOk();
        $this->get(route('admin.webinars.show', $two))->assertRedirect(route('admin.webinars.unlock', $two));
        $one->update(['management_lock_version' => (string) Str::uuid()]);
        $this->get(route('admin.forms.edit', [$one, $form]))->assertRedirect(route('admin.webinars.unlock', $one));
        $other = $this->member();
        $this->actingAs($other)->post(route('admin.webinars.unlock.store', $one), ['password' => 'Webinar secret phrase'])->assertNotFound();
    }

    public function test_unlock_rate_limiting_and_owner_password_management(): void
    {
        $user = $this->member();
        $webinar = $this->webinar($user);
        $this->actingAs($user)->put(route('admin.webinars.protection', $webinar), ['enabled' => 1, 'password' => 'Additional secret phrase', 'password_confirmation' => 'Additional secret phrase'])->assertRedirect();
        $this->assertTrue(Hash::check('Additional secret phrase', $webinar->fresh()->management_password));
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.webinars.unlock.store', $webinar), ['password' => 'wrong'])->assertSessionHasErrors();
        }
        $this->post(route('admin.webinars.unlock.store', $webinar), ['password' => 'wrong'])->assertStatus(429);
        $this->travel(2)->minutes();
        $this->post(route('admin.webinars.unlock.store', $webinar), ['password' => 'Additional secret phrase']);
        $version = $webinar->fresh()->management_lock_version;
        $this->put(route('admin.webinars.protection', $webinar), ['enabled' => 0])->assertRedirect();
        $this->assertNull($webinar->fresh()->management_password);
        $this->assertNotSame($version, $webinar->fresh()->management_lock_version);
    }

    public function test_suspension_revokes_sessions_recovery_and_pending_setup_without_deleting_content(): void
    {
        $member = $this->member();
        $webinar = $this->webinar($member);
        $token = Password::createToken($member);
        $this->actingAs($member)->get(route('admin.dashboard'))->assertOk();
        app(AccountService::class)->transition($member, 'suspend');
        $this->get(route('admin.webinars.show', $webinar))->assertRedirect(route('login'));
        $this->post(route('password.update'), ['email' => $member->email, 'token' => $token, 'password' => 'Recovery cannot bypass!', 'password_confirmation' => 'Recovery cannot bypass!'])->assertSessionHasErrors();
        $this->assertDatabaseHas('webinars', ['id' => $webinar->id, 'created_by' => $member->id]);
        $this->assertFalse($member->fresh()->is_active);
        $this->post(route('password.email'), ['email' => $member->email])->assertRedirect();
        $this->assertDatabaseCount('password_reset_tokens', 0);
    }

    public function test_rejection_and_final_administrator_safeguard(): void
    {
        $admin = User::factory()->create();
        $pending = $this->member(['approval_status' => 'pending_approval', 'is_active' => false, 'password_setup_at' => null]);
        $this->actingAs($admin)->post(route('admin.users.update', $pending), ['action' => 'reject'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $pending->fresh()->approval_status);
        $this->post(route('admin.users.update', $admin), ['action' => 'suspend'])->assertForbidden();
        $this->assertTrue($admin->fresh()->isAdministrator());
    }

    public function test_admin_access_is_explicit_and_audited_without_knowing_webinar_password(): void
    {
        $admin = User::factory()->create();
        $webinar = $this->webinar($this->member(), ['management_password' => Hash::make('Unknown to administrator')]);
        $this->actingAs($admin)->get(route('admin.webinars.show', $webinar))->assertRedirect(route('admin.webinars.administrative-access', $webinar));
        $this->post(route('admin.webinars.administrative-access.store', $webinar), ['confirmed' => 1])->assertRedirect(route('admin.webinars.show', $webinar));
        $this->get(route('admin.webinars.show', $webinar))->assertOk();
        $this->assertTrue(AuditLog::query()->where('action', 'administrator.webinar_authorized')->where('user_id', $admin->id)->exists());
        $this->assertTrue(AuditLog::query()->where('action', 'administrator.webinar_access')->exists());
    }

    public function test_provider_quota_exhaustion_pauses_sending_and_permanent_errors_are_not_retried(): void
    {
        config(['services.brevo.key' => 'synthetic-key', 'services.brevo.base_url' => 'https://api.brevo.com/v3']);
        Http::fake(['*' => Http::response(['code' => 'not_enough_credits'], 402)]);
        try {
            app(BrevoTransactionalMailer::class)->send('owner@example.test', 'Synthetic', '<p>Test</p>');
            $this->fail('Quota exhaustion must fail explicitly.');
        } catch (PermanentDeliveryException $exception) {
            $this->assertStringContainsString('credits', $exception->getMessage());
        }
        Http::assertSentCount(1);
        $this->assertTrue(Cache::has('transactional-email-paused'));
    }

    public function test_owner_creation_assigns_ownership_ignores_forged_security_fields_and_defaults_to_no_password(): void
    {
        $owner = $this->member();
        $other = $this->member();
        $this->actingAs($owner)->post(route('admin.webinars.store'), [
            'title' => 'Owner-created webinar', 'status' => 'draft', 'data_retention_days' => 30,
            'created_by' => $other->id, 'management_password' => 'untrusted-input',
        ])->assertRedirect();
        $webinar = Webinar::query()->firstOrFail();
        $this->assertSame($owner->id, $webinar->created_by);
        $this->assertNull($webinar->management_password);
        $this->assertCount(4, $webinar->forms);
        $this->get(route('admin.webinars.show', $webinar))->assertOk();
    }

    public function test_account_approval_resumes_after_password_confirmation_and_password_edits_are_not_retained(): void
    {
        config(['security.require_sensitive_action_password_confirmation' => true]);
        $admin = User::factory()->create();
        $pending = $this->member(['approval_status' => 'pending_approval', 'password_setup_at' => null, 'is_active' => false]);
        $this->actingAs($admin)->get(route('admin.users.index'))->assertOk();
        $this->post(route('admin.users.update', $pending), ['action' => 'approve'])->assertRedirect(route('admin.password.confirm'));
        $this->assertSame('pending_approval', $pending->fresh()->approval_status);
        $this->post(route('admin.password.confirm.store'), ['password' => 'password'])->assertRedirect(route('admin.users.index', ['status' => 'approved']));
        $this->assertSame('approved', $pending->fresh()->approval_status);
        $webinar = $this->webinar($admin);
        $this->withSession(['auth.password_confirmed_at' => 0, PendingSensitiveAction::SESSION_KEY => ['stale' => true]])
            ->put(route('admin.webinars.protection', $webinar), ['enabled' => 1, 'password' => 'New management passphrase!', 'password_confirmation' => 'New management passphrase!'])
            ->assertRedirect(route('admin.password.confirm'));
        $this->assertNull(session(PendingSensitiveAction::SESSION_KEY));
        $this->assertNull($webinar->fresh()->management_password);
    }
}
