<?php

namespace App\Services\Email;

class EmailBudgetDeferredException extends \RuntimeException
{
    public function __construct(string $message, public readonly int $retryAfter)
    {
        parent::__construct($message);
    }
}
