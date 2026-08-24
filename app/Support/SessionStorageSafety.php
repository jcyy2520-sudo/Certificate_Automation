<?php

namespace App\Support;

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
            if ($connection === $sessionConnection) {
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
    private static function redisDatabaseIdentity(array $configuration): ?string
    {
        $url = is_string($configuration['url'] ?? null) ? parse_url($configuration['url']) : [];

        if ($url === false) {
            return null;
        }

        $host = $configuration['host'] ?? $url['host'] ?? null;
        $port = $configuration['port'] ?? $url['port'] ?? 6379;
        $database = $configuration['database'] ?? (isset($url['path']) ? ltrim((string) $url['path'], '/') : null);

        if (! is_string($host) || $host === '' || ! is_numeric($port) || ! is_numeric($database)) {
            return null;
        }

        $identity = [
            'scheme' => strtolower((string) ($url['scheme'] ?? 'redis')),
            'host' => strtolower($host),
            'port' => (int) $port,
            'database' => (int) $database,
        ];

        return hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
    }
}
