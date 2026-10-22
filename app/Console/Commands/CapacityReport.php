<?php

namespace App\Console\Commands;

use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Import;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Throwable;

class CapacityReport extends Command
{
    protected $signature = 'ops:capacity';

    protected $description = 'Report configured queue depths, stuck work, storage configuration, and failure rates';

    public function handle(): int
    {
        $queueFailure = $this->reportQueues();

        $stuckProcessing = Certificate::query()->where('status', 'processing')->where('updated_at', '<', now()->subMinutes(10))->count();
        $stuckEmail = EmailDelivery::query()->whereNull('sent_at')->whereNull('failed_at')->where('status', '!=', 'cancelled')->where('scheduled_at', '<', now()->subMinutes(15))->count();
        $stalePending = Import::query()->where('status', Import::STATUS_PENDING)->where('created_at', '<', now()->subDay())->count();
        // last_error is encrypted: count the marker in PHP, not SQL.
        $unknownOutcome = EmailDelivery::query()->where('status', 'failed')->select(['id', 'last_error'])->lazyById(200)
            ->filter(fn ($delivery) => str_starts_with((string) $delivery->last_error, 'Provider outcome unknown'))->count();
        $failed24h = EmailDelivery::query()->where('status', 'failed')->where('failed_at', '>=', now()->subDay())->count();
        $sent24h = EmailDelivery::query()->where('status', 'sent')->where('sent_at', '>=', now()->subDay())->count();

        $this->info("Stuck certificate processing (>10m): {$stuckProcessing}");
        $this->info("Stuck email deliveries (>15m): {$stuckEmail}");
        $this->info("Stale pending imports (>24h): {$stalePending}");
        $this->info("Uncertain provider outcomes needing reconciliation: {$unknownOutcome}");
        $this->info("Email last 24h: {$sent24h} accepted, {$failed24h} failed");

        $this->info('Configured certificate disk: '.config('webinar.certificate_disk').' (configuration only; storage access not checked)');

        $failed = $queueFailure || $stuckProcessing > 0 || $stuckEmail > 0 || $unknownOutcome > 0;

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function reportQueues(): bool
    {
        $default = (string) config('queue.default');
        $email = (string) config('webinar.email.queue_connection');
        $queues = [
            [$default, (string) config("queue.connections.{$default}.queue", 'default')],
            [$email, (string) config('webinar.email.queue')],
            [$email, (string) config('accounts.email_queue')],
        ];
        $seen = [];
        $failed = false;
        $this->info('Configured queue depths (connection/queue):');

        foreach ($queues as [$connection, $queue]) {
            $label = $connection.'/'.$queue;
            if (isset($seen[$label])) {
                continue;
            }
            $seen[$label] = true;

            try {
                $driver = config("queue.connections.{$connection}.driver");
                if ($connection === '' || $queue === '' || ! is_string($driver)
                    || in_array($driver, ['sync', 'null', 'deferred', 'background'], true)) {
                    throw new RuntimeException('Queue depth is not available for this configuration.');
                }
                $depth = Queue::connection($connection)->size($queue);
                if (! is_int($depth) || $depth < 0) {
                    throw new RuntimeException('The queue backend returned no usable depth.');
                }
                $this->line("  {$label}: {$depth}");
            } catch (Throwable) {
                // Driver errors may contain endpoint credentials. Report the
                // missing metric without printing the exception payload.
                $this->warn("  {$label}: unavailable (queue depth could not be inspected)");
                $failed = true;
            }
        }

        return $failed;
    }
}
