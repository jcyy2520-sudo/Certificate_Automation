<?php

namespace Tests\Feature;

use App\Http\Controllers\TwoFactorChallengeController;
use App\Models\AuditLog;
use App\Models\Certificate;
use App\Models\CertificateTemplate;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\CertificateArtwork;
use App\Services\CertificateService;
use App\Services\CsvImportService;
use App\Services\ParticipantPrivacyService;
use App\Services\TwoFactorService;
use App\Support\SessionStorageSafety;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Events\Dispatcher;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Mockery;
use PragmaRX\Google2FA\Google2FA;
use RuntimeException;
use Tests\TestCase;

class SecurityRemediationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->webinar = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $this->webinar->forms()->create(['type' => 'registration', 'title' => 'Registration', 'status' => 'published']);
    }

    public function test_password_and_authorization_changes_invalidate_pending_mfa_without_consuming_the_factor(): void
    {
        foreach (['password', 'auth_version'] as $change) {
            $codes = app(TwoFactorService::class)->generateRecoveryCodes();
            $user = User::factory()->create([
                'password' => Hash::make('password'),
                'two_factor_secret' => app(Google2FA::class)->generateSecretKey(),
                'two_factor_confirmed_at' => now(),
                'two_factor_recovery_codes' => app(TwoFactorService::class)->hashRecoveryCodes($codes),
            ]);
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('two-factor.challenge'));
            $user->forceFill($change === 'password' ? ['password' => Hash::make('replacement-password')] : ['auth_version' => $user->auth_version + 1])->save();
            $this->post(route('two-factor.challenge.store'), ['recovery_code' => $codes[0]])->assertRedirect(route('login'));
            $this->assertGuest();
            $this->assertCount(10, $user->fresh()->two_factor_recovery_codes);
            $this->assertNull(session(TwoFactorChallengeController::PENDING_CREDENTIAL));
        }
    }

    public function test_a_missing_binding_and_an_expired_challenge_are_rejected(): void
    {
        $user = User::factory()->create(['two_factor_secret' => app(Google2FA::class)->generateSecretKey(), 'two_factor_confirmed_at' => now()]);
        $this->withSession([
            TwoFactorChallengeController::PENDING_USER => $user->id,
            TwoFactorChallengeController::PENDING_AT => now()->timestamp,
        ])->get(route('two-factor.challenge'))->assertRedirect(route('login'));
        $this->withSession([
            TwoFactorChallengeController::PENDING_USER => $user->id,
            TwoFactorChallengeController::PENDING_AT => now()->subMinutes(6)->timestamp,
            TwoFactorChallengeController::PENDING_CREDENTIAL => TwoFactorChallengeController::credentialBinding($user),
        ])->post(route('two-factor.challenge.store'), ['code' => '123456'])->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_oversized_raster_headers_are_rejected_without_replacing_artwork(): void
    {
        $template = $this->template();
        foreach ([[24000, 24000], [5000, 5000], [6001, 1]] as [$width, $height]) {
            $this->actingAs($this->owner)->put(route('admin.certification.template', $this->webinar), [
                'background' => UploadedFile::fake()->createWithContent('art.png', $this->png($width, $height)),
            ])->assertSessionHasErrors('background');
            $this->assertSame($template->background_path, $template->fresh()->background_path);
        }
        $this->assertCount(1, Storage::disk('local')->files('certificate-backgrounds'));
    }

    public function test_existing_over_budget_artwork_is_rejected_at_the_shared_renderer(): void
    {
        $template = $this->template();
        Storage::disk('local')->put($template->background_path, $this->png(5000, 5000));
        $certificate = new Certificate(['recipient_name' => 'Synthetic Participant']);
        $certificate->setRelation('template', $template)->setRelation('webinar', $this->webinar);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('12 megapixels');
        app(CertificateService::class)->render($certificate);
    }

    public function test_normal_png_and_jpeg_artwork_remain_supported(): void
    {
        $this->template();
        foreach (['png', 'jpg'] as $extension) {
            $file = UploadedFile::fake()->image('normal.'.$extension, 1600, 1100);
            $this->actingAs($this->owner)->put(route('admin.certification.template', $this->webinar), ['background' => $file])->assertSessionHasNoErrors();
            $this->assertSame(1600, $this->webinar->certificateTemplates()->first()->layout['bg_w']);
        }
        $this->assertSame('image/png', CertificateArtwork::inspect($this->png())['mime']);
    }

    public function test_participant_previews_resolve_only_live_recipients_of_the_authorized_webinar(): void
    {
        $this->template();
        $participant = $this->participant();
        $this->mock(CertificateService::class)->shouldReceive('render')->once()->withArgs(fn (Certificate $certificate) => $certificate->recipient_name === $participant->full_name)->andReturn('%PDF-synthetic');
        $url = route('admin.certification.preview', [$this->webinar, 'participant' => $participant->public_id]);
        $this->assertStringNotContainsString(urlencode($participant->full_name), $url);
        $this->actingAs($this->owner)->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $foreign = Webinar::factory()->create(['created_by' => $this->owner->id])->participants()->create(['full_name' => 'Other Webinar', 'email' => 'foreign@example.test']);
        $this->get(route('admin.certification.preview', [$this->webinar, 'participant' => $foreign->public_id]))->assertNotFound();
        $participant->update(['privacy_erased_at' => now()]);
        $this->get($url)->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => User::USER_ROLE]))->post(route('admin.certification.preview', $this->webinar), ['name' => 'Private Name'])->assertNotFound();
    }

    public function test_ad_hoc_preview_names_use_post_bodies_and_get_uses_a_synthetic_name(): void
    {
        $this->template();
        $this->mock(CertificateService::class)->shouldReceive('render')->twice()->withArgs(fn (Certificate $certificate) => in_array($certificate->recipient_name, ['Sample Participant', 'Typed Private Name'], true))->andReturn('%PDF-synthetic');
        $this->actingAs($this->owner)->get(route('admin.certification.preview', [$this->webinar, 'name' => 'Must Not Render']))->assertOk();
        $this->post(route('admin.certification.preview', $this->webinar), ['name' => 'Typed Private Name'])->assertOk();
        $this->assertDatabaseCount('certificates', 0);
    }

    public function test_selection_audits_do_not_store_emails_and_erasure_removes_legacy_copies(): void
    {
        $participant = $this->participant();
        $legacy = AuditLog::query()->create(['action' => 'certificate.issued_selection', 'auditable_type' => $this->webinar->getMorphClass(), 'auditable_id' => $this->webinar->id, 'metadata' => ['requested' => 1, 'skipped' => 1, 'skip_reasons' => [$participant->email.': already issued']]]);
        $this->mock(CertificateService::class)->shouldReceive('queue')->once()->andThrow(new RuntimeException('Synthetic failure'));
        $this->actingAs($this->owner)->post(route('admin.certificates.issue-selected', $this->webinar), ['participants' => [$participant->public_id], 'preview_confirmed' => '1'])->assertRedirect();
        $latest = AuditLog::query()->latest('id')->first();
        $this->assertStringNotContainsString($participant->email, json_encode($latest->metadata));
        app(ParticipantPrivacyService::class)->erase($participant);
        $this->assertSame(['requested' => 1, 'skipped' => 1], $legacy->fresh()->metadata);
        app(AuditService::class)->record(Request::create('/'), 'synthetic.redaction', null, ['nested' => ['skip_reasons' => ['private@example.test']]]);
        $this->assertStringNotContainsString('private@example.test', json_encode(AuditLog::query()->latest('id')->first()->metadata));
    }

    public function test_failed_cancellation_and_retention_remain_retryable(): void
    {
        $import = $this->pending();
        $disk = Storage::disk('local');
        $this->failDeletion();
        foreach (['cancel', 'retention'] as $operation) {
            try {
                $operation === 'cancel' ? app(CsvImportService::class)->cancel($import) : app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);
                $this->fail('Expected storage cleanup to fail.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('retained for retry', $exception->getMessage());
            }
            $this->assertSame(Import::STATUS_PENDING, $import->fresh()->status);
            $this->assertNull($import->fresh()->privacy_erased_at);
        }
        Storage::set('local', $disk);
        app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);
        $disk->assertMissing('imports-pending/import-'.$import->id.'.csv');
        $this->assertNotNull($import->fresh()->privacy_erased_at);
    }

    public function test_committed_import_cleanup_failure_does_not_roll_back_responses_and_is_retried(): void
    {
        $import = $this->pending();
        $disk = Storage::disk('local');
        $this->failDeletion($disk->path('imports-pending/import-'.$import->id.'.csv'));
        $counts = app(CsvImportService::class)->process($import, ['email' => 0, 'full_name' => 1], options: ['allow_import_time' => true, 'source_confirmed' => true, 'source_description' => 'Synthetic fixture']);
        $this->assertSame(1, $counts['valid']);
        $this->assertTrue($counts['cleanup_pending']);
        $this->assertSame(Import::STATUS_COMMITTED, $import->fresh()->status);
        $this->assertDatabaseCount('submissions', 1);
        Storage::set('local', $disk);
        app(CsvImportService::class)->pruneStalePending();
        $this->assertFalse($import->fresh()->column_map['cleanup_pending']);
        $disk->assertMissing('imports-pending/import-'.$import->id.'.csv');
        $this->assertDatabaseCount('submissions', 1);
    }

    public function test_failed_upload_cleanup_keeps_inventory_even_when_the_initial_write_fails(): void
    {
        $disk = $this->failDeletion();
        $disk->shouldReceive('put')->once()->andReturn(false);
        try {
            $this->pending();
            $this->fail('Expected the upload to fail.');
        } catch (RuntimeException) {
            $import = Import::query()->firstOrFail();
            $this->assertSame(Import::STATUS_FAILED, $import->status);
            $this->assertTrue($import->column_map['cleanup_pending']);
        }
    }

    public function test_webinar_cascade_waits_for_import_file_cleanup_and_leaves_other_webinars_alone(): void
    {
        $import = $this->pending();
        $disk = Storage::disk('local');
        $other = Webinar::factory()->create(['created_by' => $this->owner->id]);
        $this->failDeletion();
        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->owner)->delete(route('admin.webinars.destroy', $this->webinar), ['confirm' => $this->webinar->title]);
            $this->fail('Expected the deletion to stop.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('cleanup failed', $exception->getMessage());
        }
        $this->assertNotNull($this->webinar->fresh()->deletion_started_at);
        $this->assertTrue($import->fresh()->exists);
        Storage::set('local', $disk);
        $this->delete(route('admin.webinars.destroy', $this->webinar), ['confirm' => $this->webinar->title])->assertRedirect();
        $this->assertNull($this->webinar->fresh());
        $this->assertNotNull($other->fresh());
        $disk->assertMissing('imports-pending/import-'.$import->id.'.csv');
    }

    public function test_legacy_cancelled_and_erased_import_files_are_still_removed(): void
    {
        $import = $this->pending();
        $import->update(['status' => Import::STATUS_CANCELLED, 'privacy_erased_at' => now()]);
        app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar);
        Storage::disk('local')->assertMissing('imports-pending/import-'.$import->id.'.csv');
        $this->assertSame(['imports' => 0, 'issues' => 0], app(ParticipantPrivacyService::class)->eraseWebinarImports($this->webinar));
    }

    public function test_one_cleanup_failure_does_not_block_other_tenants_or_later_records(): void
    {
        $blocked = $this->pending();
        $later = $this->pending();
        foreach ([$blocked, $later] as $import) {
            $import->update(['status' => Import::STATUS_COMMITTED, 'column_map' => array_merge($import->column_map, ['cleanup_pending' => true])]);
        }
        $disk = Storage::disk('local');
        $failed = Mockery::mock(FilesystemAdapter::class);
        $blockedPath = 'imports-pending/import-'.$blocked->id.'.csv';
        $failed->shouldReceive('exists')->andReturnUsing(fn ($path) => $disk->exists($path));
        $failed->shouldReceive('delete')->andReturnUsing(fn ($path) => $path === $blockedPath ? false : $disk->delete($path));
        Storage::set('local', $failed);
        $otherOwner = User::factory()->create(['role' => User::USER_ROLE]);
        $other = Webinar::factory()->create(['created_by' => $otherOwner->id]);
        $this->actingAs($otherOwner)->get(route('admin.webinars.imports.index', $other))->assertOk();
        $this->artisan('imports:prune-stale')->assertFailed();
        $disk->assertExists($blockedPath);
        $disk->assertMissing('imports-pending/import-'.$later->id.'.csv');
        $this->assertTrue($blocked->fresh()->column_map['cleanup_pending']);
        $this->assertFalse($later->fresh()->column_map['cleanup_pending']);
    }

    public function test_a_commit_failure_after_source_storage_keeps_the_previously_committed_inventory(): void
    {
        // A separate in-memory connection exercises real top-level commits;
        // RefreshDatabase's surrounding test transaction otherwise hides them.
        $previous = DB::getDefaultConnection();
        config(['database.connections.security_inventory' => array_merge(config('database.connections.sqlite'), ['database' => ':memory:'])]);
        DB::setDefaultConnection('security_inventory');
        try {
            Artisan::call('migrate', ['--database' => 'security_inventory', '--force' => true]);
            $this->owner = User::factory()->create(['role' => User::USER_ROLE]);
            $this->webinar = Webinar::factory()->create(['created_by' => $this->owner->id]);
            $this->webinar->forms()->create(['type' => 'registration', 'title' => 'Registration', 'status' => 'published']);
            $connection = DB::connection();
            $events = new Dispatcher;
            $commits = 0;
            $events->listen(TransactionCommitting::class, function () use (&$commits): void {
                if (++$commits === 2) {
                    throw new RuntimeException('Synthetic commit failure after storage.');
                }
            });
            $connection->setEventDispatcher($events);
            try {
                $this->pending();
                $this->fail('Expected the second commit to fail.');
            } catch (RuntimeException $exception) {
                $this->assertSame('Synthetic commit failure after storage.', $exception->getMessage());
            }
            if ($connection->getPdo()->inTransaction()) {
                $connection->getPdo()->rollBack();
            }
            $import = Import::query()->sole();
            Storage::disk('local')->assertExists('imports-pending/import-'.$import->id.'.csv');
            $this->assertSame(Import::STATUS_PENDING, $import->status);
            $import->update(['created_at' => now()->subDays(2)]);
            app(CsvImportService::class)->pruneStalePending();
            Storage::disk('local')->assertMissing('imports-pending/import-'.$import->id.'.csv');
        } finally {
            DB::setDefaultConnection($previous);
            DB::purge('security_inventory');
        }
    }

    public function test_redis_url_path_and_query_overrides_block_flushing_before_connecting(): void
    {
        $redis = Mockery::mock();
        $redis->shouldNotReceive('connection');
        $this->app->instance('redis', $redis);
        foreach (['redis://redis.example/0', 'redis://redis.example/2?database=0', 'redis://unused.example/2?host=redis.example&port=6379&database=0', 'redis://redis.example/2?options[database]=0', 'redis://redis.example/2?options[host]=redis.example&options[port]=6379'] as $url) {
            $this->redisConfig($url);
            $this->assertFalse(SessionStorageSafety::usesDedicatedRedisDatabase());
            $this->artisan('admin:sessions:revoke', ['--force' => true])->assertFailed();
        }
    }

    public function test_legitimately_isolated_redis_urls_and_numeric_equivalents_are_resolved_consistently(): void
    {
        $this->redisConfig('rediss://redis.example/2');
        $this->assertTrue(SessionStorageSafety::usesDedicatedRedisDatabase());
        config(['database.redis.session.url' => 'redis://redis.example/2?database=00']);
        $this->assertFalse(SessionStorageSafety::usesDedicatedRedisDatabase());
        foreach (['redis://redis.example/2?database[]=2', 'redis://redis.example/2?port=-1', 'redis://redis.example:invalid/2'] as $invalid) {
            config(['database.redis.session.url' => $invalid]);
            $this->assertFalse(SessionStorageSafety::usesDedicatedRedisDatabase());
        }
        $this->redisConfig('redis://redis.example/2');
        config(['database.redis.session.url' => 'rediss://redis.example/0', 'database.redis.cache.host' => 'tls://redis.example']);
        $this->assertFalse(SessionStorageSafety::usesDedicatedRedisDatabase());
        $this->redisConfig('redis://redis.example/2');
        config(['database.redis.options.parameters' => ['database' => 0]]);
        $this->assertFalse(SessionStorageSafety::usesDedicatedRedisDatabase());
    }

    public function test_the_import_issue_migration_preserves_ciphertext_and_encrypts_legacy_json(): void
    {
        $import = $this->pending();
        $ids = [];
        $raw = ['Email Address' => 'fixture@example.test', 'Score' => '8/10'];
        $encrypted = Crypt::encryptString(json_encode($raw));
        foreach ([$encrypted, json_encode($encrypted), json_encode($raw)] as $stored) {
            $ids[] = DB::table('import_issues')->insertGetId(['import_id' => $import->id, 'webinar_id' => $this->webinar->id, 'row_number' => count($ids) + 1, 'issue_type' => 'unmatched_email', 'issue_message' => 'Synthetic fixture', 'raw_data' => $stored]);
        }
        $migration = require database_path('migrations/2026_10_21_000100_store_encrypted_import_issue_data_as_text.php');
        $migration->up();
        $this->assertSame($encrypted, DB::table('import_issues')->where('id', $ids[0])->value('raw_data'));
        foreach ($ids as $id) {
            $this->assertSame($raw, ImportIssue::query()->findOrFail($id)->raw_data);
        }
        $this->assertDatabaseCount('import_issues', 3);
    }

    private function redisConfig(string $url): void
    {
        config(['session.driver' => 'redis', 'session.connection' => 'session', 'cache.default' => 'array', 'cache.limiter' => 'array', 'queue.default' => 'sync', 'webinar.email.queue_connection' => 'sync', 'database.redis' => [
            'session' => ['url' => $url, 'host' => 'nominal.example', 'port' => 6379, 'database' => 2],
            'cache' => ['host' => 'redis.example', 'port' => 6379, 'database' => 0],
        ]]);
    }

    private function pending(): Import
    {
        $this->actingAs($this->owner);

        return app(CsvImportService::class)->storePending($this->webinar, $this->webinar->forms()->first(), UploadedFile::fake()->createWithContent('fixture.csv', "Email,Name\nfixture@example.test,Synthetic Participant\n"));
    }

    private function failDeletion(?string $path = null): mixed
    {
        $disk = Mockery::mock(FilesystemAdapter::class);
        $disk->shouldReceive('exists')->andReturn(true);
        $disk->shouldReceive('delete')->andReturn(false);
        if ($path) {
            $disk->shouldReceive('path')->andReturn($path);
        }
        Storage::set('local', $disk);

        return $disk;
    }

    private function participant(): Participant
    {
        return $this->webinar->participants()->create(['full_name' => 'Synthetic Private Name', 'email' => 'private@example.test']);
    }

    private function template(): CertificateTemplate
    {
        Storage::disk('local')->put('certificate-backgrounds/fixture.png', $this->png());

        return $this->webinar->certificateTemplates()->create(['name' => 'Synthetic design', 'is_active' => true, 'storage_disk' => 'local', 'background_path' => 'certificate-backgrounds/fixture.png', 'layout' => []]);
    }

    private function png(int $width = 1, int $height = 1): string
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII=');
        $png = substr_replace($png, pack('NN', $width, $height), 16, 8);

        return substr_replace($png, pack('N', crc32(substr($png, 12, 17))), 29, 4);
    }
}
