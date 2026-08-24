<?php

namespace App\Console\Commands;

use App\Jobs\IssueCertificateBatch;
use App\Jobs\SendTransactionalEmail;
use App\Models\User;
use App\Models\Webinar;
use App\Services\Email\BrevoTransactionalMailer;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

class SecurityCheck extends Command
{
    protected $signature = 'security:check {--production : Enforce every production deployment requirement}';

    protected $description = 'Fail when runtime security or privacy controls are unsafe for real participant data';

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(): int
    {
        $production = $this->option('production') || app()->isProduction();
        $url = (string) config('app.url');
        $database = (string) config('database.default');
        $databaseDriver = (string) config("database.connections.{$database}.driver");
        $provider = (string) config('webinar.email.provider');
        $queueName = (string) config('queue.default');
        $queue = (array) config("queue.connections.{$queueName}", []);
        $emailQueueName = (string) config('webinar.email.queue_connection');
        $emailQueue = (array) config("queue.connections.{$emailQueueName}", []);
        $cacheStoreName = (string) config('cache.default');
        $cacheStore = (array) config("cache.stores.{$cacheStoreName}", []);
        $certificateDiskName = (string) config('webinar.certificate_disk');
        $certificateDisk = config("filesystems.disks.{$certificateDiskName}");

        $this->info($production ? 'Production security preflight' : 'Security preflight');

        $this->check(! config('app.debug'), 'Debug output is disabled', 'Set APP_DEBUG=false.');
        $this->check(filled(config('app.key')), 'Application encryption key is configured', 'Generate APP_KEY once and back it up securely.');
        $this->check(! config('security.bootstrap_admin_password_present'), 'No bootstrap administrator password is retained', 'Remove ADMIN_PASSWORD from the runtime environment.');
        $this->check(config('security.content_security_policy.enabled'), 'Content Security Policy is enabled', 'Set SECURITY_CSP_ENABLED=true.');
        $this->check(! config('security.content_security_policy.report_only'), 'Content Security Policy is enforced', 'Set SECURITY_CSP_REPORT_ONLY=false.');
        $this->check(! config('filesystems.disks.local.serve'), 'Private storage has no generic HTTP route', 'Keep the local private disk serve option false.');
        $this->check(is_array($certificateDisk), 'Certificate storage disk exists', 'Set CERTIFICATE_DISK to a configured private filesystem disk.');
        $this->check(
            is_array($certificateDisk)
                && $certificateDiskName !== 'public'
                && ($certificateDisk['visibility'] ?? 'private') !== 'public'
                && ! ($certificateDisk['serve'] ?? false),
            'Certificate storage is private',
            'Use a private disk or bucket; never CERTIFICATE_DISK=public or a served/public-visibility disk.',
        );
        $this->check(config('session.encrypt'), 'Session contents are encrypted', 'Set SESSION_ENCRYPT=true.');
        $this->check(config('session.http_only'), 'Session cookies are HTTP-only', 'Set SESSION_HTTP_ONLY=true.');
        $this->check(config('session.same_site') === 'strict', 'Session cookies use SameSite=Strict', 'Set SESSION_SAME_SITE=strict.');
        $this->check((int) config('session.lifetime') <= 30, 'Administrator idle sessions expire within 30 minutes', 'Set SESSION_LIFETIME=30 or lower.');
        $this->check(config('security.require_admin_two_factor'), 'Administrator MFA is mandatory', 'Set SECURITY_REQUIRE_ADMIN_TWO_FACTOR=true.');
        $this->check(! config('security.allow_admin_remember_me'), 'Persistent administrator login is disabled', 'Set SECURITY_ALLOW_ADMIN_REMEMBER_ME=false.');
        $this->check(config('security.require_sensitive_action_password_confirmation'), 'Sensitive administrator actions require recent password confirmation', 'Set SECURITY_REQUIRE_SENSITIVE_ACTION_PASSWORD_CONFIRMATION=true.');
        $this->check(
            Schema::hasColumn('webinars', 'requires_verification'),
            'Per-webinar participant verification control is installed',
            'Run the database migrations before serving participant forms.',
        );

        if ($production) {
            $host = parse_url($url, PHP_URL_HOST);
            $this->check(Str::startsWith($url, 'https://'), 'APP_URL uses HTTPS', 'Set APP_URL to the canonical HTTPS origin.');
            $this->check(! in_array($host, [null, '', 'localhost', '127.0.0.1', '::1'], true), 'APP_URL uses a deployment hostname', 'Replace the local APP_URL hostname.');
            $this->check(config('session.secure'), 'Session cookies are HTTPS-only', 'Set SESSION_SECURE_COOKIE=true after configuring HTTPS.');
            $this->check($databaseDriver === 'pgsql', 'Production database is PostgreSQL', 'Set DB_CONNECTION=pgsql and use the managed PostgreSQL credentials.');
            $this->check(config('operations.managed_postgres'), 'PostgreSQL is declared as a managed service', 'Set MANAGED_POSTGRES=true only after provisioning managed PostgreSQL with restricted ingress and provider backups.');
            $this->check($this->databaseTransportIsVerified($database, $databaseDriver), 'PostgreSQL TLS verifies the server identity', 'Use DB_SSLMODE=verify-full and DB_SSLROOTCERT pointing to the provider CA bundle.');
            $this->check(config('session.driver') === 'redis', 'Production sessions use encrypted Redis payloads', 'Use SESSION_DRIVER=redis with a dedicated session connection; database sessions retain raw IP/user-agent columns.');
            $this->check(config('session.connection') === 'session', 'Session storage uses its dedicated Redis connection', 'Set SESSION_CONNECTION=session so emergency invalidation cannot flush queues or cache.');
            $this->check(($cacheStore['driver'] ?? null) === 'redis', 'Production cache uses Redis', 'Set CACHE_STORE=redis.');
            $this->check($this->redisDataIsIsolated($cacheStore, $queue, $emailQueue), 'Redis cache, sessions, and queues are isolated', 'Use separate Redis databases or clusters for cache, sessions, and queue data; avoid one shared REDIS_URL database path.');
            $this->check(($queue['driver'] ?? null) !== 'sync', 'Background work uses an asynchronous queue', 'Use a database or Redis queue and run workers.');
            $this->check(
                $this->visibilityTimeout($queue) > IssueCertificateBatch::TIMEOUT,
                'Certificate queue visibility exceeds its job timeout',
                'Set retry_after/visibility above 1800 seconds (2100 recommended) and keep worker --timeout below it.',
            );
            $emailVisibility = $this->visibilityTimeout($emailQueue);
            $this->check(
                ($emailQueue['driver'] ?? null) !== 'sync'
                    && $emailVisibility > SendTransactionalEmail::TIMEOUT
                    && $emailVisibility < 1800,
                'Email retries stay inside the provider idempotency window',
                'Use the dedicated email queue connection with timeout 60 and retry_after about 120 seconds.',
            );
            $this->check($provider === 'brevo', 'Participant access email uses an approved real provider', 'Set TRANSACTIONAL_EMAIL_PROVIDER=brevo; unknown or log providers are forbidden in production.');

            $trustedProxies = (array) config('app.trusted_proxies', []);

            if (config('security.behind_proxy')) {
                $this->check(
                    $trustedProxies !== [] && $this->proxyAllowlistIsExact($trustedProxies),
                    'Reverse-proxy trust is an exact allowlist',
                    'Set APP_TRUSTED_PROXIES to exact proxy IPs/CIDRs and never a wildcard.',
                );
            } else {
                $this->check(
                    $trustedProxies === [],
                    'Forwarded headers are disabled for direct TLS',
                    'Either clear APP_TRUSTED_PROXIES for direct TLS or set SECURITY_BEHIND_PROXY=true and configure an exact allowlist.',
                );
            }

            if ($provider === 'brevo') {
                $this->check(filled(config('services.brevo.key')), 'Brevo credential is configured', 'Set a newly rotated BREVO_API_KEY through a secret manager.');
                $this->check(
                    BrevoTransactionalMailer::isApprovedBaseUrl((string) config('services.brevo.base_url')),
                    'Brevo API endpoint is the approved exact origin',
                    'Use exactly https://api.brevo.com/v3 with no credentials, query, fragment, redirect, or alternate port.',
                );
            }

            $channels = (array) config('logging.channels.stack.channels', []);
            $this->check(config('logging.default') === 'stack' && in_array('daily', $channels, true), 'Logs rotate with bounded retention', 'Use LOG_CHANNEL=stack and LOG_STACK=daily.');
            $this->check($this->hasEnrolledAdministrator(), 'At least one active administrator has MFA enrolled', 'Create an administrator privately, sign in, and enroll MFA before traffic.');
            $this->check(config('operations.backups_enabled'), 'Managed backups are declared enabled', 'Enable encrypted managed PostgreSQL and private-file backups, then set BACKUPS_ENABLED=true.');
            $this->check(
                $this->timestampIsRecent(
                    config('operations.backup_last_restore_at'),
                    (int) config('operations.backup_restore_max_age_days'),
                    'days',
                ) && filled(config('operations.backup_restore_reference')),
                'A recent restore rehearsal has recorded evidence',
                'Restore a backup to a disposable target, verify it, then set BACKUP_LAST_RESTORE_AT and BACKUP_RESTORE_REFERENCE.',
            );
            $this->check($this->heartbeatIsFresh('scheduler'), 'The scheduler service heartbeat is fresh', 'Run operations:heartbeat every minute under the always-on scheduler service.');
            $this->check($this->heartbeatIsFresh('queue:default'), 'The default queue worker heartbeat is fresh', 'Start the always-on default queue worker and wait for the next scheduler probe.');
            $this->check($this->heartbeatIsFresh('queue:emails'), 'The email queue worker heartbeat is fresh', 'Start the always-on email queue worker and wait for the next scheduler probe.');
        }

        if (config('session.driver') === 'file') {
            $this->addWarning('File sessions are single-node; use Redis or a database before horizontal scaling.');
        }

        if (config('webinar.certificate_disk') === 'local') {
            $this->addWarning('Local certificate storage needs encrypted disks, encrypted backups, and one application node.');
        }

        if (config('webinar.public_verification_after_privacy_erasure')) {
            $this->addWarning('Post-erasure certificate verification remains linkable; document the lawful-retention decision and privacy notice explicitly.');
        }

        if (Schema::hasColumn('webinars', 'requires_verification')) {
            $unverifiedWebinars = Webinar::query()->where('requires_verification', false)->count();

            if ($unverifiedWebinars > 0) {
                $this->addWarning("{$unverifiedWebinars} webinar(s) have email verification disabled; participants can be impersonated by anyone who knows a registered email address.");
            }
        }

        $this->newLine();
        $this->line("{$this->failures} failure(s), {$this->warnings} warning(s).");

        return $this->failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function check(bool $passed, string $label, string $remediation): void
    {
        if ($passed) {
            $this->line("<fg=green>PASS</>  {$label}");

            return;
        }

        $this->failures++;
        $this->line("<fg=red>FAIL</>  {$label}");
        $this->line("      {$remediation}");
    }

    private function addWarning(string $message): void
    {
        $this->warnings++;
        $this->line("<fg=yellow>WARN</>  {$message}");
    }

    private function hasEnrolledAdministrator(): bool
    {
        try {
            return User::query()
                ->where('role', User::ADMINISTRATOR_ROLE)
                ->where('is_active', true)
                ->whereNotNull('two_factor_confirmed_at')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /** @param array<string, mixed> $connection */
    private function visibilityTimeout(array $connection): int
    {
        if (($connection['driver'] ?? null) === 'sqs') {
            return (int) config('security.sqs_visibility_timeout');
        }

        return (int) ($connection['retry_after'] ?? 0);
    }

    private function databaseTransportIsVerified(string $connection, string $driver): bool
    {
        return match ($driver) {
            'pgsql' => $this->postgresOption($connection, 'sslmode') === 'verify-full'
                && filled($this->postgresOption($connection, 'sslrootcert')),
            'mysql', 'mariadb' => filled(config("database.connections.{$connection}.ssl_ca")),
            'sqlsrv' => in_array(
                strtolower((string) config("database.connections.{$connection}.encrypt")),
                ['yes', 'true', 'mandatory', 'strict'],
                true,
            ) && ! filter_var(
                config("database.connections.{$connection}.trust_server_certificate", false),
                FILTER_VALIDATE_BOOL,
            ),
            default => false,
        };
    }

    private function postgresOption(string $connection, string $option): mixed
    {
        $configured = config("database.connections.{$connection}.{$option}");
        $url = (string) config("database.connections.{$connection}.url", '');

        if ($url === '') {
            return $configured;
        }

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        return $query[$option] ?? $configured;
    }

    /** @param array<string, mixed> $cacheStore @param array<string, mixed> $queue @param array<string, mixed> $emailQueue */
    private function redisDataIsIsolated(array $cacheStore, array $queue, array $emailQueue): bool
    {
        if (($cacheStore['driver'] ?? null) !== 'redis'
            || ($queue['driver'] ?? null) !== 'redis'
            || ($emailQueue['driver'] ?? null) !== 'redis') {
            return false;
        }

        $connections = [
            (string) ($cacheStore['connection'] ?? ''),
            (string) config('session.connection'),
            (string) ($queue['connection'] ?? ''),
            (string) ($emailQueue['connection'] ?? ''),
        ];

        if (in_array('', $connections, true)) {
            return false;
        }

        $stores = array_map($this->effectiveRedisStorageIdentity(...), $connections);

        return ! in_array(null, $stores, true)
            && $stores[0] !== $stores[1]
            && $stores[1] !== $stores[2]
            && $stores[1] !== $stores[3]
            && $stores[0] !== $stores[2]
            && $stores[0] !== $stores[3];
    }

    private function effectiveRedisStorageIdentity(string $connection): ?string
    {
        $config = (array) config("database.redis.{$connection}", []);
        $url = (string) ($config['url'] ?? '');
        $host = $url === '' ? ($config['host'] ?? null) : parse_url($url, PHP_URL_HOST);
        $port = $url === '' ? ($config['port'] ?? null) : (parse_url($url, PHP_URL_PORT) ?: 6379);
        $path = $url === '' ? null : parse_url($url, PHP_URL_PATH);
        $database = is_string($path) && trim($path, '/') !== ''
            ? trim($path, '/')
            : ($config['database'] ?? null);

        if (! filled($host) || ! filled($port) || $database === null) {
            return null;
        }

        return strtolower((string) $host).':'.$port.'/'.$database;
    }

    /** @param list<string> $proxies */
    private function proxyAllowlistIsExact(array $proxies): bool
    {
        foreach ($proxies as $proxy) {
            if (! str_contains($proxy, '/')) {
                if (filter_var($proxy, FILTER_VALIDATE_IP) === false) {
                    return false;
                }

                continue;
            }

            [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
            $maximum = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32
                : (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : null);

            if ($maximum === null || ! ctype_digit((string) $prefix) || (int) $prefix > $maximum) {
                return false;
            }
        }

        return true;
    }

    private function heartbeatIsFresh(string $name): bool
    {
        try {
            return $this->timestampIsRecent(
                Cache::get('operations:heartbeat:'.$name),
                (int) config('operations.service_heartbeat_max_age_minutes'),
                'minutes',
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function timestampIsRecent(mixed $value, int $maximumAge, string $unit): bool
    {
        if (! filled($value) || $maximumAge < 1) {
            return false;
        }

        try {
            $timestamp = CarbonImmutable::parse((string) $value);
            $now = CarbonImmutable::now();
            $oldest = $unit === 'days'
                ? $now->subDays($maximumAge)
                : $now->subMinutes($maximumAge);

            return $timestamp->betweenIncluded($oldest, $now->addMinutes(5));
        } catch (Throwable) {
            return false;
        }
    }
}
