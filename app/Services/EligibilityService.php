<?php

namespace App\Services;

use App\Models\EligibilityOverride;
use App\Models\EligibilityRule;
use App\Models\Participant;
use App\Models\Webinar;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class EligibilityService
{
    /** Stable rule snapshot; contains no participant identity or answers. */
    public function ruleSnapshot(Webinar $webinar): array
    {
        return $this->rulesFor($webinar)->where('is_required', true)->sortBy('requirement')
            ->map(fn ($rule) => ['requirement' => $rule->requirement, 'minimum_score' => $rule->minimum_score])
            ->values()->all();
    }

    /** One derived roster; manual exceptions never change form eligibility. */
    public function certificateParticipantsQuery(Webinar $webinar): Builder
    {
        return Participant::query()->select('participants.id')
            ->where('webinar_id', $webinar->id)->whereNull('privacy_erased_at')
            ->where(fn ($query) => $query
                ->whereIn('participants.id', $this->eligibleParticipantsQuery($webinar))
                ->orWhereHas('certificateApproval', fn ($approval) => $approval->where('source', 'manual')->whereNull('removed_at')));
    }

    public function canPrepareCertificate(Participant $participant): bool
    {
        return $this->evaluate($participant)['eligible'] || $this->hasManualRecipient($participant);
    }

    public function hasManualRecipient(Participant $participant): bool
    {
        $participant->loadMissing('certificateApproval');

        return $participant->certificateApproval?->source === 'manual'
            && $participant->certificateApproval->removed_at === null;
    }

    /**
     * @param  Collection<int, EligibilityRule>|null  $rules
     * @return array{eligible: bool, overridden: bool, requirements: array<string, bool>}
     */
    public function evaluate(Participant $participant, ?Collection $rules = null): array
    {
        $override = $this->activeOverride($participant);

        if ($override) {
            return [
                'eligible' => $override->decision === 'eligible',
                'overridden' => true,
                'requirements' => [],
            ];
        }

        $requirements = $this->requirementResults($participant, $rules);

        return [
            'eligible' => ! in_array(false, $requirements, true),
            'overridden' => false,
            'requirements' => $requirements,
        ];
    }

    /** Actual rule evidence, including when an organizer override takes precedence. */
    public function requirementResults(Participant $participant, ?Collection $rules = null): array
    {
        if ($rules === null) {
            $participant->loadMissing('webinar.eligibilityRules');
            $rules = $participant->webinar->eligibilityRules;
        }
        $participant->loadMissing('submissions.form');
        $requirements = [];
        foreach ($rules->where('is_required', true) as $rule) {
            $requirements[$rule->requirement] = $this->meets($participant, $rule);
        }

        return $requirements;
    }

    /** @param Collection<int, Participant> $participants */
    public function attachTo(Collection $participants, Webinar $webinar): void
    {
        if ($participants->isEmpty()) {
            return;
        }

        $participants->loadMissing([
            'submissions.form:id,type',
            'eligibilityOverrides' => fn ($query) => $query
                ->select(['id', 'participant_id', 'decision', 'expires_at', 'created_at'])
                ->where(fn ($active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                ->latest()
                ->orderByDesc('id'),
        ]);
        $rules = $this->rulesFor($webinar);

        foreach ($participants as $participant) {
            $participant->setAttribute('eligibility', $this->evaluate($participant, $rules));
        }
    }

    /** @return array<int, int> */
    public function eligibleParticipantIds(Webinar $webinar): array
    {
        return $this->eligibleParticipantsQuery($webinar)
            ->pluck('participants.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /** @return Builder<Participant> */
    public function eligibleParticipantsQuery(Webinar $webinar): Builder
    {
        $required = $this->rulesFor($webinar)->where('is_required', true);
        $at = now();

        return Participant::query()
            ->select('participants.id')
            ->where('participants.webinar_id', $webinar->id)
            ->where(function (Builder $eligible) use ($at, $required, $webinar): void {
                $eligible->where($this->latestActiveOverrideDecision($at), 'eligible')
                    ->orWhere(function (Builder $automatic) use ($at, $required, $webinar): void {
                        $automatic->whereNotExists(function ($overrides) use ($at): void {
                            $overrides->selectRaw('1')
                                ->from('eligibility_overrides')
                                ->whereColumn('eligibility_overrides.participant_id', 'participants.id')
                                ->where(fn ($active) => $active
                                    ->whereNull('eligibility_overrides.expires_at')
                                    ->orWhere('eligibility_overrides.expires_at', '>', $at));
                        });

                        foreach ($required as $rule) {
                            if ($rule->requirement === 'registration') {
                                $automatic->whereNotNull('participants.verified_at');

                                continue;
                            }
                            if ($rule->requirement === 'attendance') {
                                $automatic->whereNotNull('participants.checked_in_at');

                                continue;
                            }

                            $automatic->whereExists(function ($submissions) use ($rule, $webinar): void {
                                $submissions->selectRaw('1')
                                    ->from('submissions')
                                    ->join('forms', 'forms.id', '=', 'submissions.form_id')
                                    ->whereColumn('submissions.participant_id', 'participants.id')
                                    ->where('forms.webinar_id', $webinar->id)
                                    ->where('forms.type', $rule->requirement)
                                    ->where('submissions.status', 'submitted')
                                    ->when($rule->minimum_score !== null, fn ($query) => $query->where('submissions.score', '>=', (float) $rule->minimum_score));
                            });
                        }
                    });
            });
    }

    private function latestActiveOverrideDecision(CarbonInterface $at): Closure
    {
        return fn ($overrides) => $overrides
            ->select('decision')
            ->from('eligibility_overrides')
            ->whereColumn('eligibility_overrides.participant_id', 'participants.id')
            ->where(fn ($active) => $active
                ->whereNull('eligibility_overrides.expires_at')
                ->orWhere('eligibility_overrides.expires_at', '>', $at))
            ->orderByDesc('eligibility_overrides.created_at')
            ->orderByDesc('eligibility_overrides.id')
            ->limit(1);
    }

    /** @return Collection<int, EligibilityRule> */
    private function rulesFor(Webinar $webinar): Collection
    {
        $webinar->loadMissing('eligibilityRules');

        return $webinar->eligibilityRules;
    }

    private function activeOverride(Participant $participant): ?EligibilityOverride
    {
        $unexpired = fn (EligibilityOverride $override) => $override->expires_at === null || $override->expires_at->isFuture();

        if ($participant->relationLoaded('eligibilityOverrides')) {
            return $participant->eligibilityOverrides
                ->filter($unexpired)
                ->sortBy([['created_at', 'desc'], ['id', 'desc']])
                ->first();
        }

        return $participant->eligibilityOverrides()
            ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->orderByDesc('id')
            ->first();
    }

    private function meets(Participant $participant, EligibilityRule $rule): bool
    {
        if ($rule->requirement === 'registration') {
            return $participant->verified_at !== null;
        }

        if ($rule->requirement === 'attendance') {
            return $participant->checked_in_at !== null;
        }

        return $participant->submissions
            ->where('form.type', $rule->requirement)
            ->where('status', 'submitted')
            ->contains(function ($submission) use ($rule): bool {
                return $rule->minimum_score === null
                    || ($submission->score !== null && (float) $submission->score >= (float) $rule->minimum_score);
            });
    }
}
