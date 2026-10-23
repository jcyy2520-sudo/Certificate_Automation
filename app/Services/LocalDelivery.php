<?php

namespace App\Services;

use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Throwable;

/** Process only newly requested local work; never drain the existing outbox. */
final class LocalDelivery
{
    public function enabled(): bool
    {
        return app()->environment(['local', 'testing']) && config('operations.local_immediate_delivery', false);
    }

    public function dispatch(object $job): void
    {
        if ($this->enabled()) {
            set_time_limit(max(120, (int) ($job->timeout ?? 120)));
            Bus::dispatchSync($job);
        } else {
            Bus::dispatch($job);
        }
    }

    public function send(EmailDelivery $delivery): void
    {
        set_time_limit(1800);
        // Use the same authorization, eligibility, quota and idempotency checks
        // as the email worker, after the creating transaction has committed.
        $job = new SendTransactionalEmail($delivery->id);
        try {
            app()->call([$job, 'handle']);
        } catch (Throwable) {
            // The existing handler records the transport outcome. A local
            // click never schedules an automatic retry or starts a worker.
        }
        $delivery->refresh();
        if ($delivery->status === 'pending') {
            $job->failed(new RuntimeException($delivery->last_error ?: 'Email was not sent. Check provider configuration and availability before retrying.'));
        }
    }
}
