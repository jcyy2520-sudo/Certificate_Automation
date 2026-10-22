<?php

namespace App\Jobs;

use App\Models\Certificate;
use App\Models\Participant;
use App\Services\CertificateService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class IssueSelectedCertificate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public int $timeout = 120;

    public function __construct(public int $participantId) {}

    public function handle(CertificateService $service): void
    {
        $participant = Participant::query()->findOrFail($this->participantId);
        $service->issue($participant);
    }

    /** Never leave the studio showing an endless queued state after all retries fail. */
    public function failed(?Throwable $exception): void
    {
        Certificate::query()
            ->where('participant_id', $this->participantId)
            ->where('status', 'processing')
            // Bulk updates bypass Certificate's saving event, so release its
            // active-issuance claim explicitly. Keep the file inventory for
            // retention/erasure if a terminated worker left an artifact.
            ->update(['status' => 'failed', 'issuance_key' => null, 'updated_at' => now()]);
    }
}
