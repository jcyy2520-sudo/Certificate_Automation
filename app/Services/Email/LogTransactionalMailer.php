<?php

namespace App\Services\Email;

use App\Contracts\TransactionalMailer;
use Illuminate\Support\Facades\Log;

class LogTransactionalMailer implements TransactionalMailer
{
    public function send(
        string $recipient,
        string $subject,
        string $html,
        array $attachments = [],
        ?string $idempotencyKey = null,
    ): ?string {
        // Development logs must not become a second store of email addresses or
        // magic-link payloads. The fingerprint supports local correlation only.
        Log::info('Transactional email captured by the log provider.', [
            'recipient_fingerprint' => hash_hmac('sha256', strtolower(trim($recipient)), (string) config('app.key')),
            'subject_fingerprint' => hash_hmac('sha256', $subject, (string) config('app.key')),
            'attachment_count' => count($attachments),
        ]);

        return null;
    }
}
