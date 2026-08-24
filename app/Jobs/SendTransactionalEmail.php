<?php

namespace App\Jobs;

use App\Contracts\TransactionalMailer;
use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Form;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\ParticipantMagicLinkService;
use App\Services\ParticipantStatusLinkService;
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

    /**
     * Certificate delivery is intentionally permitted after an administrator
     * marks an event completed: issuance is normally a post-event workflow.
     * Participant access, by contrast, is useful only while forms are live.
     * Unknown webinar-bound delivery types fail closed until explicitly added.
     *
     * @return list<string>
     */
    public static function allowedWebinarStatusesFor(string $deliveryType): array
    {
        return match ($deliveryType) {
            ParticipantMagicLinkService::DELIVERY_TYPE => ['published'],
            ParticipantStatusLinkService::DELIVERY_TYPE => ['published', 'completed'],
            'certificate' => ['published', 'completed'],
            default => [],
        };
    }

    public function handle(TransactionalMailer $mailer): void
    {
        DB::transaction(function () use ($mailer): void {
            // Establish the global webinar -> participant -> child lock order,
            // then re-read the delivery before any external side effect.
            $identity = EmailDelivery::query()
                ->whereKey($this->deliveryId)
                ->select(['webinar_id', 'participant_id', 'type', 'payload'])
                ->firstOrFail();
            $formId = $this->formIdFor($identity);
            $webinar = $identity->webinar_id
                // Shared parent locks keep lifecycle/deletion changes out while
                // allowing different participants' emails to send in parallel.
                ? Webinar::query()->whereKey($identity->webinar_id)->sharedLock()->first()
                : null;
            $form = $identity->type === ParticipantMagicLinkService::DELIVERY_TYPE && $webinar && $formId
                ? Form::query()
                    ->whereKey($formId)
                    ->where('webinar_id', $webinar->id)
                    ->sharedLock()
                    ->first()
                : null;
            $participant = $identity->participant_id
                ? Participant::withTrashed()->whereKey($identity->participant_id)->lockForUpdate()->first()
                : null;
            $delivery = EmailDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->firstOrFail();

            if (in_array($delivery->status, ['sent', 'cancelled'], true)) {
                return;
            }

            if ($this->deliveryIsInvalid($identity, $delivery, $webinar, $form, $participant)) {
                $this->cancel($delivery);

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
                ->select(['webinar_id', 'participant_id', 'type', 'payload'])
                ->first();

            if (! $identity) {
                return;
            }

            $formId = $this->formIdFor($identity);
            $webinar = $identity->webinar_id
                ? Webinar::query()->whereKey($identity->webinar_id)->sharedLock()->first()
                : null;
            $form = $identity->type === ParticipantMagicLinkService::DELIVERY_TYPE && $webinar && $formId
                ? Form::query()
                    ->whereKey($formId)
                    ->where('webinar_id', $webinar->id)
                    ->sharedLock()
                    ->first()
                : null;
            $participant = $identity->participant_id
                ? Participant::withTrashed()->whereKey($identity->participant_id)->lockForUpdate()->first()
                : null;

            $delivery = EmailDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

            if (! $delivery
                || in_array($delivery->status, ['sent', 'cancelled'], true)) {
                return;
            }

            if ($this->deliveryIsInvalid($identity, $delivery, $webinar, $form, $participant)) {
                $this->cancel($delivery);

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

    private function deliveryIsInvalid(
        EmailDelivery $identity,
        EmailDelivery $delivery,
        ?Webinar $webinar,
        ?Form $form,
        ?Participant $participant,
    ): bool {
        $webinarStatuses = self::allowedWebinarStatusesFor($delivery->type);
        $webinarBoundType = $webinarStatuses !== [];

        if (blank($delivery->recipient_email)
            || blank($delivery->payload)
            || $delivery->expires_at?->isPast()
            || $delivery->type !== $identity->type) {
            return true;
        }

        // Known production delivery types are always webinar- and
        // participant-bound. Missing either authoritative parent fails closed.
        if ($webinarBoundType && (! $identity->webinar_id || ! $identity->participant_id)) {
            return true;
        }

        if ($identity->webinar_id && (
            ! $webinar
            || $delivery->webinar_id !== $webinar->id
            || $webinar->archived_at !== null
            || $webinar->deletion_started_at !== null
            || $webinar->retention_due_at === null
            || ! $webinar->retention_due_at->isFuture()
            || ! in_array($webinar->status, $webinarStatuses, true)
        )) {
            return true;
        }

        if ($delivery->type === ParticipantMagicLinkService::DELIVERY_TYPE) {
            if (! $webinar || ! $webinar->requiresVerification() || ! $form) {
                return true;
            }

            $form->setRelation('webinar', $webinar);

            if ($this->formIdFor($delivery) !== $form->id || ! $form->acceptsResponses()) {
                return true;
            }
        }

        // Privacy erasure clears both fields. A queued retry that wakes up later
        // must not reconstruct or send the erased message. Comparing the locked
        // current email also invalidates a delivery after an identity correction.
        return (bool) ($identity->participant_id && (
            ! $participant
            || $participant->trashed()
            || $participant->privacy_erased_at
            || $delivery->participant_id !== $participant->id
            || ! hash_equals(
                Str::lower(trim((string) $participant->email)),
                Str::lower(trim((string) $delivery->recipient_email)),
            )
        ));
    }

    private function formIdFor(EmailDelivery $delivery): ?int
    {
        $formId = $delivery->payload['form_id'] ?? null;

        return is_int($formId) && $formId > 0 ? $formId : null;
    }

    private function cancel(EmailDelivery $delivery): void
    {
        $delivery->update([
            'status' => 'cancelled',
            'processing_at' => null,
            'recipient_email' => null,
            'payload' => null,
            'last_error' => null,
        ]);
    }
}
