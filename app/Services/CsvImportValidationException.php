<?php

namespace App\Services;

use RuntimeException;

final class CsvImportValidationException extends RuntimeException
{
    public function __construct(public readonly string $issueType, string $message)
    {
        parent::__construct($message);
    }
}
