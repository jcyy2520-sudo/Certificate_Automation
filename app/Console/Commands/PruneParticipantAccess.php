<?php

namespace App\Console\Commands;

use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\ParticipantAccessToken;
use App\Services\ParticipantMagicLinkService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class PruneParticipantAccess extends Command
{
    protected $signature = 'security:prune-participant-access {--dry-run}';

    protected $description = 'Delete spent access credentials and abandoned unverified participant records';

    public function handle(): int
    {
        $expiredTokens = ParticipantAccessToken::query()
            ->where(fn ($query) => $query
                ->whereNotNull('used_at')
                ->orWhere('expires_at', '<=', now()))
            ->count();

        $cutoff = now()->subMinutes(max(
            60,
            (int) config('webinar.unverified_participant_retention_minutes', 1440),
        ));

        $abandoned = Participant::query()
            ->whereNull('email_verified_at')
            ->whereNull('verified_at')
            ->whereNull('privacy_erased_at')
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('submissions')
            ->whereDoesntHave('certificates')
            ->whereDoesntHave('accessTokens', fn ($query) => $query
                ->whereNull('used_at')
                ->where('expires_at', '>', now()))
            ->count();

        if ($this->option('dry-run')) {
            $this->info("{$expiredTokens} token(s) and {$abandoned} participant(s) would be deleted.");

            return self::SUCCESS;
        }

        $deletedTokens = ParticipantAccessToken::query()
            ->where(fn ($query) => $query
                ->whereNotNull('used_at')
                ->orWhere('expires_at', '<=', now()))
            ->delete();

        $deletedParticipants = 0;

        Participant::query()
            ->whereNull('email_verified_at')
            ->whereNull('verified_at')
            ->whereNull('privacy_erased_at')
            ->where('created_at', '<=', $cutoff)
            ->whereDoesntHave('submissions')
            ->whereDoesntHave('certificates')
            ->whereDoesntHave('accessTokens', fn ($query) => $query
                ->whereNull('used_at')
                ->where('expires_at', '>', now()))
            ->select('id')
            ->chunkById(100, function ($participants) use ($cutoff, &$deletedParticipants): void {
                foreach ($participants as $participant) {
                    $deleted = DB::transaction(function () use ($participant, $cutoff): bool {
                        $locked = Participant::withTrashed()
                            ->whereKey($participant->id)
                            ->lockForUpdate()
                            ->first();

                        if (! $locked
                            || $locked->trashed()
                            || $locked->email_verified_at
                            || $locked->verified_at
                            || $locked->privacy_erased_at
                            || $locked->created_at->isAfter($cutoff)
                            || $locked->submissions()->exists()
                            || $locked->certificates()->exists()
                            || $locked->accessTokens()
                                ->whereNull('used_at')
                                ->where('expires_at', '>', now())
                                ->exists()) {
                            return false;
                        }

                        EmailDelivery::query()
                            ->where('participant_id', $locked->id)
                            ->where('type', ParticipantMagicLinkService::DELIVERY_TYPE)
                            ->delete();
                        $locked->accessTokens()->delete();
                        $locked->forceDelete();

                        return true;
                    }, attempts: 3);

                    if ($deleted) {
                        $deletedParticipants++;
                    }
                }
            });

        $this->info("{$deletedTokens} token(s) and {$deletedParticipants} participant(s) deleted.");

        return self::SUCCESS;
    }
}
