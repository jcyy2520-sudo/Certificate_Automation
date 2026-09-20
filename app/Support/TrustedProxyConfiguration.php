<?php

namespace App\Support;

final class TrustedProxyConfiguration
{
    /**
     * Parse an operator-supplied proxy allowlist without permitting Laravel's
     * dynamic or catch-all proxy shortcuts.
     *
     * @return array<int, string>
     */
    public static function proxies(string $value): array
    {
        return self::values($value, fn (string $proxy): bool => self::isExactProxy($proxy));
    }

    /**
     * Parse exact host aliases for TrustHosts. Wildcard host patterns are not
     * accepted because the bootstrap turns these values into regular expressions.
     *
     * @return array<int, string>
     */
    public static function hosts(string $value): array
    {
        return self::values($value, fn (string $host): bool => self::isExactHost($host));
    }

    /**
     * @param  callable(string): bool  $isAllowed
     * @return array<int, string>
     */
    private static function values(string $value, callable $isAllowed): array
    {
        $values = [];

        foreach (explode(',', $value) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate !== '' && $isAllowed($candidate)) {
                $values[$candidate] = true;
            }
        }

        return array_keys($values);
    }

    private static function isExactProxy(string $proxy): bool
    {
        if (in_array(strtolower($proxy), ['*', '**', 'remote_addr'], true)) {
            return false;
        }

        if (! str_contains($proxy, '/')) {
            return filter_var($proxy, FILTER_VALIDATE_IP) !== false;
        }

        [$address, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
        $maximum = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? 32
            : (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 128 : null);

        return $maximum !== null
            && ctype_digit((string) $prefix)
            && (int) $prefix > 0
            && (int) $prefix <= $maximum;
    }

    private static function isExactHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return preg_match(
            '/^(?=.{1,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)*[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',
            $host,
        ) === 1;
    }
}
