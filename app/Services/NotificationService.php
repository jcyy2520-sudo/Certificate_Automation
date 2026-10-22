<?php

namespace App\Services;

use App\Jobs\SendTransactionalEmail;
use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use Illuminate\Support\Facades\DB;
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
        ?string $idempotencyKey = null,
        ?User $account = null,
    ): EmailDelivery {
        $delivery = EmailDelivery::query()->create([
            'webinar_id' => $webinar?->id,
            'account_user_id' => $account?->id,
            'participant_id' => $participant?->id,
            'certificate_id' => $certificate?->id,
            'type' => $type,
            'provider' => config('webinar.email.provider'),
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
            'recipient_email' => $recipient,
            'subject' => $subject,
            'payload' => ['html' => $html, 'attachments' => $attachments, 'account_version' => $account?->auth_version],
            // Set the default explicitly so EmailDelivery can establish its
            // database-backed active-delivery claim before INSERT. Database
            // defaults are applied too late for Eloquent model events.
            'status' => 'pending',
            'max_attempts' => config('webinar.email.max_attempts'),
            'scheduled_at' => now(),
            'expires_at' => $expiresAt,
        ]);

        // Never let a worker observe a delivery whose certificate transaction
        // later rolls back. This also prevents sending a certificate before its
        // durable file and issued status are committed.
        if ($account) {
            DB::afterCommit(function () use ($delivery): void {
                try {
                    SendTransactionalEmail::dispatch($delivery->id)->onQueue(config('accounts.email_queue'));
                } catch (\Throwable) {
                    // The relay can recover the committed row. Do not log a
                    // queue exception that could contain activation tokens.
                    $delivery->update(['last_error' => 'Queue handoff pending. The outbox relay will retry dispatch.']);
                }
            });
        } else {
            SendTransactionalEmail::dispatch($delivery->id)->afterCommit();
        }

        return $delivery;
    }
}
