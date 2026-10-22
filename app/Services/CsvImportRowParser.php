<?php

namespace App\Services;

use App\Models\ImportIssue;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/** Interpret declared columns only; never infer identity or completion from an answer. */
final class CsvImportRowParser
{
    public static function nameKey(string $name): string
    {
        return Str::lower(preg_replace('/\s+/u', ' ', trim($name)) ?? trim($name));
    }

    public static function decimal(string $value): ?float
    {
        if (preg_match('/^\d+(?:\.\d{1,2})?$/D', trim($value)) !== 1) {
            return null;
        }

        $number = (float) $value;

        return $number <= 99999999.99 ? $number : null;
    }

    public function parse(array $row, array $headers, array $map, array $options, string $timestampFormat, bool $registration, bool $scoreRequired): array
    {
        if (count($row) !== count($headers)) {
            $this->reject(ImportIssue::TYPE_INVALID_ROW_STRUCTURE, 'The number of cells does not match the header. Re-export the original CSV.');
        }
        if (! collect($row)->contains(fn ($value) => trim((string) $value) !== '')) {
            $this->reject(ImportIssue::TYPE_EMPTY_ROW, 'Empty response row; nothing was imported.');
        }

        $value = fn (string $field): ?string => isset($map[$field]) ? (trim((string) $row[$map[$field]]) ?: null) : null;
        // Zero is a valid score and must not be treated as an empty value.
        $scoreValue = isset($map['score']) ? trim((string) $row[$map['score']]) : '';
        $maximumValue = isset($map['maximum_score']) ? trim((string) $row[$map['maximum_score']]) : '';
        $email = Str::lower(trim((string) $value('email')));
        if ($email === '') {
            $this->reject(ImportIssue::TYPE_MISSING_EMAIL, 'The response has no email address. Correct it in the source and upload again.');
        }
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->reject(ImportIssue::TYPE_INVALID_EMAIL, 'The email address is invalid. It was not guessed or corrected.');
        }

        $name = $value('full_name');
        if (isset($map['first_name']) || isset($map['last_name'])) {
            if ($value('first_name') === null || $value('last_name') === null) {
                $this->reject(ImportIssue::TYPE_MISSING_NAME, 'Both first and last name must be present when using separate name columns.');
            }
            $name = implode(' ', array_filter(array_map($value, ['first_name', 'middle_name', 'last_name', 'suffix']), fn ($part) => $part !== null));
        }
        if (($registration || isset($map['full_name'])) && $name === null) {
            $this->reject(ImportIssue::TYPE_MISSING_NAME, 'The mapped certificate name is blank.');
        }
        if ($name !== null && (preg_match('/[\p{L}]/u', $name) !== 1 || str_contains($name, '@') || preg_match('/[\x00-\x1F\x7F]/', $name) === 1 || in_array(self::nameKey($name), ['n/a', 'na', 'none', 'unknown', '-', 'not applicable'], true))) {
            $this->reject(ImportIssue::TYPE_MISSING_NAME, 'The certificate name appears to be a placeholder or an invalid name. Confirm the intended spelling.');
        }
        $organization = $value('organization');
        if (mb_strlen((string) $name) > 180 || mb_strlen((string) $organization) > 180) {
            $this->reject(ImportIssue::TYPE_VALUE_TOO_LONG, 'The name or organization exceeds 180 characters. It was not truncated.');
        }

        foreach ($options['required_columns'] as $index) {
            if (trim((string) $row[$index]) === '') {
                $this->reject(ImportIssue::TYPE_INCOMPLETE_RESPONSE, 'Required answer is blank: '.$headers[$index].'.');
            }
        }

        [$score, $maximum] = $this->score($scoreValue, $maximumValue, $options, $scoreRequired || isset($map['score']));
        $timestamp = $value('submitted_at');
        if ($timestamp === null && ! $options['allow_import_time']) {
            $this->reject(ImportIssue::TYPE_INVALID_TIMESTAMP, 'The response timestamp is missing. Supply it or explicitly allow the import time.');
        }
        $submittedAt = $timestamp === null ? null : $this->date($timestamp, $options['timestamp_timezone'], $timestampFormat);

