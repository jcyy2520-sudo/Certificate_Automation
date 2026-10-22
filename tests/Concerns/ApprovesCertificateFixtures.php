<?php

namespace Tests\Concerns;

use App\Models\CertificateApproval;
use App\Models\Webinar;
use App\Services\EligibilityService;

trait ApprovesCertificateFixtures
{
    /** Explicit approval setup for legacy PDF/delivery tests; approval behavior has its own HTTP tests. */
    private function approveCertificateFixtures(Webinar $webinar): void
    {
        $eligibility = app(EligibilityService::class);
        foreach ($webinar->participants()->whereIn('participants.id', $eligibility->eligibleParticipantsQuery($webinar->fresh()))->get() as $participant) {
            CertificateApproval::query()->firstOrCreate(['participant_id' => $participant->id], [
                'approved_by' => $webinar->created_by,
                'approved_at' => now(),
                'eligibility_snapshot' => ['rules' => $eligibility->ruleSnapshot($webinar->fresh()), 'evaluation' => $eligibility->evaluate($participant)],
            ]);
        }
    }
}
