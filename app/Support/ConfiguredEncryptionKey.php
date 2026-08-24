<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

final class ConfiguredEncryptionKey
{
    public static function decode(mixed $configured): ?string
    {
        if (! is_string($configured) || $configured === '') {
            return null;
        }

        if (! Str::startsWith($configured, 'base64:')) {
            return $configured;
        }

        $encoded = Str::after($configured, 'base64:');
        $decoded = base64_decode($encoded, true);

        return $encoded !== '' && is_string($decoded) ? $decoded : null;
    }

    public static function isSupported(mixed $configured, mixed $cipher): bool
    {
        $decoded = self::decode($configured);

        return $decoded !== null
            && is_string($cipher)
            && Encrypter::supported($decoded, $cipher);
    }

    public static function isObviousPlaceholder(mixed $configured): bool
    {
        $decoded = self::decode($configured);

        if (! is_string($configured) || $decoded === null) {
            return true;
        }

        $configuredText = strtolower($configured);
        $decodedText = preg_match('//u', $decoded) === 1 ? strtolower($decoded) : '';
        $placeholder = '/(?:change[-_ ]?me|replace[-_ ]?me|placeholder|example|your[-_ ]?(?:app[-_ ]?)?key|insert[-_ ]?(?:app[-_ ]?)?key|password|012345|abcdef|qwerty)/i';

        if (preg_match($placeholder, $configuredText) || ($decodedText !== '' && preg_match($placeholder, $decodedText))) {
            return true;
        }

        $bytes = array_values(unpack('C*', $decoded) ?: []);

        if (count(array_unique($bytes)) < 8) {
            return true;
        }

        // Repeated short patterns (for example, 12341234...) are common in
        // copied example configurations and are never credible random keys.
        for ($length = 1; $length <= 8 && $length < strlen($decoded); $length++) {
            $pattern = substr($decoded, 0, $length);

            if (str_repeat($pattern, (int) ceil(strlen($decoded) / $length)) === $decoded) {
                return true;
            }
        }

        return false;
    }
}
