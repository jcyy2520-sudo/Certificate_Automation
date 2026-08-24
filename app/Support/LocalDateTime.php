<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

class LocalDateTime
{
    /**
     * Interpret a browser datetime-local value in an IANA timezone, reject
     * DST-normalized nonexistent times, and return one unambiguous UTC instant.
     */
    public static function toUtc(?string $value, string $timezone, string $field): ?CarbonImmutable
    {
        if (blank($value)) {
            return null;
        }

        try {
            $local = CarbonImmutable::parse($value, $timezone);
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'Enter a valid local date and time.']);
        }

        $submittedMinute = str_replace(' ', 'T', substr(trim($value), 0, 16));

        if ($local->format('Y-m-d\TH:i') !== $submittedMinute) {
            throw ValidationException::withMessages([
                $field => 'That local time does not exist in the selected timezone because of a clock change.',
            ]);
        }

        return $local->utc();
    }
}
