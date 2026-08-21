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
    /**
     * Decide whether a participant has met the webinar's certificate requirements.
     *
     * Pass $rules when evaluating many participants of the same webinar so the rule
     * set is fetched once rather than once per participant. When the participant's
     * `submissions.form` and `eligibilityOverrides` relations are already loaded this
     * runs entirely in memory and issues no queries at all.
     *
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

        if ($rules === null) {
            $participant->loadMissing('webinar.eligibilityRules');
            $rules = $participant->webinar->eligibilityRules;
        }

        $participant->loadMissing('submissions.form');
        $requirements = [];

        foreach ($rules->where('is_required', true) as $rule) {
            $requirements[$rule->requirement] = $this->meets($participant, $rule);
        }

        return [
            'eligible' => ! in_array(false, $requirements, true),
            'overridden' => false,
            'requirements' => $requirements,
        ];
    }

    /**
     * Attach an `eligibility` attribute to each participant, loading what the
     * evaluation needs in a fixed number of queries rather than one set per row.
     *
     * @param  Collection<int, Participant>  $participants
     */
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

    /**
     * The ids of every participant of this webinar who currently meets its requirements.
     *
     * Kept for batch callers that need ids. Paginated screens should compose the
     * query below directly so they never materialize an event-sized array.
     *
     * @return array<int, int>
     */
    public function eligibleParticipantIds(Webinar $webinar): array
    {
        return $this->eligibleParticipantsQuery($webinar)
            ->pluck('participants.id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * A database-native eligibility query that can be counted or nested directly
     * inside a paginated participant query. No participant ids are hydrated in PHP,
     * so list navigation stays bounded as an event grows.
     *
     * @return Builder<Participant>
     */
    public function eligibleParticipantsQuery(Webinar $webinar): Builder
    {
        $required = $this->rulesFor($webinar)->where('is_required', true);
        $at = now();

        return Participant::query()
            ->select('participants.id')
            ->where('participants.webinar_id', $webinar->id)
            ->where(function (Builder $eligible) use ($at, $required, $webinar): void {
                // A current administrator decision wins over the automatic rules.
                $eligible->where($this->latestActiveOverrideDecision($at), 'eligible')
                    ->orWhere(function (Builder $automatic) use ($at, $required, $webinar): void {
                        // Automatic rules apply only when there is no current override.
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

                            $automatic->whereExists(function ($submissions) use ($rule, $webinar): void {
                                $submissions->selectRaw('1')
                                    ->from('submissions')
                                    ->join('forms', 'forms.id', '=', 'submissions.form_id')
                                    ->whereColumn('submissions.participant_id', 'participants.id')
                                    ->where('forms.webinar_id', $webinar->id)
                                    ->where('forms.type', $rule->requirement)
                                    ->where('submissions.status', 'submitted')
                                    ->when($rule->minimum_score !== null, fn ($query) => $query
                                        ->where('submissions.score', '>=', (float) $rule->minimum_score));
                            });
                        }
                    });
            });
    }

    /** A correlated scalar subquery containing the latest current override. */
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

    /**
     * The override in force, preferring the already-loaded relation so a batch
     * evaluation does not fire one query per participant.
     */
    private function activeOverride(Participant $participant): ?EligibilityOverride
    {
        $unexpired = fn (EligibilityOverride $override) => $override->expires_at === null || $override->expires_at->isFuture();

        if ($participant->relationLoaded('eligibilityOverrides')) {
            return $participant->eligibilityOverrides
                ->filter($unexpired)
                ->sortBy([
                    ['created_at', 'desc'],
                    ['id', 'desc'],
                ])
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

        return $participant->submissions
            ->where('form.type', $rule->requirement)
            ->where('status', 'submitted')
            ->contains(function ($submission) use ($rule): bool {
                return $rule->minimum_score === null
                    || ($submission->score !== null && (float) $submission->score >= (float) $rule->minimum_score);
            });
    }
}
