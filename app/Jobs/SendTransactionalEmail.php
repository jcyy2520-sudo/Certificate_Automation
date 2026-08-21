<?php

namespace App\Jobs;

use App\Contracts\TransactionalMailer;
use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Webinar;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

class SendTransactionalEmail implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 60;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = self::TIMEOUT;

    public function __construct(public int $deliveryId)
    {
        $this->onConnection(config('webinar.email.queue_connection'));
        $this->onQueue(config('webinar.email.queue'));
    }

    public function handle(TransactionalMailer $mailer): void
    {
        DB::transaction(function () use ($mailer): void {
            // Establish the global webinar -> participant -> child lock order,
            // then re-read the delivery before any external side effect.
            $identity = EmailDelivery::query()
                ->whereKey($this->deliveryId)
                ->select(['webinar_id', 'participant_id'])
                ->firstOrFail();
            $webinar = $identity->webinar_id
                ? Webinar::query()->whereKey($identity->webinar_id)->lockForUpdate()->first()
                : null;
            $participant = $identity->participant_id
                ? Participant::withTrashed()->whereKey($identity->participant_id)->lockForUpdate()->first()
                : null;
            $delivery = EmailDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->firstOrFail();

            if (in_array($delivery->status, ['sent', 'cancelled'], true)) {
                return;
            }

            // Privacy erasure clears both fields. A queued retry that wakes up
            // later must not reconstruct or send the erased message. Requiring
            // the locked identity and current email also prevents a stale
            // in-memory address from surviving an erasure or identity change.
            if (blank($delivery->recipient_email)
                || blank($delivery->payload)
                || $delivery->expires_at?->isPast()
                || ($identity->webinar_id && (
                    ! $webinar
                    || $webinar->deletion_started_at
                    || $webinar->status === 'archived'
                    || $webinar->retention_due_at?->isPast()
                    || $delivery->webinar_id !== $webinar->id
                ))
                || ($identity->participant_id && (
                    ! $participant
                    || $participant->trashed()
                    || $participant->privacy_erased_at
                    || $delivery->participant_id !== $participant->id
                    || ! hash_equals(
                        Str::lower(trim((string) $participant->email)),
                        Str::lower(trim((string) $delivery->recipient_email)),
                    )
                ))) {
                $delivery->update([
                    'status' => 'cancelled',
                    'processing_at' => null,
                    'recipient_email' => null,
                    'payload' => null,
                    'last_error' => null,
                ]);

                return;
            }

            $delivery->update([
                'status' => 'processing',
                'processing_at' => now(),
                'attempts' => $this->attempts(),
            ]);

            // The provider timeout is bounded. Holding the participant lock
            // through this call gives erasure and sending a deterministic order:
            // whichever acquires the identity first completes first.
            $messageId = $mailer->send(
                $delivery->recipient_email,
                $delivery->subject,
                $delivery->payload['html'] ?? '',
                $delivery->payload['attachments'] ?? [],
                $delivery->idempotency_key,
            );

            $delivery->update([
                'status' => 'sent',
                'provider_message_id' => $messageId,
                'sent_at' => now(),
                'last_error' => null,
                // Attachments contain base64 PDF data and are only needed while
                // a delivery can still be retried.
                'payload' => null,
            ]);

            // Mirror delivery onto the certificate so the admin views show when it reached the recipient.
            if ($delivery->certificate_id) {
                Certificate::query()->whereKey($delivery->certificate_id)->whereNull('sent_at')->update(['sent_at' => now()]);
            }
        }, attempts: 3);
    }

    public function failed(?Throwable $exception): void
    {
        DB::transaction(function () use ($exception): void {
            $identity = EmailDelivery::query()
                ->whereKey($this->deliveryId)
                ->select(['webinar_id', 'participant_id'])
                ->first();

            if (! $identity) {
                return;
            }

            if ($identity->webinar_id) {
                Webinar::query()->whereKey($identity->webinar_id)->lockForUpdate()->first();
            }

            if ($identity->participant_id) {
                Participant::withTrashed()->whereKey($identity->participant_id)->lockForUpdate()->first();
            }

            $delivery = EmailDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if (! $delivery
                || in_array($delivery->status, ['sent', 'cancelled'], true)
                || blank($delivery->recipient_email)
                || blank($delivery->payload)) {
                return;
            }

            // Use an Eloquent update so encrypted casts are never bypassed when
            // a provider error is persisted for an administrator to inspect.
            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error' => Str::limit((string) $exception?->getMessage(), 2000, ''),
            ]);
        }, attempts: 3);
    }
}
