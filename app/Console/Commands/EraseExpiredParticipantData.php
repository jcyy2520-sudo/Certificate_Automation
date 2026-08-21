<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\ParticipantPrivacyService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class EraseExpiredParticipantData extends Command
{
    protected $signature = 'privacy:erase-expired-participants
                            {--dry-run : Report records without changing them}';

    protected $description = 'Erase participant personal data after each webinar retention period';

    public function handle(ParticipantPrivacyService $privacy): int
    {
        $erased = 0;
        $erasedByWebinar = [];
        $failedErasures = 0;
        $missingDeadlines = Webinar::query()
            ->whereNull('retention_due_at')
            ->whereExists(function ($participants): void {
                $participants->selectRaw('1')
                    ->from('participants')
                    ->whereColumn('participants.webinar_id', 'webinars.id')
                    ->whereNull('participants.privacy_erased_at');
            })
            ->pluck('id');

        foreach ($missingDeadlines as $webinarId) {
            Log::critical('Participant data has no committed retention deadline.', [
                'webinar_id' => $webinarId,
                'command' => $this->getName(),
            ]);
            $this->error("Webinar {$webinarId} contains participant data but has no retention deadline.");
        }

        Webinar::query()
            ->whereNotNull('retention_due_at')
            ->where('retention_due_at', '<=', now())
            ->eachById(function (Webinar $webinar) use (&$erased, &$erasedByWebinar, &$failedErasures, $privacy): void {
                $webinar->participants()
                    ->withTrashed()
                    ->whereNull('privacy_erased_at')
                    ->chunkById(100, function ($participants) use (&$erased, &$erasedByWebinar, &$failedErasures, $privacy): void {
                        foreach ($participants as $participant) {
                            if ($this->option('dry-run')) {
                                $erased++;

                                continue;
                            }

                            try {
                                $privacy->erase($participant);
                            } catch (Throwable) {
                                $failedErasures++;
                                Log::critical('An overdue participant record could not be erased.', [
                                    'webinar_id' => $participant->webinar_id,
                                    'participant_id' => $participant->id,
                                ]);
                                $this->error(
                                    "Participant {$participant->id} in webinar {$participant->webinar_id} could not be erased.",
                                );

                                continue;
                            }

                            $erased++;
                            $erasedByWebinar[$participant->webinar_id] = ($erasedByWebinar[$participant->webinar_id] ?? 0) + 1;
                        }
                    });
            });

        if (! $this->option('dry-run')) {
            foreach ($erasedByWebinar as $webinarId => $count) {
                // Aggregate evidence proves the retention run occurred without
                // recreating the participant identifier erased moments earlier.
                AuditLog::query()->create([
                    'action' => 'privacy.retention_erasure_completed',
                    'metadata' => ['webinar_id' => $webinarId, 'erased_count' => $count],
                ]);
            }
        }

        $verb = $this->option('dry-run') ? 'would be erased' : 'erased';
        $this->info("{$erased} participant record(s) {$verb}.");

        if ($failedErasures > 0) {
            $this->error($failedErasures.' overdue participant record(s) require retry or operator intervention.');
        }

        return $missingDeadlines->isEmpty() && $failedErasures === 0
            ? self::SUCCESS
            : self::FAILURE;
    }
}
