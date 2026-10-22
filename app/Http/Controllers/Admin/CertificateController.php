<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CertificateDeliveryState;
use App\Http\Controllers\Controller;
use App\Jobs\IssueCertificateBatch;
use App\Jobs\IssueSelectedCertificate;
use App\Models\Certificate;
use App\Models\CertificateBatch;
use App\Models\CertificateTemplate;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Webinar;
use App\Policies\WebinarPolicy;
use App\Services\AuditService;
use App\Services\CertificateFileService;
use App\Services\CertificateService;
use App\Services\EligibilityService;
use App\Services\NotificationService;
use App\Services\ParticipantEligibilityService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CertificateController extends Controller
{
    /**
     * The certificate studio: pick eligible participants on the left, review a
     * live in-browser preview of each generated certificate in the centre, and
     * issue to the selection from the right.
     */
    public function studio(Request $request, Webinar $webinar, EligibilityService $eligibility, ParticipantEligibilityService $feedback): View
    {
        $template = $webinar->certificateTemplates()->where('is_active', true)->first();

        $eligibleIds = $eligibility->certificateParticipantsQuery($webinar);
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:200'], 'source' => ['nullable', 'in:eligible,manual'], 'highlight' => ['nullable', 'ulid']]);
        $search = trim($filters['search'] ?? '');
        $notCurrentlyEligibleQuery = $webinar->participants()->whereNull('privacy_erased_at')
            ->whereNotIn('participants.id', $eligibility->eligibleParticipantsQuery($webinar))
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->whereLike('full_name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%')));
        $canReviewEligibility = app(WebinarPolicy::class)->permission($request->user(), $webinar, 'manage_participants');
        $notCurrentlyEligibleCount = $canReviewEligibility ? (clone $notCurrentlyEligibleQuery)->count() : 0;
        $notCurrentlyEligible = $canReviewEligibility ? $notCurrentlyEligibleQuery->orderBy('id')->limit(50)->get() : collect();
        $feedback->prepare($notCurrentlyEligible, $webinar);
        foreach ($notCurrentlyEligible as $person) {
            $person->feedback = $feedback->summary($person);
        }

        $requestedIds = collect($request->query('participants', []))
            ->filter(fn ($id) => is_string($id) && preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $id))
            // Public identifiers are ULIDs: upper-case Crockford base32. A
            // hand-typed or lower-cased identifier is the same identifier.
            ->map(fn ($id) => strtoupper($id))
            ->unique()->take(200)->values();

        // The participant page's "Send certificate" button links here with a
        // single public id. Honour it, so the manual path opens that person
        // alone and preselects them instead of the whole ready list.
        $manual = trim((string) $request->query('manual', ''));
        if ($manual !== '') {
            // A manual request always narrows the studio to that one person,
            // even when the identifier is malformed. Falling back to the whole
            // ready list would put every eligible participant one click away
            // from being sent, which is the opposite of what was asked for.
            $requestedIds = $requestedIds->push(strtoupper($manual))->unique()->values();
        }

        // Delivered certificates are kept out of the working list entirely:
        // they are not selectable, so they only crowd out everyone still
        // awaiting a send. They remain visible in the collapsed section below.
        $participants = $webinar->participants()
            ->whereIn('participants.id', $eligibleIds)
            ->when($search !== '', fn ($query) => $query->where(fn ($q) => $q->whereLike('full_name', '%'.$search.'%')->orWhereLike('email', '%'.$search.'%')))
            ->when(($filters['source'] ?? '') === 'manual', fn ($query) => $query->where(fn ($q) => $q
                ->whereHas('certificateApproval', fn ($a) => $a->where('source', 'manual')->whereNull('removed_at'))
                ->orWhereHas('eligibilityOverrides', fn ($o) => $o->where('decision', 'eligible')->where(fn ($active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', now())))))
            ->when(($filters['source'] ?? '') === 'eligible', fn ($query) => $query
                ->whereIn('participants.id', $eligibility->eligibleParticipantsQuery($webinar))
                ->whereDoesntHave('certificateApproval', fn ($a) => $a->where('source', 'manual')->whereNull('removed_at'))
                ->whereDoesntHave('eligibilityOverrides', fn ($o) => $o->where(fn ($active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()))))
            ->when($requestedIds->isNotEmpty(), fn ($query) => $query->whereIn('public_id', $requestedIds))
            ->whereNull('privacy_erased_at')
            ->whereDoesntHave('certificates', fn ($query) => $query
                ->whereNull('revoked_at')
                ->whereNotNull('sent_at'))
            ->with(['certificateApproval', 'certificates' => fn ($query) => $query
                ->select(['id', 'participant_id', 'verification_code', 'issued_at', 'sent_at', 'revoked_at', 'status', 'layout'])])
            ->withExists('submissions')
            ->when(filled($filters['highlight'] ?? null), fn ($query) => $query->orderByRaw('CASE WHEN public_id = ? THEN 0 ELSE 1 END', [$filters['highlight']]))
            ->orderByRaw('full_name is null')
            ->orderBy('full_name')
            // Bounded pages keep the document usable for very large events;
            // whole-roster sending goes through "Send to all ready" instead.
            ->paginate(200)
            ->withPath(route('admin.certificates.studio', $webinar))
            ->withQueryString();

        $eligibility->attachTo($participants->getCollection(), $webinar);

        // A person the organizer asked for, but who is not eligible, must not
        // disappear behind a generic empty list. Say what is missing and where
        // to record the decision, so a manual send is never a dead end.
        $listedIds = $participants->getCollection()->pluck('public_id');
        $ineligibleScope = collect();
        if ($requestedIds->isNotEmpty()) {
            $ineligibleScope = $webinar->participants()
                ->whereIn('public_id', $requestedIds)
                ->whereNull('privacy_erased_at')
                // Eligibility reads the registration and attendance markers, so
                // those columns have to travel with the record.
                ->get(['id', 'public_id', 'full_name', 'email', 'verified_at', 'checked_in_at'])
                ->reject(fn ($participant) => $listedIds->contains($participant->public_id))
                ->map(function ($participant) use ($eligibility, $webinar) {
                    $evaluation = $eligibility->evaluate($participant, $webinar->eligibilityRules);
                    $missing = collect($evaluation['requirements'])
                        ->filter(fn ($met) => ! $met)
                        ->keys()
                        ->map(fn ($requirement) => ucfirst(str_replace('_', ' ', $requirement)))
                        ->values()
                        ->all();

                    return ['participant' => $participant, 'missing' => $missing];
                });
        }

        // Map each eligible participant to one of the four plain statuses the
        // admin sees: ready (no certificate yet), sending (issued, email in
        // flight), sent (email delivered), or failed (delivery failed).
        $held = fn ($participant) => $participant->certificates
            ->sortByDesc('id')
            ->first(fn ($c) => ! $c->revoked_at && in_array($c->status, ['processing', 'issued', 'failed'], true));
        $deliveryByCertificate = $this->deliveryByCertificate(
            $participants->map($held)->filter()->pluck('id'),
        );
        foreach ($participants as $participant) {
            $participant->recipient_source = $eligibility->hasManualRecipient($participant) || $participant->eligibility['overridden'] ? 'manual' : 'eligible';
            $participant->manual_editable = $eligibility->hasManualRecipient($participant)
                && $participant->certificateApproval->manages_identity
                && ! $participant->verified_at && ! $participant->email_verified_at && ! $participant->checked_in_at
                && ! $participant->submissions_exists
                && $participant->certificates->isEmpty();
            $certificate = $held($participant);
            $delivery = $certificate ? $deliveryByCertificate->get($certificate->id) : null;
            $state = CertificateDeliveryState::from(
                $certificate,
                $delivery,
                filled($participant->email),
            );
            $participant->certificate_delivery_state = $state;
            $participant->cert_status = $state->slug();
            $participant->certificate_record = $certificate;
            $participant->delivery_meta = $this->deliveryMeta($delivery, $state);
            $participant->cert_status_detail = $state->detail();
        }

        // The separated record of everything already delivered, newest first,
        // for the rare moment an organizer needs to look one up.
        $sentCount = $webinar->certificates()
            ->whereNotNull('sent_at')
            ->whereNull('revoked_at')
            ->count();
        $sentRecent = $webinar->certificates()
            ->whereNotNull('sent_at')
            ->whereNull('revoked_at')
            ->with('participant:id,full_name,email')
            ->latest('sent_at')
            ->limit(100)
            ->get(['id', 'participant_id', 'recipient_name', 'verification_code', 'sent_at', 'eligibility_snapshot']);

        // Everything the bulk "Send to all ready" action would queue: eligible,
        // reachable by email, and without an active issued certificate.
        $bulkReadyCount = $webinar->participants()
            ->whereIn('participants.id', $eligibleIds)
            ->whereNull('privacy_erased_at')
            ->whereNotNull('participants.email')
            ->where('participants.email', '!=', '')
            ->whereDoesntHave('certificates', fn ($query) => $query
                ->whereNull('revoked_at')
                ->whereNotNull('issued_at'))
            ->count();

        if ($request->routeIs('admin.certificates.studio.recipients')) {
            return view('admin.webinars.partials.studio-roster', [
                'webinar' => $webinar, 'participants' => $participants, 'selectionScoped' => false,
                'sentRecent' => $sentRecent, 'sentCount' => $sentCount, 'bulkReadyCount' => $bulkReadyCount,
                'notCurrentlyEligible' => $notCurrentlyEligible, 'notCurrentlyEligibleCount' => $notCurrentlyEligibleCount, 'canReviewEligibility' => $canReviewEligibility,
            ]);
        }

        // Responses that need a human decision before bulk sending is safe:
        // a held row can be a missing participant, a conflicting duplicate, or
        // a wrong-file/wrong-column signal. Bulk issuance is blocked until the
        // review queue is empty (resolve, promote, replace, or dismiss).
        $heldCount = $webinar->importIssues()->whereNull('resolved_at')->count();

        // A batch is asynchronous, so a flash message cannot answer which
        // recipients were issued or why a particular one failed. Keep the
        // latest run and its bounded per-person outcome visible in the studio.
        $latestBatch = $webinar->certificateBatches()
            ->latest('id')
            ->first(['id', 'public_id', 'status', 'total_count', 'completed_count', 'failed_count', 'completed_at', 'created_at']);
        $latestBatchItems = $latestBatch
            ? $latestBatch->items()
                ->with([
                    'participant:id,full_name',
                    'certificate:id,verification_code',
                ])
                ->latest('id')
                ->limit(50)
                ->get(['id', 'certificate_batch_id', 'participant_id', 'certificate_id', 'status', 'message', 'created_at'])
            : collect();
        $latestBatchItemsTruncated = $latestBatch !== null
            && $latestBatch->items()->count() > $latestBatchItems->count();

        // If certificate emails have been waiting to go out for a while, the
        // background email sender is probably not running — warn the admin so
        // certificates don't sit silently in "Sending". A single stalled item
        // is not enough during a large run: the pipeline counts as stalled
        // only when nothing at all has moved for a couple of minutes.
        $stalledAfter = now()->subMinutes(2);
        $generationStalled = $webinar->certificates()
            ->where('status', 'processing')
            ->where('updated_at', '<', $stalledAfter)
            ->exists()
            && ! $webinar->certificates()
                ->where('status', 'issued')
                ->where('updated_at', '>=', $stalledAfter)
                ->exists();
        $emailStalled = $webinar->certificates()
            ->whereHas('deliveries', fn ($query) => $query
                ->whereNull('sent_at')
                ->whereNull('failed_at')
                ->where('status', '!=', 'cancelled')
                ->where('scheduled_at', '<', $stalledAfter))
            ->exists()
            && ! $webinar->emailDeliveries()
                ->whereNotNull('sent_at')
                ->where('sent_at', '>=', $stalledAfter)
                ->exists();
        $emailProviderReady = (config('webinar.email.certificate_provider') ?? config('webinar.email.provider')) !== 'log'
            && ((config('webinar.email.certificate_provider') ?? config('webinar.email.provider')) !== 'brevo' || filled(config('services.brevo.key')));
        $pipelineWarning = match (true) {
            ! $emailProviderReady => 'Email delivery is in test mode. Certificates can be generated, but no message will leave the application until a transactional email provider is configured.',
            $generationStalled && $emailStalled => 'Nothing has moved for two minutes: no certificate was generated and no email was sent. Start the certificate worker ('.$this->workerCommand().') and the email worker ('.$this->emailWorkerCommand().').',
            $generationStalled => 'Certificates are queued but nothing has been generated for two minutes. Start the certificate worker ('.$this->workerCommand().').',
            $emailStalled => 'Certificates are ready but no email has been sent for two minutes. Start the email worker ('.$this->emailWorkerCommand().').',
            default => null,
        };

        return view('admin.webinars.certificate-studio', [
            'recipientFilters' => $filters,
            'notCurrentlyEligible' => $notCurrentlyEligible, 'notCurrentlyEligibleCount' => $notCurrentlyEligibleCount, 'canReviewEligibility' => $canReviewEligibility,
            'webinar' => $webinar,
            'template' => $template,
            'participants' => $participants,
            'hasBackground' => (bool) $template?->background_path,
            'layout' => $template?->layout ?? [],
            'fonts' => CertificateTemplate::FONTS,
            'sentRecent' => $sentRecent,
            'sentCount' => $sentCount,
            'pipelineWarning' => $pipelineWarning,
            'bulkReadyCount' => $bulkReadyCount,
            'heldCount' => $heldCount,
            'latestBatch' => $latestBatch,
            'latestBatchItems' => $latestBatchItems,
            'latestBatchItemsTruncated' => $latestBatchItemsTruncated,
            'selectionScoped' => $requestedIds->isNotEmpty(),
            'manualRequested' => $manual !== '',
            'ineligibleScope' => $ineligibleScope,
            'backUrl' => route('admin.participants.index', $webinar),
            'statusUrl' => route('admin.certificates.studio.status', $webinar),
        ]);
    }

    /**
     * Lightweight polling payload for the studio's live delivery tracker.
     *
     * Only participants that actually hold certificate work are returned, and
     * an ETag lets an unchanged studio receive an empty 304 instead of the
     * payload — the poll runs every few seconds for minutes at a time.
     */
    public function status(Request $request, Webinar $webinar): JsonResponse
    {
        $fingerprint = collect([
            $webinar->certificates()->max('updated_at'),
            $webinar->emailDeliveries()->max('updated_at'),
        ])->implode('|');
        $etag = '"'.md5("studio-status|{$webinar->id}|{$fingerprint}").'"';

        if (hash_equals($etag, (string) $request->headers->get('If-None-Match', ''))) {
            return response('', 304)->setEtag($etag);
        }

        $participants = $webinar->participants()
            ->whereNull('privacy_erased_at')
            ->whereHas('certificates', fn ($query) => $query
                ->whereNull('revoked_at')
                ->whereIn('status', ['processing', 'issued', 'failed']))
            ->with(['certificates' => fn ($query) => $query
                ->whereNull('revoked_at')->whereIn('status', ['processing', 'issued', 'failed'])
                ->select(['id', 'participant_id', 'verification_code', 'status', 'issued_at', 'sent_at'])])
            ->get(['id', 'public_id']);
        $certificates = $participants->pluck('certificates')->flatten(1);
        $deliveryByCertificate = $this->deliveryByCertificate($certificates->pluck('id'));

        return response()->json([
            'certificates' => $participants->map(function ($participant) use ($deliveryByCertificate) {
                $certificate = $participant->certificates->sortByDesc('id')->first();
                $delivery = $certificate ? $deliveryByCertificate->get($certificate->id) : null;
                $state = CertificateDeliveryState::from($certificate, $delivery);
                $meta = $this->deliveryMeta($delivery, $state);

                return [
                    'participant_id' => $participant->public_id,
                    'certificate_number' => $certificate?->verification_code,
                    'status' => $state->slug(),
                    'status_label' => $state->label(),
                    'status_detail' => $state->detail(),
                    'failed_reason' => $meta['failed_reason'] ?? null,
                    'retry_count' => $meta['retry_count'] ?? 0,
                    'failed_at' => $meta['failed_at'] ?? null,
                ];
            })->values(),
        ])->setEtag($etag);
    }

    /**
     * Send a certificate's email again — used to recover a delivery that failed.
     * The certificate already exists; this re-queues its stored PDF for delivery.
     */
    public function resend(
        Request $request,
        Certificate $certificate,
        NotificationService $notifications,
        AuditService $audit,
    ): RedirectResponse {
        // An uncertain provider outcome (connection lost after a possible
        // accept) must be reconciled in the Brevo log before a new
        // idempotency key is minted; otherwise a "failed" retry duplicates mail.
        // last_error is encrypted at rest, so the marker is checked after
        // decryption rather than with a SQL LIKE.
        $unknown = EmailDelivery::query()
            ->where('certificate_id', $certificate->id)
            ->where('status', 'failed')
            ->get(['last_error'])
            ->contains(fn ($delivery) => str_starts_with((string) $delivery->last_error, 'Provider outcome unknown'));
        $reconciliationReference = null;
        if ($unknown) {
            if (! $request->boolean('confirm_unknown')) {
                return back()->with('error', 'The last delivery has an uncertain provider outcome (the request may have been accepted while the response was lost). Check the Brevo message log for this recipient, then retry with explicit confirmation that no delivery occurred.');
            }
            $data = $request->validate([
                'confirm_unknown' => ['accepted'],
                'provider_reconciliation_reference' => ['required', 'string', 'min:5', 'max:200'],
            ], [
                'confirm_unknown.accepted' => 'Confirm that you checked the provider log before requesting a new delivery.',
                'provider_reconciliation_reference.required' => 'Record the provider-log search or case reference before retrying an uncertain delivery.',
            ]);
            $reconciliationReference = $data['provider_reconciliation_reference'];
        }
        try {
            DB::transaction(function () use ($certificate, $notifications): void {
                // Follow the application-wide lock order: webinar, participant,
                // then the certificate/delivery child rows. The old check was
                // outside a transaction and expired after ten minutes, allowing
                // concurrent clicks to queue duplicate sends.
                $lockedCertificate = Certificate::query()->whereKey($certificate->id)->firstOrFail();
                $webinar = Webinar::query()
                    ->whereKey($lockedCertificate->webinar_id)
                    ->whereNull('deletion_started_at')
                    ->sharedLock()
                    ->firstOrFail();
                $participant = Participant::query()
                    ->whereKey($lockedCertificate->participant_id)
                    ->whereNull('privacy_erased_at')
                    ->lockForUpdate()
                    ->firstOrFail();
                $lockedCertificate = Certificate::query()->whereKey($lockedCertificate->id)->lockForUpdate()->firstOrFail();

                if ($lockedCertificate->issued_at === null || $lockedCertificate->revoked_at !== null) {
                    throw new RuntimeException('This certificate cannot be resent.');
                }
                if (blank($participant->email)) {
                    throw new RuntimeException('There is no email address on file for this participant.');
                }
                if ($lockedCertificate->recipient_name !== $participant->full_name) {
                    throw new RuntimeException('This PDF has an earlier participant name. Revoke it and issue a corrected certificate before sending.');
                }
                if (EmailDelivery::query()
                    ->where('certificate_id', $lockedCertificate->id)
                    ->whereIn('status', ['pending', 'processing'])
                    ->lockForUpdate()
                    ->exists()) {
                    throw new RuntimeException('A certificate email is already queued or sending for this participant. Check its delivery status before retrying.');
                }

                // Repair a claim left by a legacy/raw status update. Normal
                // Eloquent transitions clear it automatically, but a terminal
                // delivery must never block an otherwise valid retry forever.
                EmailDelivery::query()
                    ->where('certificate_id', $lockedCertificate->id)
                    ->whereNotIn('status', ['pending', 'processing'])
                    ->whereNotNull('active_certificate_delivery_key')
                    ->update(['active_certificate_delivery_key' => null]);

                $disk = Storage::disk((string) $lockedCertificate->storage_disk);
                if (! $lockedCertificate->file_path || ! $disk->exists($lockedCertificate->file_path)) {
                    throw new RuntimeException('The stored certificate file is missing.');
                }
                $statusForm = $webinar->forms()->where('type', 'registration')->first(['public_token']);
                $notifications->queue(
                    $webinar,
                    $participant,
                    'certificate',
                    (string) $participant->email,
                    $webinar->certificateEmailSubject($lockedCertificate->recipient_name),
                    view('emails.certificate-issued', [
                        'participant' => $participant,
                        'certificate' => $lockedCertificate,
                        'customMessage' => $webinar->certificateEmailMessage($lockedCertificate->recipient_name),
                        'statusUrl' => $statusForm ? route('forms.public.status', $statusForm->public_token) : null,
                    ])->render(),
                    $lockedCertificate,
                    [['name' => 'certificate.pdf', 'content' => base64_encode($disk->get($lockedCertificate->file_path))]],
                    $webinar->retention_due_at,
                );
            }, attempts: 3);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (UniqueConstraintViolationException) {
            // The database guard is the final authority under concurrent
            // requests. Treat a lost race as an operator-visible no-op, never
            // as a second send or an opaque 500 response.
            return back()->with('warning', 'A certificate email was already queued by another request. Check its delivery status before retrying.');
        }

        $audit->record($request, 'certificate.resent', $certificate, array_filter([
            // Never place an operator's provider-log notes into audit metadata;
            // retain a keyed correlation value instead.
            'provider_reconciliation_reference_fingerprint' => $reconciliationReference
                ? $audit->fingerprint($reconciliationReference, 'provider-reconciliation-reference')
                : null,
            'provider_outcome_was_uncertain' => $unknown ?: null,
        ], fn ($value) => $value !== null));

        return back()->with('success', 'The certificate email was queued again. Watch its delivery status here.');
    }

    /**
     * The command that generates the queued certificates.
     *
     * The administrator has to be told exactly what to run: a certificate that
     * sits in "Queued" with no worker running is indistinguishable from a
     * broken send.
     */
    private function workerCommand(): string
    {
        return 'php artisan queue:work '.config('queue.default').' --queue=default';
    }

    /** The command that hands the generated certificates to the email provider. */
    private function emailWorkerCommand(): string
    {
        return 'php artisan queue:work '.(string) config('webinar.email.queue_connection')
            .' --queue='.(string) config('webinar.email.queue');
    }

    /** @return Collection<int, EmailDelivery> */
    private function deliveryByCertificate($certificateIds): Collection
    {
        if ($certificateIds->isEmpty()) {
            return collect();
        }

        return EmailDelivery::query()
            ->whereIn('certificate_id', $certificateIds)
            ->orderBy('id')
            ->get(['certificate_id', 'status', 'last_error', 'attempts', 'failed_at'])
            ->keyBy('certificate_id');
    }

    /** @return array{status:string,failed_reason:?string,retry_count:int,failed_at:?string,requires_reconciliation:bool} */
    private function deliveryMeta(?EmailDelivery $delivery, CertificateDeliveryState $state): array
    {
        return [
            'status' => $state->slug(),
            'failed_reason' => $delivery?->last_error,
            'retry_count' => (int) ($delivery?->attempts ?? 0),
            'failed_at' => $delivery?->failed_at?->toIso8601String(),
            'requires_reconciliation' => str_starts_with((string) $delivery?->last_error, 'Provider outcome unknown'),
        ];
    }

    /**
     * Issue certificates to an explicit selection of participants made in the
     * studio. Already-issued and no-longer-eligible participants are skipped.
     */
    public function issueSelected(Request $request, Webinar $webinar, CertificateService $service, AuditService $audit): RedirectResponse
    {
        $data = $request->validate([
            'participants' => ['required', 'array', 'min:1', 'max:200'],
            'participants.*' => ['string', 'max:64'],
            'preview_confirmed' => ['required', 'accepted'],
            'designs_json' => ['nullable', 'string', 'max:200000'],
        ], [
            'participants.required' => 'Select at least one participant to send a certificate to.',
            'participants.max' => 'Send to up to 200 at once here. For a larger group, use “Send to all ready”.',
        ]);

        $participants = $webinar->participants()
            ->whereIn('public_id', $data['participants'])
            ->whereNull('privacy_erased_at')
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->get();

        $submittedDesigns = json_decode($data['designs_json'] ?? '{}', true);
        $submittedDesigns = is_array($submittedDesigns) ? $submittedDesigns : [];
        $issued = 0;
        $skipped = count($data['participants']) - $participants->count();
        $skipReasons = [];
        if ($skipped > 0) {
            $skipReasons[] = "{$skipped} not found, erased, or missing email";
        }

        foreach ($participants as $participant) {
            $certificate = null;
            try {
                $design = $this->normalizeSubmittedDesign($submittedDesigns[$participant->public_id] ?? null);
                $certificate = $service->queue($participant, $design);
                if ($certificate->status === 'issued') {
                    $skipped++;
                    $skipReasons[] = "{$participant->email}: already issued";

                    continue;
                }
                IssueSelectedCertificate::dispatch($participant->id);
                $issued++;
            } catch (RuntimeException $exception) {
                // Preparation commits before the broker handoff. If the broker
                // refuses it, expose a retryable failure instead of leaving an
                // unqueued record in the Studio's processing state forever.
                if ($certificate?->status === 'processing') {
                    Certificate::query()->whereKey($certificate->id)->where('status', 'processing')->update([
                        'status' => 'failed',
                        'issuance_key' => null,
                        'updated_at' => now(),
                    ]);
                }
                $skipped++;
                $skipReasons[] = "{$participant->email}: {$exception->getMessage()}";
            }
        }

        $audit->record($request, 'certificate.issued_selection', $webinar, [
            'requested' => count($data['participants']),
            'issued' => $issued,
            'skipped' => $skipped,
        ]);

        $message = $issued === 1
            ? '1 certificate was queued for generation. It becomes a PDF when the certificate worker runs and an email when the email worker runs — start them if they are not already running.'
            : "{$issued} certificates were queued for generation. They become PDFs when the certificate worker runs and emails when the email worker runs — start them if they are not already running.";
        if ($skipped > 0) {
            $message .= ' '.$skipped.' skipped: '.implode('; ', array_slice($skipReasons, 0, 5));
            if (count($skipReasons) > 5) {
                $message .= '; and '.(count($skipReasons) - 5).' more (see Email Logs).';
            }
        }

        return redirect()->route('admin.certificates.studio', $webinar)
            ->with($issued > 0 ? 'success' : 'error', $issued > 0 ? $message : 'Nothing was sent — '.implode('; ', array_slice($skipReasons, 0, 5)));
    }

    /** Keep client-authored visual settings inside the same bounds as the template editor. */
    private function normalizeSubmittedDesign(mixed $design): ?array
    {
        if (! is_array($design)) {
            return null;
        }
        $family = (string) ($design['name_font_family'] ?? '');
        $color = (string) ($design['accent'] ?? '');
        if (! isset(CertificateTemplate::FONTS[$family]) || ! preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            return null;
        }

        return [
            'name_top' => round(max(0, min(100, (float) ($design['name_top'] ?? 62))), 2),
            'name_left' => round(max(0, min(100, (float) ($design['name_left'] ?? 50))), 2),
            'name_font_size' => round(max(12, min(160, (float) ($design['name_font_size'] ?? 42))), 1),
            'name_font_family' => $family,
            'accent' => strtolower($color),
            'name_font_weight' => ($design['name_font_weight'] ?? 'bold') === 'regular' ? 'regular' : 'bold',
            'name_font_style' => ($design['name_font_style'] ?? 'regular') === 'italic' ? 'italic' : 'regular',
            'name_text_align' => in_array($design['name_text_align'] ?? 'center', ['left', 'center', 'right'], true) ? ($design['name_text_align'] ?? 'center') : 'center',
        ];
    }

    public function store(Request $request, Webinar $webinar, Participant $participant, CertificateService $service, AuditService $audit): RedirectResponse
    {
        abort_unless($participant->webinar_id === $webinar->id, 404);

        try {
            $certificate = $service->issue($participant);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->record($request, 'certificate.issued', $certificate);

        return back()->with('success', 'Certificate issued. It is being sent to the participant.');
    }

    /** Queue an issuance run for every eligible participant without a valid certificate. */
    public function batch(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        $template = $webinar->certificateTemplates()->where('is_active', true)->first();

        if (! $template || blank($template->background_path)) {
            return back()->with('error', 'Upload a certificate design before issuing in bulk.');
        }

        // Held import rows are unresolved identity/completion questions. Bulk
        // sending while they exist risks certifying the wrong set, so the
        // operator must clear the review queue first (promote, replace, or
        // dismiss each row). Dismissing is the explicit "leave it out" escape.
        $heldCount = $webinar->importIssues()->whereNull('resolved_at')->count();
        if ($heldCount > 0) {
            return back()->with('warning', "Review {$heldCount} held import row(s) before bulk sending — open Imports, resolve each row, then send. This prevents certifying an incomplete set.");
        }

        // A second bulk run while one is still queued would iterate the same
        // set concurrently. Point the operator at the active run instead.
        $activeBatch = $webinar->certificateBatches()
            ->whereIn('status', ['pending', 'processing'])
            ->latest('id')
            ->first();
        if ($activeBatch) {
            return back()->with('warning', 'A bulk issuance run is already queued or processing. Wait for it to finish before starting another.');
        }

        $batch = CertificateBatch::query()->create([
            'webinar_id' => $webinar->id,
            'certificate_template_id' => $template->id,
            'created_by' => $request->user()->id,
            'status' => 'pending',
        ]);

        IssueCertificateBatch::dispatch($batch->id);
        $audit->record($request, 'certificate_batch.queued', $batch);

        return back()->with('success', 'Bulk issuance queued. Progress appears below as the queue worker processes it.');
    }

    public function download(
        Request $request,
        Certificate $certificate,
        AuditService $audit,
        CertificateFileService $certificateFiles,
    ): StreamedResponse {
        abort_if($certificate->webinar?->deletion_started_at !== null, 409);

        $response = $certificateFiles->download($certificate);
        $audit->record($request, 'certificate.downloaded', $certificate);

        return $response;
    }

    public function revoke(Request $request, Certificate $certificate, AuditService $audit): RedirectResponse
    {
        abort_if($certificate->webinar?->deletion_started_at !== null, 409);

        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:2000']]);
        $certificate->update([
            'status' => 'revoked',
            'revoked_at' => now(),
            'revocation_reason' => $data['reason'],
            'issuance_key' => null,
        ]);
        $audit->record($request, 'certificate.revoked', $certificate, [
            'reason_fingerprint' => $audit->fingerprint($data['reason'], 'certificate-revocation-reason'),
        ]);

        return back()->with('success', 'Certificate revoked.');
    }

    /**
     * Download all issued, non-revoked certificate PDFs in a single ZIP archive.
     */
    public function exportZip(
        Request $request,
        Webinar $webinar,
        CertificateFileService $certificateFiles,
        AuditService $audit,
    ): BinaryFileResponse|RedirectResponse {
        $archive = $certificateFiles->createZipArchive($webinar);

        if (! $archive) {
            return back()->with('error', 'There are no issued certificate files available to export for this webinar.');
        }

        $audit->record($request, 'webinar.certificates_exported_zip', $webinar, [
            'certificate_count' => $archive['count'],
        ]);

        $filename = Str::slug($webinar->title ?: 'webinar').'-certificates.zip';

        return response()->download($archive['path'], $filename, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }
}
