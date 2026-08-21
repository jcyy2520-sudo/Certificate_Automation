<?php

namespace App\Jobs;

use App\Models\CertificateBatch;
use App\Models\Webinar;
use App\Services\CertificateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Throwable;

class IssueCertificateBatch implements ShouldQueue
{
    use Queueable;

    public const TIMEOUT = 1800;

    public int $tries = 3;

    public int $timeout = self::TIMEOUT;

    public function __construct(public int $batchId) {}

    /**
     * The queue visibility timeout is also checked by security:check. This
     * lock is a second line of defence if a worker is accidentally started
     * with incompatible retry settings.
     *
     * @return array<int, WithoutOverlapping>
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('certificate-batch:'.$this->batchId))
                ->releaseAfter(60)
                ->expireAfter(self::TIMEOUT + 300),
        ];
    }

    public function handle(CertificateService $certificates): void
    {
        $webinarId = CertificateBatch::query()->whereKey($this->batchId)->value('webinar_id');

        if (! $webinarId) {
            return;
        }

        $batch = DB::transaction(function () use ($webinarId): ?CertificateBatch {
            $webinar = Webinar::query()->whereKey($webinarId)->lockForUpdate()->first();
            $batch = CertificateBatch::query()->whereKey($this->batchId)->lockForUpdate()->first();

            if (! $webinar
                || $webinar->deletion_started_at
                || ! $batch
                || in_array($batch->status, ['completed', 'cancelled'], true)) {
                return null;
            }

            $batch->setRelation('webinar', $webinar);

            return $batch;
        }, attempts: 3);

        if (! $batch) {
            return;
        }

        $certificates->issueBatch($batch);
    }

    public function failed(?Throwable $exception): void
    {
        CertificateBatch::query()
            ->whereKey($this->batchId)
            ->whereNotIn('status', ['completed', 'cancelled'])
            ->update([
                'status' => 'failed',
                'completed_at' => now(),
            ]);
    }
}
