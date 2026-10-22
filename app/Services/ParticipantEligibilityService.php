<?php

namespace App\Services;

use App\Models\EmailDelivery;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Participant;
use App\Models\Webinar;
use Illuminate\Support\Collection;

/** A presentation of existing evidence; never grants or overrides eligibility. */
class ParticipantEligibilityService
{
    public const LABELS = [
        'registration' => 'Registration', 'pretest' => 'Pre-Test',
        'posttest' => 'Post-Test', 'evaluation' => 'Evaluation',
        'attendance' => 'Attendance',
    ];

    public function __construct(private EligibilityService $eligibility) {}

    /** Load bounded roster pages together, keeping private answer payloads out. */
    public function prepare(Collection $participants, Webinar $webinar): void
    {
        if ($participants->isEmpty()) {
            return;
        }
        $webinar->loadMissing(['eligibilityRules', 'imports:id,webinar_id,status,committed_at']);
        $participants->loadMissing([
            'submissions' => fn ($q) => $q->select(['id', 'form_id', 'participant_id', 'status', 'score', 'submitted_at', 'metadata']),
            'submissions.form:id,webinar_id,type', 'eligibilityOverrides', 'certificates', 'eligibilityReviews', 'eligibilityDecisions',
        ]);
        $hashes = $participants->filter(fn ($p) => filled($p->email))->map(fn ($p) => ImportIssue::emailHash($p->email))->all();
        $issues = ImportIssue::query()->where('webinar_id', $webinar->id)->whereNull('resolved_at')->whereIn('normalized_email_hash', $hashes)
            ->with('import.form:id,type,webinar_id')->orderBy('id')->get()->groupBy('normalized_email_hash');
        $certificateIds = $participants->flatMap(fn ($p) => $p->certificates->pluck('id'));
        $deliveries = EmailDelivery::query()->whereIn('certificate_id', $certificateIds)->where('type', 'certificate')
            ->latest('id')->get(['id', 'certificate_id', 'status', 'provider', 'attempts', 'delivery_outcome'])->groupBy('certificate_id');
        foreach ($participants as $participant) {
            $participant->setRelation('webinar', $webinar);
            $participant->setRelation('feedbackIssues', $issues->get(ImportIssue::emailHash((string) $participant->email), collect()));
            $participant->setRelation('feedbackDeliveries', $deliveries);
        }
    }

