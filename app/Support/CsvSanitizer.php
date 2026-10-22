<?php

namespace App\Support;

final class CsvSanitizer
{
    /**
     * Sanitize a cell value for safe inclusion in CSV exports.
     *
     * Spreadsheet applications (Microsoft Excel, LibreOffice Calc, etc.) may interpret
     * cells starting with formula characters (=, +, -, @) as executable expressions or
     * DDE commands. Quoting alone does not mitigate this, as spreadsheet engines evaluate
     * formulas inside quotes.
     *
     * Strips null bytes and prepends a single quote (') if the value begins with
     * any formula trigger character, even when preceded by whitespace or control characters.
     */
    public static function safeCell(mixed $value): string
    {
        $clean = str_replace("\0", '', (string) ($value ?? ''));

        if (preg_match('/^[\p{Z}\x00-\x20]*[=+\-@]/u', $clean) === 1) {
            return "'".$clean;
        }

        return $clean;
    }
}
