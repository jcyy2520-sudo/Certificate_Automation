<?php

namespace App\Jobs;

use App\Contracts\TransactionalMailer;
use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Form;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\EligibilityService;
use App\Services\Email\EmailSendBudget;
use App\Services\Email\PermanentDeliveryException;
use App\Services\Email\ProviderRateLimitException;
use App\Services\ParticipantMagicLinkService;
use App\Services\ParticipantStatusLinkService;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
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

    public int $retryDeadline = 0;

    public function __construct(public int $deliveryId)
    {
        $this->retryDeadline = now()->addDay()->timestamp;
        $this->onConnection(config('webinar.email.queue_connection'));
        $this->onQueue(config('webinar.email.queue'));
    }

    public function retryUntil(): \DateTimeInterface
    {
        // Older serialized jobs predate this property; keep them readable.
        return Carbon::createFromTimestamp($this->retryDeadline ?: now()->addDay()->timestamp);
    }

    /** @return list<string> */
    public static function allowedWebinarStatusesFor(string $deliveryType): array
    {
        return match ($deliveryType) {
            ParticipantMagicLinkService::DELIVERY_TYPE => ['published'],
            ParticipantStatusLinkService::DELIVERY_TYPE => ['published', 'completed'],
            'certificate' => ['published', 'completed'],
            'participant_message' => ['published', 'completed'],
            'certificate_test' => ['draft', 'published', 'completed'],
            default => [],
        };
    }

    public function handle(TransactionalMailer $mailer): void
    {
        $candidate = EmailDelivery::query()->find($this->deliveryId);
        if (! $candidate || in_array($candidate->status, ['sent', 'cancelled', 'failed'], true)) {
            return;
        }
        if ($candidate->attempts > 0 && ($candidate->first_attempted_at ?? $candidate->created_at)->lt(now()->subMinutes(25))) {
            $exception = new PermanentDeliveryException('Provider outcome unknown: the safe idempotency retry window expired. Reconcile provider logs before explicitly resending.');
            $this->failed($exception);
            $this->fail($exception);

            return;
        }
        try {
            app(EmailSendBudget::class)->reserve();
        } catch (\OverflowException) {
            $this->release(60);

            return;
        } catch (PermanentDeliveryException $exception) {
            $this->failed($exception);
            $this->fail($exception);

            return;
        }
        try {
            // Commit the attempt envelope before entering the transport
            // transaction. A killed worker cannot run catch/failure handlers,
            // and provider acceptance cannot be rolled back with our database.
            // Reacquire and revalidate every lifecycle lock before sending.
            foreach ([false, true] as $transport) {
                $available = DB::transaction(function () use ($mailer, $transport): bool {
                    $identity = EmailDelivery::query()
                        ->whereKey($this->deliveryId)
                        ->select(['webinar_id', 'participant_id', 'account_user_id', 'type', 'payload'])
                        ->firstOrFail();
                    // Account state is locked before the outbox row, matching
                    // approval/suspension and token consumption.
                    $account = $identity->account_user_id ? User::query()->whereKey($identity->account_user_id)->lockForUpdate()->first() : null;
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
                    $delivery = EmailDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->firstOrFail();

                    if (in_array($delivery->status, ['sent', 'cancelled', 'failed'], true)) {
                        return false;
                    }

                    if ($identity->account_user_id && (! $account
                        || (int) ($identity->payload['account_version'] ?? 0) !== (int) $account->auth_version
                        || $delivery->recipient_email !== $account->email
                        || ! match ($delivery->type) {
                            'account_verification' => $account->approval_status === 'pending_verification',
                            'account_setup', 'account_reset' => $account->approval_status === 'approved' && $account->is_active,
                            'account_rejection' => $account->approval_status === 'rejected',
                            default => false,
                        })) {
                        $this->cancel($delivery);

                        return false;
                    }

                    if (in_array($delivery->status, ['sent', 'cancelled'], true)) {
                        return false;
                    }

                    if ($this->deliveryIsInvalid($identity, $delivery, $webinar, $form, $participant)) {
                        $this->cancel($delivery);

                        return false;
                    }

                    if ($delivery->attempts > 0 && ($delivery->first_attempted_at ?? $delivery->created_at)->lt(now()->subMinutes(25))) {
                        throw new PermanentDeliveryException('Provider outcome unknown: the safe idempotency retry window expired. Reconcile provider logs before explicitly resending.');
                    }

                    if (! $transport) {
                        $delivery->update([
                            'processing_at' => now(),
                            'attempts' => (int) $delivery->attempts + 1,
                            'first_attempted_at' => $delivery->first_attempted_at ?? now(),
                            // A killed worker may never reach failed(), and a
                            // later Worker expiry can bypass handle() entirely.
                            'last_error' => 'Provider outcome unknown: a send attempt has no durable provider acknowledgement. Reconcile provider logs before explicitly resending.',
                        ]);

                        return true;
                    }

                    $delivery->update(['status' => 'processing', 'processing_at' => now()]);

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
                        'payload' => null,
                    ]);

                    if ($delivery->certificate_id) {
                        Certificate::query()
                            ->whereKey($delivery->certificate_id)
                            ->whereNull('sent_at')
                            ->update(['sent_at' => now()]);
                    }

                    return true;
                }, attempts: 3);
                if (! $available) {
                    return;
                }
            }
        } catch (Throwable $exception) {
            // The send transaction rolled back, but its attempt envelope was
            // already committed. Record only the error, without charging a
            // second attempt, then preserve the job's existing retry handling.
            $this->recordFailure($exception);

            if ($exception instanceof PermanentDeliveryException
                || (int) EmailDelivery::query()->whereKey($this->deliveryId)->value('attempts') >= (int) config('webinar.email.max_attempts', 3)) {
                $this->failed($exception);
                $this->fail($exception);

                return;
            }

            if ($exception instanceof ProviderRateLimitException) {
                $this->release($exception->retryAfter);

                return;
            }

            throw $exception;
        }
    }

    /**
     * Record a failed delivery's error without disturbing the delivery's
     * pending state: the job still owns the retry, and the claim that stops a
     * second copy of the same certificate from being sent must stay held.
     */
    private function recordFailure(Throwable $exception): void
    {
        $message = (string) $exception->getMessage();
        // Only a classified provider refusal proves this attempt was rejected.
        // Other failures include lost acknowledgements and database errors
        // after acceptance, so keep them behind the manual reconciliation gate.
        if (! $exception instanceof PermanentDeliveryException && ! $exception instanceof ProviderRateLimitException) {
            $message = 'Provider outcome unknown (send may have been accepted). Reconcile by provider message log before resending: '.$message;
        }

        try {
            DB::transaction(function () use ($message): void {
                $delivery = EmailDelivery::query()->whereKey($this->deliveryId)->lockForUpdate()->first();

                if (! $delivery || in_array($delivery->status, ['sent', 'cancelled'], true)) {
                    return;
                }

                $delivery->update([
                    'last_error' => Str::limit($message, 2000, ''),
                ]);
            }, attempts: 3);
        } catch (Throwable) {
            // A bookkeeping failure must never mask the provider failure.
        }
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

            if (! $delivery || in_array($delivery->status, ['sent', 'cancelled'], true)) {
                return;
            }

            if ($this->deliveryIsInvalid($identity, $delivery, $webinar, $form, $participant)) {
                $this->cancel($delivery);

                return;
            }

            $message = (string) $exception?->getMessage();
            // Worker expiry/attempt exhaustion can invoke this before handle().
            // A durable attempted envelope without a classified clean refusal
            // still needs reconciliation, whatever exception the Worker supplies.
            if ($exception instanceof ConnectionException
                || str_starts_with((string) $delivery->last_error, 'Provider outcome unknown')
                || ((int) $delivery->attempts > 0 && blank($delivery->last_error))) {
                if (! str_starts_with($message, 'Provider outcome unknown')) {
                    $message = 'Provider outcome unknown (send may have been accepted). Reconcile by provider message log before resending: '.$message;
                }
            }

            $delivery->update([
                'status' => 'failed',
                'failed_at' => now(),
                'last_error' => Str::limit($message, 2000, ''),
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

        if ($webinarBoundType && (! $identity->webinar_id || ($delivery->type !== 'certificate_test' && ! $identity->participant_id))) {
            return true;
        }

        if ($identity->webinar_id && (
            ! $webinar
            || $delivery->webinar_id !== $webinar->id
            || $webinar->archived_at !== null
            || $webinar->deletion_started_at !== null
            || ($webinar->retention_due_at === null && $delivery->type !== 'certificate_test')
            || ($webinar->retention_due_at !== null && ! $webinar->retention_due_at->isFuture())
            || ! in_array($webinar->status, $webinarStatuses, true)
        )) {
            return true;
        }

        if ($webinar && in_array($delivery->type, ['certificate', 'certificate_test', 'participant_message'], true)
            && $webinar->creator && ! $webinar->creator->canAccessPlatform()) {
            return true;
        }

        if ($delivery->type === 'participant_message') {
            $actor = $delivery->messageBatch?->creator;
            if (! $webinar || $webinar->isUploadOnly() || ! $participant || $participant->webinar_id !== $webinar->id
                || ! $actor?->canAccessPlatform() || (! $actor->isAdministrator() && $webinar->created_by !== $actor->id)
                || ! app(EligibilityService::class)->evaluate($participant)['eligible']) {
                return true;
            }
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

        if ($delivery->type === 'certificate' && $delivery->certificate_id) {
            $certificate = Certificate::query()->whereKey($delivery->certificate_id)->first();
            if (! $certificate || $certificate->status !== 'issued' || $certificate->revoked_at !== null
                || $certificate->participant_id !== $participant?->id
                || $certificate->webinar_id !== $webinar?->id
                || $certificate->recipient_name !== $participant?->full_name) {
                return true;
            }
        }

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
