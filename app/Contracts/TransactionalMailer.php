<?php

namespace App\Contracts;

interface TransactionalMailer
{
    /** @param array<int, array{name: string, content: string}> $attachments */
    public function send(
        string $recipient,
        string $subject,
        string $html,
        array $attachments = [],
        ?string $idempotencyKey = null,
    ): ?string;
}
