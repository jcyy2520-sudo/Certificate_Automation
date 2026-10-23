<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EligibilityDecision;
use App\Models\EligibilityReview;
use App\Models\Form;
use App\Models\ImportIssue;
use App\Models\Participant;
use App\Models\ParticipantMessageBatch;
use App\Models\Webinar;
use App\Policies\WebinarPolicy;
use App\Services\AuditService;
use App\Services\CsvImportService;
use App\Services\EligibilityReviewFiles;
use App\Services\EligibilityService;
use App\Services\LocalDelivery;
use App\Services\NotificationService;
use App\Services\ParticipantEligibilityService;
use App\Services\ParticipantStatusLinkService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ParticipantEligibilityController extends Controller
{
    public function selectWebinar(Request $request)
    {
        $data = $request->validate(['webinar' => ['required', 'integer', 'min:1']]);
        $webinar = Webinar::query()->visibleTo($request->user())->findOrFail($data['webinar']);
        abort_unless(app(WebinarPolicy::class)->permission($request->user(), $webinar, 'manage_participants'), 403);

        return redirect()->route('admin.webinars.eligibility.index', $webinar);
    }

    public function index(Request $request, Webinar $webinar, EligibilityService $rules, ParticipantEligibilityService $feedback)
    {
        $data = $request->session()->get('eligibility_filters.'.$webinar->id, []);
        if ($request->has('status')) {
            $data['status'] = $request->validate(['status' => ['nullable', 'in:eligible,incomplete,not_eligible,review']])['status'] ?? '';
        }
        $query = $webinar->participants()->whereNull('privacy_erased_at');
        if (filled($data['search'] ?? null)) {
            $query->where(fn ($q) => $q->whereLike('full_name', '%'.$data['search'].'%')->orWhereLike('email', '%'.$data['search'].'%'));
        }
        $status = $data['status'] ?? '';
        if ($status === 'eligible') {
            $query->whereIn('id', $rules->eligibleParticipantsQuery($webinar));
        } elseif ($status === 'review') {
            $query->whereHas('eligibilityReviews', fn ($q) => $q->where('status', 'pending'));
        } elseif (in_array($status, ['incomplete', 'not_eligible'], true)) {
            $finalIds = [];
            $webinar->participants()->whereNull('privacy_erased_at')->whereHas('eligibilityDecisions')
                ->with($this->relations())->chunkById(100, function ($participants) use ($feedback, &$finalIds): void {
                    if ($participants->isNotEmpty()) {
                        $feedback->prepare($participants, $participants->first()->webinar);
                    }
                    foreach ($participants as $participant) {
                        if ($feedback->summary($participant)['status'] === 'not_eligible') {
                            $finalIds[] = $participant->id;
                        }
                    }
                });
            $status === 'not_eligible' ? $query->whereIn('id', $finalIds)
                : $query->whereNotIn('id', $rules->eligibleParticipantsQuery($webinar))->whereNotIn('id', $finalIds);
        }
        $participants = $query->with($this->relations())->orderBy('id')->paginate(50)->withQueryString();
        $feedback->prepare($participants->getCollection(), $webinar);
        foreach ($participants as $participant) {
            $participant->feedback = $feedback->summary($participant);
        }
        $webinars = Webinar::query()->visibleTo($request->user())->where('creation_mode', 'standard')->orderBy('title')->get(['id', 'title', 'created_by'])
            ->filter(fn ($w) => app(WebinarPolicy::class)->permission($request->user(), $w, 'manage_participants'));

        return view('admin.webinars.eligibility.index', ['webinar' => $webinar, 'participants' => $participants, 'webinars' => $webinars, 'filters' => $data]);
    }

    public function filter(Request $request, Webinar $webinar)
    {
        $data = $request->validate(['search' => ['nullable', 'string', 'max:180'], 'status' => ['nullable', 'in:eligible,incomplete,not_eligible,review']]);
        $request->session()->put('eligibility_filters.'.$webinar->id, $data);

        return redirect()->route('admin.webinars.eligibility.index', $webinar);
    }

    public function show(Webinar $webinar, Participant $participant, ParticipantEligibilityService $feedback)
    {
        abort_unless($participant->webinar_id === $webinar->id && ! $participant->privacy_erased_at, 404);

        return view('admin.webinars.eligibility.show', [
            'webinar' => $webinar, 'participant' => $participant, 'summary' => $feedback->summary($participant),
            'issues' => $feedback->issues($participant), 'reviews' => $participant->eligibilityReviews()->latest('id')->get(),
            'history' => $webinar->emailDeliveries()->where('participant_id', $participant->id)->where('type', 'eligibility_notification')->latest('id')->limit(20)->get(),
        ]);
    }

    public function finalize(Request $request, Webinar $webinar, Participant $participant, ParticipantEligibilityService $feedback, AuditService $audit)
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:10', 'max:1500'], 'verification_complete' => ['accepted']]);
        DB::transaction(function () use ($webinar, $participant, $feedback, $request, $data, $audit): void {
            $locked = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            $this->available($locked);
            $forms = $locked->forms()->orderBy('id')->lockForUpdate()->get();
            abort_if($forms->contains(fn ($f) => $f->setRelation('webinar', $locked)->acceptsResponses()), 409, 'Close collection before finalizing ineligibility.');
            $p = $locked->participants()->whereKey($participant->id)->whereNull('privacy_erased_at')->lockForUpdate()->firstOrFail();
            $summary = $feedback->summary($p);
            abort_if($summary['evaluation']['eligible'] || $summary['pending_review'] || $feedback->hasUnfinishedImports($locked), 409, 'Resolve reviews, pending imports and all held rows before finalizing. Eligible participants cannot be marked ineligible here.');
            if ($summary['status'] !== 'not_eligible') {
                $decision = EligibilityDecision::query()->create(['webinar_id' => $locked->id, 'participant_id' => $p->id,
                    'evidence_fingerprint' => $summary['fingerprint'], 'reason' => trim($data['reason']), 'finalized_by' => $request->user()->id]);
                $audit->record($request, 'eligibility.finalized', $decision, ['participant_id' => $p->id, 'decision' => 'not_eligible']);
            }
        }, attempts: 3);

        return back()->with('success', 'The current eligibility review is finalized. No notification was sent and no submission evidence was changed.');
    }

    public function decide(Request $request, Webinar $webinar, Participant $participant, EligibilityReview $review, ParticipantEligibilityService $feedback, CsvImportService $imports, AuditService $audit)
    {
        $data = $request->validate(['decision' => ['required', 'in:approved,rejected'], 'reason' => ['required', 'string', 'min:10', 'max:1500'],
            'correction_type' => ['required_if:decision,approved', 'nullable', 'in:existing_import,records_updated'], 'import_issue_id' => ['nullable', 'integer', 'min:1']]);
        try {
            DB::transaction(function () use ($request, $webinar, $participant, $review, $data, $feedback, $imports, $audit): void {
                $locked = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
                $this->available($locked);
                Form::query()->where('webinar_id', $locked->id)->orderBy('id')->lockForUpdate()->get(['id']);
                $p = $locked->participants()->whereKey($participant->id)->whereNull('privacy_erased_at')->lockForUpdate()->firstOrFail();
                $r = $p->eligibilityReviews()->whereKey($review->id)->lockForUpdate()->firstOrFail();
                abort_unless($r->status === 'pending', 409, 'This review already has a decision.');
                if ($data['decision'] === 'approved') {
                    if ($data['correction_type'] === 'existing_import') {
                        $issue = ImportIssue::query()->whereKey($data['import_issue_id'] ?? 0)->where('webinar_id', $locked->id)
                            ->where('normalized_email_hash', ImportIssue::emailHash($p->email))->whereNull('resolved_at')->firstOrFail();
                        if ($issue->issue_type === ImportIssue::TYPE_UNMATCHED_EMAIL) {
                            $attached = $imports->promote($issue, $request->user(), $p->full_name);
                            abort_unless($attached->id === $p->id, 409);
                        } elseif ($issue->issue_type === ImportIssue::TYPE_DUPLICATE_EXISTING) {
                            $imports->replaceResponse($issue, $request->user(), $data['reason']);
                        } else {
                            throw ValidationException::withMessages(['import_issue_id' => 'Resolve this issue through the existing import review; it is not a validated correction.']);
                        }
                    } elseif (hash_equals($r->evidence_fingerprint, $feedback->summary($p)['fingerprint'])) {
                        throw ValidationException::withMessages(['correction_type' => 'No verified record change was found. Correct records through Imports or Participants first, or select a validated held import. A review cannot grant eligibility.']);
                    }
                }
                $r->update(['status' => $data['decision'], 'open_key' => null, 'decision_reason' => trim($data['reason']),
                    'decided_by' => $request->user()->id, 'decided_at' => now(), 'correction_type' => $data['correction_type'] ?? null,
                    'import_issue_id' => ($data['correction_type'] ?? '') === 'existing_import' ? $data['import_issue_id'] ?? null : null]);
                $audit->record($request, 'eligibility.review_'.$data['decision'], $r, ['participant_id' => $p->id, 'correction_type' => $data['correction_type'] ?? null]);
            }, attempts: 3);
        } catch (\RuntimeException $exception) {
            if (get_class($exception) !== \RuntimeException::class) {
                throw $exception;
            }

            return back()->withErrors(['review' => $exception->getMessage()]);
        }

        return back()->with('success', 'Review decision saved. Eligibility is recalculated from the current verified records; no certificate or notification was sent.');
    }

    public function notify(Request $request, Webinar $webinar, ParticipantEligibilityService $feedback, EligibilityService $rules, NotificationService $notifications, AuditService $audit)
    {
        $data = $request->validate(['submission_key' => ['required', 'uuid'], 'confirmed' => ['accepted'], 'resend' => ['sometimes', 'accepted'],
            'participants' => ['required', 'array', 'min:1', 'max:50'], 'participants.*' => ['required', 'ulid', 'distinct']]);
        $ids = $data['participants'];
        sort($ids, SORT_STRING);
        $digest = hash_hmac('sha256', json_encode(['non_eligible', $ids, $request->boolean('resend')], JSON_THROW_ON_ERROR), config('app.key'));
        $count = DB::transaction(function () use ($request, $webinar, $data, $ids, $digest, $feedback, $rules, $notifications, $audit): int {
            $locked = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            $this->available($locked);
            $previous = ParticipantMessageBatch::query()->where('submission_key', $data['submission_key'])->first();
            if ($previous) {
                abort_unless($previous->webinar_id === $locked->id && $previous->created_by === $request->user()->id && hash_equals($previous->request_digest, $digest), 409);

                return $previous->recipient_count;
            }
            Form::query()->where('webinar_id', $locked->id)->orderBy('id')->sharedLock()->get(['id']);
            $participants = $locked->participants()->whereIn('public_id', $ids)->whereNull('privacy_erased_at')->orderBy('id')->lockForUpdate()->get();
            abort_unless($participants->count() === count($ids), 422, 'Refresh the participant list before sending.');
            $count = 0;
            $batch = ParticipantMessageBatch::query()->create(['webinar_id' => $locked->id, 'created_by' => $request->user()->id,
                'submission_key' => $data['submission_key'], 'request_digest' => $digest, 'recipient_count' => 0]);
            foreach ($participants as $p) {
                if (! filter_var($p->email, FILTER_VALIDATE_EMAIL)) {
                    throw ValidationException::withMessages(['participants' => 'Every selected participant needs a valid email address.']);
                }
                $summary = $feedback->summary($p);
                if ($rules->canPrepareCertificate($p)) {
                    throw ValidationException::withMessages(['participants' => 'This action is only for participants who are not currently eligible. Eligible and authorized manual certificate recipients must use the separate certificate or eligible-message action. Nothing in this selection was sent.']);
                }
                $previous = $locked->emailDeliveries()->where('participant_id', $p->id)->where('type', 'eligibility_notification')->latest('id')->first();
                if ($previous && (in_array($previous->status, ['pending', 'processing'], true) || ! $request->boolean('resend'))) {
                    continue;
                }
                if ($previous && str_starts_with((string) $previous->last_error, 'Provider outcome unknown')) {
                    $request->validate(['provider_reconciled' => ['accepted']]);
                }
                if ($previous?->created_at->gt(now()->subMinutes(2))) {
                    continue;
                }
                $expires = now()->addDay();
                $delivery = $notifications->queue($locked, $p, 'eligibility_notification', $p->email, 'Your participation record — '.$locked->title,
                    view('emails.eligibility-notification', ['webinar' => $locked, 'participant' => $p, 'summary' => $summary])->render(),
                    expiresAt: $expires, context: ['status_access_version' => (int) $p->status_access_version, 'eligibility_fingerprint' => $summary['fingerprint'], 'eligibility_status' => $summary['status']]);
                $delivery->update(['participant_message_batch_id' => $batch->id]);
                $count++;
            }
            $batch->update(['recipient_count' => $count]);
            $audit->record($request, 'eligibility.notifications_queued', $locked, ['recipient_count' => $count, 'resend' => $request->boolean('resend')]);

            return $count;
        }, attempts: 3);

        return back()->with('success', app(LocalDelivery::class)->enabled()
            ? "{$count} non-eligible message(s) processed locally. Check Email logs for the result. Log mode does not send email; failed attempts require an explicit retry."
            : "{$count} eligibility notification(s) queued. Only non-eligible recipients are included. Check Email logs for provider acceptance and delivery results.");
    }

    public function revoke(Request $request, Webinar $webinar, Participant $participant, AuditService $audit)
    {
        DB::transaction(function () use ($request, $webinar, $participant, $audit): void {
            $locked = Webinar::query()->whereKey($webinar->id)->lockForUpdate()->firstOrFail();
            $p = $locked->participants()->whereKey($participant->id)->lockForUpdate()->firstOrFail();
            $p->increment('status_access_version');
            $p->accessTokens()->where('purpose', ParticipantStatusLinkService::PURPOSE)->whereNull('used_at')->update(['used_at' => now()]);
            $audit->record($request, 'eligibility.access_revoked', $p);
        });

        return back()->with('success', 'Personal status links and existing status sessions revoked. A new email link is required.');
    }

    public function evidence(Webinar $webinar, Participant $participant, EligibilityReview $review, EligibilityReviewFiles $files)
    {
        abort_unless($participant->webinar_id === $webinar->id && $review->participant_id === $participant->id && $review->webinar_id === $webinar->id && ! $participant->privacy_erased_at, 404);

        return $files->download($review);
    }

    private function available(Webinar $webinar): void
    {
        abort_if($webinar->isUploadOnly() || ! in_array($webinar->status, ['published', 'completed'], true) || $webinar->archived_at || $webinar->deletion_started_at || ! $webinar->retention_due_at?->isFuture(), 409, 'The webinar is unavailable or past its retention period.');
    }

    private function relations(): array
    {
        return ['webinar.eligibilityRules', 'webinar.imports:id,webinar_id,status,committed_at',
            'submissions' => fn ($q) => $q->select(['id', 'form_id', 'participant_id', 'status', 'score', 'submitted_at', 'metadata']),
            'submissions.form:id,type,webinar_id', 'eligibilityOverrides', 'certificates', 'eligibilityReviews', 'eligibilityDecisions'];
    }
}
