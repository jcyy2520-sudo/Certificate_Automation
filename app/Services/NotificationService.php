<?php

namespace App\Services;

use App\Jobs\SendTransactionalEmail;
use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Webinar;
use Illuminate\Support\Str;

class NotificationService
{
    public function queue(
        ?Webinar $webinar,
        ?Participant $participant,
        string $type,
        string $recipient,
        string $subject,
        string $html,
        ?Certificate $certificate = null,
        array $attachments = [],
        mixed $expiresAt = null,
    ): EmailDelivery {
        $delivery = EmailDelivery::query()->create([
            'webinar_id' => $webinar?->id,
            'participant_id' => $participant?->id,
            'certificate_id' => $certificate?->id,
            'type' => $type,
            'provider' => config('webinar.email.provider'),
            'idempotency_key' => (string) Str::uuid(),
            'recipient_email' => $recipient,
            'subject' => $subject,
            'payload' => ['html' => $html, 'attachments' => $attachments],
            'max_attempts' => config('webinar.email.max_attempts'),
            'scheduled_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        // Never let a worker observe a delivery whose certificate transaction
        // later rolls back. This also prevents sending a certificate before its
        // durable file and issued status are committed.
        SendTransactionalEmail::dispatch($delivery->id)->afterCommit();

        return $delivery;
    }
}