        return [
            'email' => $email,
            'name' => $name,
            'organization' => $organization,
            'score' => $score,
            'maximum_score' => $maximum,
            'submitted_at' => $submittedAt,
            'required_answers' => array_map(fn ($index) => trim((string) $row[$index]), $options['required_columns']),
        ];
    }

    private function score(string $value, string $maximumValue, array $options, bool $required): array
    {
        $maximum = $options['default_maximum_score'];
        if ($maximumValue !== '') {
            $mapped = self::decimal($maximumValue);
            if ($mapped === null || $mapped <= 0 || ($maximum !== null && abs($mapped - $maximum) > 0.000001)) {
                $this->reject(ImportIssue::TYPE_INVALID_SCORE, 'The maximum score is invalid or disagrees with the declared maximum.');
            }
            $maximum = $mapped;
        }
        if ($value === '') {
            if ($required) {
                $this->reject(ImportIssue::TYPE_INVALID_SCORE, 'A score is required but the score cell is blank or unmapped.');
            }

            return [null, $maximum];
        }
        if (str_contains($value, '/')) {
            if ($options['score_mode'] !== 'points' || preg_match('/^(\d+(?:\.\d+)?)\s*\/\s*(\d+(?:\.\d+)?)$/D', $value, $parts) !== 1) {
                $this->reject(ImportIssue::TYPE_INVALID_SCORE, 'Use points mode for fraction scores such as 8/10.');
            }
            $score = self::decimal($parts[1]);
            $fractionMaximum = self::decimal($parts[2]);
            if ($score === null || $fractionMaximum === null || ($maximum !== null && abs($maximum - $fractionMaximum) > 0.000001)) {
                $this->reject(ImportIssue::TYPE_INVALID_SCORE, 'The fraction must use at most two decimal places and agree with the maximum score.');
            }
            $maximum = $fractionMaximum;
        } elseif ($options['score_mode'] === 'percentage') {
            $percentage = preg_match('/^(\d+(?:\.\d{1,2})?)\s*%?$/D', $value, $parts) === 1 ? self::decimal($parts[1]) : null;
            if ($percentage === null || $percentage > 100 || $maximum === null || $maximum <= 0) {
                $this->reject(ImportIssue::TYPE_INVALID_SCORE, 'A percentage needs a value from 0 to 100 and a positive maximum score to convert it to points.');
            }
            $score = $percentage * $maximum / 100;
            if (abs($score - round($score, 2)) > 0.000001) {
                $this->reject(ImportIssue::TYPE_INVALID_SCORE, 'Converting this percentage would round the score. Supply the original points instead.');
            }
            $score = round($score, 2);
        } else {
            $score = self::decimal($value);
            if ($score === null) {
                $this->reject(ImportIssue::TYPE_INVALID_SCORE, 'Use points with at most two decimal places, or explicitly choose percentage mode.');
            }
        }
        if (($maximum !== null && $maximum <= 0) || ($maximum !== null && $score > $maximum)) {
            $this->reject(ImportIssue::TYPE_INVALID_SCORE, 'The score cannot exceed a positive maximum score.');
        }

        return [$score, $maximum];
    }

    private function date(string $value, string $timezone, string $format): Carbon
    {
        if ($format === 'auto' && preg_match('/^(\d{1,2})\/(\d{1,2})\/\d{4}\s/', $value, $parts) === 1 && (int) $parts[1] <= 12 && (int) $parts[2] <= 12) {
            $this->reject(ImportIssue::TYPE_INVALID_TIMESTAMP, 'Ambiguous timestamp: choose month/day/year or day/month/year.');
        }
        $formats = match ($format) {
            'month_day_year' => ['n/j/Y G:i:s', 'n/j/Y G:i', 'n/j/Y g:i:s A', 'n/j/Y g:i A'],
            'day_month_year' => ['d/m/Y G:i:s', 'd/m/Y G:i', 'd/m/Y g:i:s A', 'd/m/Y g:i A'],
            'iso_8601' => ['Y-m-d\TH:i:sP', 'Y-m-d H:i:s', 'Y-m-d H:i'],
            default => ['n/j/Y G:i:s', 'n/j/Y G:i', 'n/j/Y g:i:s A', 'n/j/Y g:i A', 'd/m/Y G:i:s', 'd/m/Y G:i', 'd/m/Y g:i:s A', 'd/m/Y g:i A', 'Y-m-d\TH:i:sP', 'Y-m-d H:i:s', 'Y-m-d H:i'],
        };
        foreach ($formats as $candidate) {
            try {
                $date = Carbon::createFromFormat('!'.$candidate, $value, $timezone);
                $errors = Carbon::getLastErrors();
                if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $date->utc();
                }
            } catch (\Throwable) {
                continue;
            }
        }
        $this->reject(ImportIssue::TYPE_INVALID_TIMESTAMP, 'The timestamp is invalid or does not match the selected format.');
    }

    private function reject(string $type, string $message): never
    {
        throw new CsvImportValidationException($type, $message);
    }
}
