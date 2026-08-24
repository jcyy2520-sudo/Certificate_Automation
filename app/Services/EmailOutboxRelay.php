<?php

namespace App\Services;

use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class EmailOutboxRelay
{
    /**
     * Dispatch due outbox rows that were not handed to the queue, or whose
     * previous relay lease expired. The job remains the authority that validates
     * privacy/lifecycle state immediately before contacting the provider.
     *
     * @return array{eligible: int, dispatched: int, failed: int}
     */
    public function relay(int $limit, int $staleSeconds, bool $dryRun = false): array
    {
        $cutoff = now()->subSeconds($staleSeconds);
        $candidateIds = $this->dueQuery($cutoff)
            ->limit($limit)
            ->pluck('id');

        if ($dryRun) {
            return ['eligible' => $candidateIds->count(), 'dispatched' => 0, 'failed' => 0];
        }

        $dispatched = 0;
        $failed = 0;

        foreach ($candidateIds as $deliveryId) {
            $claimed = DB::transaction(function () use ($deliveryId, $cutoff): bool {
                $delivery = EmailDelivery::query()->whereKey($deliveryId)->lockForUpdate()->first();

                if (! $delivery || ! $this->isDue($delivery, $cutoff)) {
                    return false;
                }

                // processing_at doubles as a short outbox relay lease while the
                // row is still pending. A process death is recovered after the
                // configured stale interval; duplicate jobs remain harmless.
                $delivery->update(['processing_at' => now()]);

                return true;
            }, attempts: 3);

            if (! $claimed) {
                continue;
            }

            try {
                SendTransactionalEmail::dispatch((int) $deliveryId);
                $dispatched++;
            } catch (Throwable $exception) {
                EmailDelivery::query()
                    ->whereKey($deliveryId)
                    ->where('status', 'pending')
                    ->update(['processing_at' => null]);
                report($exception);
                $failed++;
            }
        }

        return [
            'eligible' => $candidateIds->count(),
            'dispatched' => $dispatched,
            'failed' => $failed,
        ];
    }

    /** @return Builder<EmailDelivery> */
    private function dueQuery($cutoff): Builder
    {
        return EmailDelivery::query()
            ->where('status', 'pending')
            ->whereNotNull('recipient_email')
            ->whereNotNull('payload')
            ->where(fn ($query) => $query
                ->whereNull('scheduled_at')
                ->orWhere('scheduled_at', '<=', now()))
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->where(fn ($query) => $query
                ->whereNull('processing_at')
                ->orWhere('processing_at', '<=', $cutoff))
            ->orderBy('id');
    }

    private function isDue(EmailDelivery $delivery, $cutoff): bool
    {
        return $delivery->status === 'pending'
            && filled($delivery->recipient_email)
            && filled($delivery->payload)
            && ($delivery->scheduled_at === null || ! $delivery->scheduled_at->isFuture())
            && ($delivery->expires_at === null || $delivery->expires_at->isFuture())
            && ($delivery->processing_at === null || ! $delivery->processing_at->isAfter($cutoff));
    }
}
