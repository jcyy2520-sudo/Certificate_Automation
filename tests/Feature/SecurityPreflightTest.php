<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
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
        $this->assertStringContainsString('FAIL  Managed backups are declared enabled', $output);
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
}
