<?php

namespace App\Services\Email;

class ProviderRateLimitException extends \RuntimeException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct('Brevo is rate limiting email requests. Sending will resume after the provider cooldown.');
    }
}