    /** No answers, raw imports, other identities or private organizer notes. */
    public function summary(Participant $participant): array
    {
        $participant->loadMissing(['webinar.eligibilityRules', 'submissions.form:id,webinar_id,type',
            'eligibilityOverrides', 'certificates', 'eligibilityReviews', 'eligibilityDecisions']);
        $webinar = $participant->webinar;
        $rules = $webinar->eligibilityRules->keyBy('requirement');
        $evaluation = $this->eligibility->evaluate($participant);
        $requirements = $this->eligibility->requirementResults($participant);
        $issues = $this->issues($participant);
        $activities = [];
        foreach (self::LABELS as $type => $label) {
            if ($type === 'attendance' && ! ($rules[$type]->is_required ?? false) && ! $participant->checked_in_at) {
                continue;
            }
            $records = $participant->submissions->filter(fn ($s) => $s->form?->webinar_id === $webinar->id && $s->form?->type === $type && $s->status === 'submitted');
            $marker = match ($type) {
                'registration' => $participant->verified_at,
                'attendance' => $participant->checked_in_at,
                default => null,
            };
            $found = $marker !== null || ($type !== 'registration' && $type !== 'attendance' && $records->isNotEmpty());
            $held = $issues->contains(fn ($issue) => $issue->import?->form?->type === $type);
            $required = (bool) ($rules[$type]->is_required ?? false);
            $met = $requirements[$type] ?? null;
            $activities[] = [
                'type' => $type, 'label' => $label, 'required' => $required,
                'state' => $held || ($type === 'registration' && ! $found && $records->isNotEmpty()) ? 'Under Review' : ($found ? 'Completed' : 'Not Found'),
                'met' => $met, 'minimum_score' => $rules[$type]->minimum_score ?? null,
                'detail' => $held ? 'A record for this email is held for verification.'
                    : ($found && $met === false ? 'A record is present, but it does not satisfy the configured requirement.'
                        : (! $found ? 'No verified record is currently matched. This alone does not prove you did not submit.' : 'Verified in the webinar records.')),
                'marker_at' => $marker?->toIso8601String(),
                'records' => $records->map(fn ($s) => [
                    'reference' => 'S-'.$s->id, 'recorded_at' => $s->submitted_at?->toIso8601String(),
                    'timestamp_kind' => ($s->metadata['timestamp_source'] ?? '') === 'import_time' ? 'Import recorded at (original submission time unavailable)' : 'Submission recorded at',
                    'source' => ($s->metadata['source'] ?? '') === 'csv_import' ? 'Imported response' : 'System form',
                    'import_reference' => isset($s->metadata['import_id']) ? 'I-'.$s->metadata['import_id'].' / row '.($s->metadata['import_row_number'] ?? '?') : null,
                    'score' => $required && ($rules[$type]->minimum_score ?? null) !== null ? $s->score : null,
                    'match' => 'Matched to your participant record by email',
                ])->values()->all(),
            ];
        }
        $fingerprint = $this->fingerprint($participant, $issues);
        $latestDecision = $participant->eligibilityDecisions->sortByDesc('id')->first();
        $pending = $participant->eligibilityReviews->contains('status', 'pending');
        $final = ! $evaluation['eligible'] && ! $pending && $issues->isEmpty()
            && $latestDecision && hash_equals($latestDecision->evidence_fingerprint, $fingerprint)
            && ! $this->hasUnfinishedImports($webinar);
        $status = $evaluation['eligible'] ? 'eligible' : ($final ? 'not_eligible' : 'incomplete');
        $certificate = $participant->certificates->whereNull('revoked_at')->sortByDesc('id')->first();
        $delivery = $certificate ? ($participant->relationLoaded('feedbackDeliveries') ? $participant->getRelation('feedbackDeliveries')->get($certificate->id)?->first()
            : EmailDelivery::query()->where('certificate_id', $certificate->id)->where('type', 'certificate')->latest('id')->first()) : null;

        return [
            'status' => $status, 'label' => match ($status) {
                'eligible' => 'Eligible', 'not_eligible' => 'Not Eligible', default => 'Incomplete / Under Review'
            },
            'explanation' => $evaluation['overridden'] ? 'The organizer has recorded an explicit eligibility decision. The checklist below preserves the actual submission evidence.'
                : ($evaluation['eligible'] ? 'All currently required conditions have been verified.'
                    : ($final ? 'The organizer has finalized the review of the current records against the published requirements.'
                        : 'Some required records are missing, unmatched or awaiting verification. This is not a final ineligibility decision.')),
            'activities' => $activities, 'evaluation' => $evaluation, 'fingerprint' => $fingerprint,
            'final_reason' => $final ? $latestDecision->reason : null, 'pending_review' => $pending,
            'certificate' => $certificate?->isPubliclyValid() ? $certificate : null,
            'certificate_status' => match ($certificate?->status) {
                'issued' => 'Generated', 'processing' => 'Processing', 'failed' => 'Generation failed', default => 'Not generated'
            },
            'delivery_status' => $delivery?->statusLabel() ?? 'Not queued',
            'missing' => collect($activities)->filter(fn ($a) => $a['required'] && $a['met'] !== true)->pluck('label')->all(),
        ];
    }

    public function issues(Participant $participant): Collection
    {
        if ($participant->relationLoaded('feedbackIssues')) {
            return $participant->getRelation('feedbackIssues');
        }
        if (! filled($participant->email)) {
            return collect();
        }

        return ImportIssue::query()->where('webinar_id', $participant->webinar_id)->whereNull('resolved_at')
            ->where('normalized_email_hash', ImportIssue::emailHash($participant->email))
            ->with('import.form:id,type,webinar_id')->orderBy('id')->get();
    }

    public function hasUnfinishedImports(Webinar $webinar): bool
    {
        return $webinar->importIssues()->whereNull('resolved_at')->exists()
            || $webinar->imports()->where(fn ($q) => $q->whereIn('status', [Import::STATUS_PENDING, Import::STATUS_PREVIEWED])
                ->orWhere('column_map->cleanup_pending', true))->exists();
    }

    private function fingerprint(Participant $participant, Collection $issues): string
    {
        $participant->webinar->loadMissing('imports:id,webinar_id,status,committed_at');
        // Changes to evidence, rules or overrides invalidate a previous final decision.
        $evidence = [$participant->id, $participant->email_normalized, $participant->full_name, $participant->verified_at?->toIso8601String(), $participant->checked_in_at?->toIso8601String(),
            $this->eligibility->ruleSnapshot($participant->webinar),
            $participant->submissions->sortBy('id')->map(fn ($s) => [$s->id, $s->form_id, $s->status, $s->score, $s->submitted_at?->toIso8601String()])->values()->all(),
            $participant->eligibilityOverrides->sortBy('id')->map(fn ($o) => [$o->id, $o->decision, $o->expires_at?->toIso8601String(), $o->expires_at === null || $o->expires_at->isFuture()])->values()->all(),
            $issues->pluck('id')->all(),
            $participant->webinar->imports->sortBy('id')->map(fn ($i) => [$i->id, $i->status, $i->committed_at?->toIso8601String()])->values()->all(),
        ];

        return hash_hmac('sha256', json_encode($evidence, JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
