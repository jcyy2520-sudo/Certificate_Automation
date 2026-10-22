<?php

namespace App\Support;

use Illuminate\Support\ConfigurationUrlParser;

final class SessionStorageSafety
{
    public static function usesDedicatedRedisDatabase(): bool
    {
        if (config('session.driver') !== 'redis') {
            return false;
        }

        $sessionConnection = config('session.connection');

        if (! is_string($sessionConnection) || $sessionConnection === '') {
            return false;
        }

        $connections = (array) config('database.redis', []);
        $sessionConfiguration = $connections[$sessionConnection] ?? null;

        if (! is_array($sessionConfiguration)) {
            return false;
        }

        $sessionIdentity = self::redisDatabaseIdentity($sessionConfiguration);

        if ($sessionIdentity === null) {
            return false;
        }

        foreach ($connections as $name => $configuration) {
            if ($name === $sessionConnection || ! is_array($configuration)) {
                continue;
            }

            if (hash_equals($sessionIdentity, (string) self::redisDatabaseIdentity($configuration))) {
                return false;
            }
        }

        foreach (self::activeRedisConsumerConnections() as $connection) {
            $configuration = $connections[$connection] ?? null;
            $identity = is_array($configuration) ? self::redisDatabaseIdentity($configuration) : null;
            if ($identity === null || hash_equals($sessionIdentity, $identity)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private static function activeRedisConsumerConnections(): array
    {
        $connections = [];
        $cacheStores = array_unique(array_filter([
            config('cache.default'),
            config('cache.limiter') ?: config('cache.default'),
        ], 'is_string'));

        foreach ($cacheStores as $store) {
            $configuration = config("cache.stores.{$store}");

            if (is_array($configuration) && ($configuration['driver'] ?? null) === 'redis') {
                $connections[] = (string) ($configuration['connection'] ?? 'default');
                $connections[] = (string) ($configuration['lock_connection'] ?? $configuration['connection'] ?? 'default');
            }
        }

        $queueConnections = array_unique(array_filter([
            config('queue.default'),
            config('webinar.email.queue_connection'),
        ], 'is_string'));

        foreach ($queueConnections as $queue) {
            $configuration = config("queue.connections.{$queue}");

            if (is_array($configuration) && ($configuration['driver'] ?? null) === 'redis') {
                $connections[] = (string) ($configuration['connection'] ?? 'default');
            }
        }

        return array_values(array_unique($connections));
    }

    /**
     * Return a non-secret digest of the server/database identity. Passwords are
     * deliberately excluded: different credentials can still address the same
     * Redis database and therefore do not make a session flush safe.
     */
    public static function redisDatabaseIdentity(array $configuration): ?string
    {
        try {
            // Match RedisManager: URL primary options and query options both
            // override the nominal connection defaults.
            $configuration = (new ConfigurationUrlParser)->parseConfiguration($configuration);
            // Connectors can reinterpret nested/global options (including
            // Predis parameter defaults and PhpRedis's last-wins merge).
            // Refuse endpoint overrides here: destructive flushing requires a
            // directly resolvable single-server identity, not a topology guess.
            foreach ([config('database.redis.options', []), $configuration['options'] ?? []] as $options) {
                if (! is_array($options) || array_intersect(['host', 'port', 'database', 'parameters', 'replication', 'aggregate', 'connections'], array_keys($options)) !== []) {
                    return null;
                }
            }
        } catch (\Throwable) {
            return null;
        }

        $host = $configuration['host'] ?? null;
        if (is_string($host)) {
            $host = preg_replace('#^(?:tcp|tls)://#i', '', $host);
        }
        $port = $configuration['port'] ?? 6379;
        $database = $configuration['database'] ?? null;

        if (! is_string($host) || trim($host) === '' || str_contains($host, '://') || str_starts_with($host, '/')
            || ! is_scalar($port) || ! preg_match('/^[0-9]+$/D', (string) $port) || (int) $port < 1 || (int) $port > 65535
            || ! is_scalar($database) || ! preg_match('/^[0-9]+$/D', (string) $database)) {
            return null;
        }

        $identity = [
            'host' => strtolower($host),
            'port' => (int) $port,
            'database' => (int) $database,
        ];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
