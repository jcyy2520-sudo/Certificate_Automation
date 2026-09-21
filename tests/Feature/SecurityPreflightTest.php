<?php

namespace Tests\Feature;

use App\Console\Commands\SecurityCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use ReflectionMethod;
use Tests\TestCase;

class SecurityPreflightTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_preflight_fails_closed_without_disclosing_credentials(): void
    {
        config([
            'app.url' => 'http://localhost',
            'app.debug' => false,
            'security.bootstrap_admin_password_present' => true,
            'services.brevo.key' => 'do-not-print-this-secret',
            'webinar.email.provider' => 'brevo',
        ]);

        $exit = Artisan::call('security:check', ['--production' => true]);
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL  APP_URL uses HTTPS', $output);
        $this->assertStringContainsString('FAIL  No bootstrap administrator password is retained', $output);
        $this->assertStringContainsString('FAIL  Production database is PostgreSQL', $output);
        $this->assertStringContainsString('FAIL  Production cache uses Redis', $output);
        $this->assertStringContainsString('FAIL  Self-managed PostgreSQL backups are declared enabled', $output);
        $this->assertStringContainsString('FAIL  A recent restore rehearsal has recorded evidence', $output);
        $this->assertStringContainsString('FAIL  The scheduler service heartbeat is fresh', $output);
        $this->assertStringContainsString('FAIL  At least one active administrator has MFA enrolled', $output);
        $this->assertStringNotContainsString('do-not-print-this-secret', $output);
    }

    public function test_production_preflight_rejects_one_shared_redis_url_database(): void
    {
        $shared = 'rediss://user:do-not-print-this-secret@redis.example/0';

        config([
            'cache.default' => 'redis',
            'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
            'session.driver' => 'redis',
            'session.connection' => 'session',
            'queue.default' => 'redis',
            'queue.connections.redis' => ['driver' => 'redis', 'connection' => 'queue', 'queue' => 'default', 'retry_after' => 2100],
            'queue.connections.redis-emails' => ['driver' => 'redis', 'connection' => 'queue', 'queue' => 'emails', 'retry_after' => 120],
            'webinar.email.queue_connection' => 'redis-emails',
            'database.redis.cache' => ['url' => $shared, 'database' => 1],
            'database.redis.session' => ['url' => $shared, 'database' => 2],
            'database.redis.queue' => ['url' => $shared, 'database' => 3],
        ]);

        Artisan::call('security:check', ['--production' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('FAIL  Redis cache, sessions, and queues are isolated', $output);
        $this->assertStringNotContainsString('do-not-print-this-secret', $output);
    }

    public function test_managed_postgresql_requires_verified_tls(): void
    {
        config([
            'operations.managed_postgres' => true,
            'database.connections.pgsql.sslmode' => 'verify-full',
            'database.connections.pgsql.sslrootcert' => '/etc/ssl/certs/postgresql-ca.pem',
        ]);

        $this->assertTrue($this->securityCheckMethod('databaseTransportIsVerified')->invoke(
            app(SecurityCheck::class),
            'pgsql',
            'pgsql',
        ));

        config(['database.connections.pgsql.sslmode' => 'disable']);

        $this->assertFalse($this->securityCheckMethod('databaseTransportIsVerified')->invoke(
            app(SecurityCheck::class),
            'pgsql',
            'pgsql',
        ));
    }

    public function test_self_managed_postgresql_accepts_only_local_hosts_without_tls(): void
    {
        config([
            'operations.managed_postgres' => false,
            'database.connections.pgsql.driver' => 'pgsql',
            'database.connections.pgsql.sslmode' => 'disable',
        ]);

        $method = $this->securityCheckMethod('selfManagedPostgresHostIsLocal');

        foreach (['127.0.0.1', 'localhost', '::1', '/var/run/postgresql'] as $host) {
            config(['database.connections.pgsql.host' => $host, 'database.connections.pgsql.url' => null]);

            $this->assertTrue($method->invoke(app(SecurityCheck::class), 'pgsql', 'pgsql'));
        }

        config(['database.connections.pgsql.host' => 'postgres.internal.example']);

        $this->assertFalse($method->invoke(app(SecurityCheck::class), 'pgsql', 'pgsql'));

        config([
            'database.connections.pgsql.host' => '127.0.0.1',
            'database.connections.pgsql.url' => 'postgresql://postgres.internal.example/webinar',
        ]);

        $this->assertFalse($method->invoke(app(SecurityCheck::class), 'pgsql', 'pgsql'));
    }

    public function test_self_managed_postgresql_still_requires_backup_and_restore_evidence(): void
    {
        config([
            'operations.managed_postgres' => false,
            'operations.backups_enabled' => false,
            'operations.backup_last_restore_at' => null,
            'operations.backup_restore_reference' => null,
        ]);

        Artisan::call('security:check', ['--production' => true]);
        $output = Artisan::output();

        $this->assertStringContainsString('FAIL  Self-managed PostgreSQL backups are declared enabled', $output);
        $this->assertStringContainsString('FAIL  A recent restore rehearsal has recorded evidence', $output);
    }

    private function securityCheckMethod(string $name): ReflectionMethod
    {
        $method = new ReflectionMethod(SecurityCheck::class, $name);
        $method->setAccessible(true);

        return $method;
    }
}
