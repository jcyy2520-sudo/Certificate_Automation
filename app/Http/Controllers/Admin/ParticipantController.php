<?php

namespace App\Http\Controllers\Admin;

use App\Enums\CertificateDeliveryState;
use App\Http\Controllers\Controller;
use App\Models\EligibilityOverride;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\EligibilityService;
use App\Services\ParticipantPrivacyService;
use App\Support\CsvSanitizer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ParticipantController extends Controller
{
    public function index(Request $request, Webinar $webinar, EligibilityService $eligibility): View
    {
        $filters = $request->session()->get($this->filterSessionKey($webinar), []);
        $filters = is_array($filters) ? $filters : [];
        $webinar->load([
            'forms' => fn ($query) => $query->select(['id', 'webinar_id', 'type', 'title']),
            'eligibilityRules' => fn ($query) => $query
                ->select(['id', 'webinar_id', 'requirement', 'is_required', 'minimum_score']),
        ]);

        // The same database-native query drives both the global count and filters;
        // only the requested 25-row page is hydrated into application memory.
        $eligibleParticipants = $eligibility->eligibleParticipantsQuery($webinar);

        $query = $this->query($filters, $webinar);

        match ($filters['filter'] ?? '') {
            'complete' => $query->whereIn('participants.id', (clone $eligibleParticipants)->toBase()),
            'incomplete' => $query->whereNotIn('participants.id', (clone $eligibleParticipants)->toBase()),
            default => null,
        };

        $participants = $query->paginate(25);
        $eligibility->attachTo($participants->getCollection(), $webinar);
        $certificateByParticipant = $participants->getCollection()->mapWithKeys(function ($participant): array {
            $certificate = $participant->certificates
                ->whereNull('revoked_at')
                ->sortByDesc('id')
                ->first();

            return [$participant->id => $certificate];
        });
        $deliveryByCertificate = EmailDelivery::query()
            ->whereIn('certificate_id', $certificateByParticipant->filter()->pluck('id'))
            ->orderBy('id')
            ->get(['certificate_id', 'status'])
            ->keyBy('certificate_id');

        foreach ($participants as $participant) {
            $certificate = $certificateByParticipant[$participant->id] ?? null;
            $delivery = $certificate ? ($deliveryByCertificate[$certificate->id] ?? null) : null;
            $participant->certificate_record = $certificate;
            $participant->certificate_delivery_state = CertificateDeliveryState::from(
                $certificate,
                $delivery,
                filled($participant->email),
                $participant->eligibility['eligible'],
            );
        }

        return view('admin.participants.index', [
            'webinar' => $webinar,
            'participants' => $participants,
            // Every form gets a column, ordered the way a participant meets them.
            'forms' => $webinar->forms->sortBy(function ($form): int {
                $position = array_search($form->type, CertificationController::REQUIREMENTS, true);

                return $position === false ? 99 : $position;
            })->values(),
            'completedCount' => (clone $eligibleParticipants)->count(),
            'participantFilters' => $filters,
        ]);
    }

    /** Compatibility endpoint: all manual additions use the Studio mechanism. */
    public function store(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        $response = app(ManualCertificateRecipientController::class)->store($request, $webinar, app(EligibilityService::class), $audit);

        return redirect()->route('admin.certificates.studio', [$webinar, 'manual' => $response->getData()->participant_id])
            ->with('success', 'Recipient added to Studio.');
    }

    /** Store sensitive search terms in the encrypted session, never in URLs. */
    public function filter(Request $request, Webinar $webinar): RedirectResponse
    {
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:200'],
            'filter' => ['nullable', Rule::in(['complete', 'incomplete'])],
        ]);
        $filters = $request->boolean('clear') ? [] : array_filter([
            'search' => filled($data['search'] ?? null) ? trim($data['search']) : null,
            'filter' => $data['filter'] ?? null,
        ], fn ($value): bool => filled($value));

        if ($filters === []) {
            $request->session()->forget($this->filterSessionKey($webinar));
        } else {
            $request->session()->put($this->filterSessionKey($webinar), $filters);
        }

        return redirect()->route('admin.participants.index', $webinar);
    }

    /**
     * Download the pre-send roster and its final delivery evidence. This is
     * deliberately an authenticated, password-confirmed export: it contains
     * participant addresses and provider correlation IDs for operator review.
     */
    public function export(Request $request, Webinar $webinar, EligibilityService $eligibility, AuditService $audit): StreamedResponse
    {
        $audit->record($request, 'participants.exported', $webinar);

        $webinar->load('eligibilityRules');
        $forms = $webinar->forms()->get()->sortBy(function ($form): int {
            $position = array_search($form->type, CertificationController::REQUIREMENTS, true);

            return $position === false ? 99 : $position;
        })->values();
        $scored = $forms->filter(fn ($form) => $form->type !== 'registration');
        $filename = 'participants-'.$webinar->slug.'-'.now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($webinar, $forms, $scored, $eligibility): void {
            $handle = fopen('php://output', 'wb');

            $this->writeCsvRow($handle, [
                'Name', 'Email', 'Organization', 'Registered on', 'Attendance',
                ...$forms->map(fn ($form) => $form->title)->all(),
                ...$scored->map(fn ($form) => $form->title.' score')->all(),
                'Meets all requirements', 'Eligibility basis',
                'Certificate number', 'Certificate state',
                'Email delivery state', 'Email sent at (UTC)', 'Provider message ID',
            ]);

            $webinar->participants()
                ->with([
                    'submissions' => fn ($query) => $query->select([
                        'id', 'form_id', 'participant_id', 'attempt_number', 'status', 'score', 'maximum_score', 'submitted_at',
                    ]),
                    'submissions.form:id,type',
                    'eligibilityOverrides' => fn ($query) => $query
                        ->select(['id', 'participant_id', 'decision', 'expires_at', 'created_at'])
                        ->where(fn ($active) => $active->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        ->latest()
                        ->orderByDesc('id'),
                    'certificates' => fn ($query) => $query
                        ->select(['id', 'participant_id', 'verification_code', 'status', 'issued_at', 'sent_at', 'revoked_at', 'privacy_erased_at'])
                        ->with(['deliveries' => fn ($deliveries) => $deliveries
                            ->select(['id', 'certificate_id', 'status', 'sent_at', 'provider_message_id'])]),
                ])
                ->chunkById(200, function ($participants) use ($handle, $webinar, $forms, $scored, $eligibility): void {
                    $eligibility->attachTo($participants, $webinar);

                    foreach ($participants as $participant) {
                        $certificate = $participant->certificates
                            ->whereNull('privacy_erased_at')
                            ->sortByDesc('id')
                            ->first();
                        $delivery = $certificate?->deliveries->sortByDesc('id')->first();
                        $registration = $participant->submissions
                            ->whereIn('form_id', $forms->where('type', 'registration')->pluck('id'))
                            ->sortByDesc('submitted_at')
                            ->first();
                        $registeredAt = $registration?->submitted_at ?? $participant->verified_at;

                        $row = [
                            $participant->full_name ?: '(erased)',
                            $participant->email ?: '(erased)',
                            $participant->organization,
                            $registeredAt?->toDateString() ?? '',
                            $participant->checked_in_at?->toDateString() ?? '',
                        ];

                        foreach ($forms as $form) {
                            // Match EligibilityService::meets(): only submitted
                            // responses count; superseded rows must not show Yes.
                            $completed = $form->type === 'registration'
                                ? $participant->verified_at !== null
                                : $participant->submissions->firstWhere(fn ($s) => $s->form_id === $form->id && $s->status === 'submitted') !== null;
                            $row[] = $completed ? 'Yes' : 'No';
                        }

                        foreach ($scored as $form) {
                            $submission = $participant->submissions
                                ->where('form_id', $form->id)
                                ->where('status', 'submitted')
                                ->sortByDesc('attempt_number')
                                ->first();
                            $row[] = $submission?->score !== null
                                ? number_format((float) $submission->score, 1).' / '.number_format((float) $submission->maximum_score, 1)
                                : '';
                        }

                        $row[] = $participant->eligibility['eligible'] ? 'Yes' : 'No';
                        $row[] = $participant->eligibility['overridden']
                            ? 'Administrator override: '.($participant->eligibility['eligible'] ? 'eligible' : 'ineligible')
                            : 'Automatic requirements';
                        $row[] = $certificate?->verification_code ?? '';
                        $row[] = match (true) {
                            $certificate === null => 'Not issued',
                            $certificate->revoked_at !== null => 'Revoked',
                            $certificate->status === 'processing' => 'Generating',
                            $certificate->status === 'issued' && $certificate->sent_at !== null => 'Issued and sent',
                            $certificate->status === 'issued' => 'Issued; awaiting delivery',
                            default => ucfirst((string) $certificate->status),
                        };
                        $row[] = $delivery?->status ?? '';
                        $row[] = $delivery?->sent_at?->utc()->toIso8601String() ?? '';
                        $row[] = $delivery?->provider_message_id ?? '';

                        $this->writeCsvRow($handle, $row);
                    }
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store, max-age=0',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function show(Webinar $webinar, Participant $participant, EligibilityService $eligibilityService): View
    {
        $this->assertBelongsTo($webinar, $participant);
        $participant->load(['submissions.form', 'certificates.deliveries', 'eligibilityOverrides.administrator']);
        $activeOverride = $participant->eligibilityOverrides
            ->filter(fn (EligibilityOverride $override): bool => $override->expires_at === null || $override->expires_at->isFuture())
            ->sortBy([
                ['created_at', 'desc'],
                ['id', 'desc'],
            ])
            ->first();

        // "Issued" says the PDF exists, not that the email left. Showing the
        // delivery outcome next to it stops a failed email from reading as a
        // finished send.
        $deliveryStates = [];
        foreach ($participant->certificates as $certificate) {
            $delivery = $certificate->deliveries->sortByDesc('id')->first();
            $deliveryStates[$certificate->id] = [
                'state' => CertificateDeliveryState::from($certificate, $delivery, filled($participant->email)),
                'delivery' => $delivery,
            ];
        }

        return view('admin.participants.show', [
            'webinar' => $webinar,
            'participant' => $participant,
            'eligibility' => $eligibilityService->evaluate($participant),
            'activeOverride' => $activeOverride,
            'deliveryStates' => $deliveryStates,
        ]);
    }

    public function override(Request $request, Webinar $webinar, Participant $participant, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $participant);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['eligible', 'ineligible'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        $override = EligibilityOverride::query()->create([
            ...$data,
            'participant_id' => $participant->id,
            'overridden_by' => $request->user()->id,
        ]);
        $audit->record($request, 'eligibility.overridden', $override, [
            'decision' => $data['decision'],
            'reason_fingerprint' => $audit->fingerprint($data['reason'], 'eligibility-override-reason'),
        ]);

        return back()->with('success', 'Eligibility override recorded.');
    }

    public function destroyOverride(
        Request $request,
        Webinar $webinar,
        Participant $participant,
        EligibilityOverride $eligibilityOverride,
        AuditService $audit,
    ): RedirectResponse {
        $this->assertBelongsTo($webinar, $participant);
        abort_unless($eligibilityOverride->participant_id === $participant->id, 404);

        DB::transaction(function () use ($audit, $eligibilityOverride, $participant, $request): void {
            $lockedOverride = EligibilityOverride::query()
                ->whereKey($eligibilityOverride->id)
                ->where('participant_id', $participant->id)
                ->lockForUpdate()
                ->firstOrFail();
            $audit->record($request, 'eligibility.override_removed', $lockedOverride, [
                'decision' => $lockedOverride->decision,
            ]);
            $lockedOverride->delete();
        });

        return back()->with('success', 'Eligibility override removed. Eligibility was recalculated.');
    }

    public function attendance(Request $request, Webinar $webinar, Participant $participant, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $participant);

        $participant->update([
            'checked_in_at' => $participant->checked_in_at === null ? now() : null,
        ]);
        $audit->record($request, 'participant.attendance_toggled', $participant, [
            'checked_in' => $participant->checked_in_at !== null,
        ]);

        return back()->with('success', $participant->checked_in_at ? 'Participant marked present.' : 'Attendance mark removed.');
    }

    /** Correct the authoritative participant name used by every future certificate. */
    public function updateName(Request $request, Webinar $webinar, Participant $participant, AuditService $audit): RedirectResponse|JsonResponse
    {
        $this->assertBelongsTo($webinar, $participant);
        abort_if($participant->privacy_erased_at !== null, 422, 'This participant record is no longer editable.');

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:180'],
        ]);
        $manual = $participant->certificateApproval;
        if ($manual?->source === 'manual' && $manual->manages_identity
            && ($participant->certificates()->whereNull('revoked_at')->whereIn('status', ['processing', 'issued'])->exists()
                || (! $participant->certificates()->exists() && ! $participant->verified_at
                    && ! $participant->email_verified_at && ! $participant->checked_in_at && ! $participant->submissions()->exists()))) {
            throw ValidationException::withMessages(['full_name' => 'Manage manual recipient details in Certificate Studio. After issuance, use the correction or reissue workflow.']);
        }

        $name = trim($data['full_name']);

        $participant->update(['full_name' => $name]);
        $audit->record($request, 'participant.name_corrected', $participant);
        $hasCertificate = $participant->certificates()->whereNull('revoked_at')->whereIn('status', ['processing', 'issued'])->exists();
        $message = $hasCertificate
            ? 'Participant name corrected. An existing certificate keeps its earlier name. Revoke it and issue a corrected certificate; queued work using the earlier name will not finalize.'
            : 'Participant name corrected. Future certificate previews now use this name.';

        if ($request->expectsJson()) {
            return response()->json([
                'participant_id' => $participant->public_id,
                'full_name' => $name,
                'certificate_warning' => $hasCertificate ? $message : null,
            ]);
        }

        return back()->with($hasCertificate ? 'warning' : 'success', $message);
    }

    /**
     * Permanently remove a response and everything attached to it.
     *
     * Issued certificates are detached rather than deleted, because their public
     * verification record has to keep working after the response is gone.
     */
    public function destroy(
        Request $request,
        Webinar $webinar,
        Participant $participant,
        AuditService $audit,
        ParticipantPrivacyService $privacy,
    ): RedirectResponse {
        $this->assertBelongsTo($webinar, $participant);

        $privacy->erase($participant, forceDelete: true);
        $audit->record($request, 'participant.deleted', $webinar);

        return redirect()
            ->route('admin.participants.index', $webinar)
            ->with('success', 'Participant and their responses were deleted.');
    }

    /**
     * Reviewed identity merge for the email-A/email-B split: one person used
     * two mailboxes across forms. Never automatic; requires explicit reason and
     * confirmation. Blocked while either identity holds an active certificate
     * or conflicting responses for the same form.
     */
    public function merge(Request $request, Webinar $webinar, Participant $participant, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $participant);
        $data = $request->validate([
            'secondary_email' => ['required', 'email', 'max:254'],
            'reason' => ['required', 'string', 'min:10', 'max:2000'],
            'merge_confirmed' => ['accepted'],
        ], [
            'merge_confirmed.accepted' => 'Confirm the two records describe the same person before merging.',
        ]);

        $secondaryEmail = strtolower(trim($data['secondary_email']));
        if ($secondaryEmail === strtolower(trim((string) $participant->email))) {
            return back()->with('error', 'The two email addresses are the same record. No merge was performed.');
        }

        try {
            $merged = DB::transaction(function () use ($webinar, $participant, $secondaryEmail): Participant {
                $lockedWebinar = Webinar::query()->whereKey($webinar->id)->whereNull('deletion_started_at')->sharedLock()->firstOrFail();
                // Lock in id order to avoid deadlocks; both are child rows of the webinar.
                $ordered = Participant::query()
                    ->where('webinar_id', $lockedWebinar->id)
                    ->whereIn('email_normalized', [strtolower(trim((string) $participant->email)), $secondaryEmail])
                    ->whereNull('privacy_erased_at')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $primary = $ordered->firstWhere('id', $participant->id);
                $secondary = $ordered->firstWhere('email_normalized', $secondaryEmail);
                if (! $primary || ! $secondary) {
                    throw new RuntimeException('Both participant records must exist in this webinar and not be erased.');
                }
                foreach ([$primary, $secondary] as $record) {
                    if ($record->certificates()->whereNull('revoked_at')->whereIn('status', ['processing', 'issued'])->exists()) {
                        throw new RuntimeException('Revoke active certificates on both records before merging, then reissue after checking eligibility.');
                    }
                }
                $primaryForms = $primary->submissions()->where('status', 'submitted')->pluck('form_id')->all();
                $conflict = $secondary->submissions()->where('status', 'submitted')->whereIn('form_id', $primaryForms)->exists();
                if ($conflict) {
                    throw new RuntimeException('Both records hold a submitted response for the same form. Resolve which response is authoritative (revoke/correct) before merging.');
                }
                $secondary->submissions()->update(['participant_id' => $primary->id]);
                $secondary->eligibilityOverrides()->update(['participant_id' => $primary->id]);
                if (blank($primary->full_name) && filled($secondary->full_name)) {
                    $primary->full_name = $secondary->full_name;
                }
                if (blank($primary->organization) && filled($secondary->organization)) {
                    $primary->organization = $secondary->organization;
                }
                // The reviewed same-person merge retains event participation
                // facts. Mailbox ownership remains bound to the primary email.
                $primary->verified_at ??= $secondary->verified_at;
                $primary->checked_in_at ??= $secondary->checked_in_at;
                $primary->save();
                $secondary->accessTokens()->delete();
                $secondary->delete();

                return $primary->fresh();
            }, attempts: 3);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->record($request, 'participant.merged', $merged, [
            'secondary_email_fingerprint' => $audit->fingerprint($secondaryEmail, 'participant-merge-email'),
            'reason_fingerprint' => $audit->fingerprint($data['reason'], 'participant-merge-reason'),
        ]);

        return redirect()->route('admin.participants.show', [$webinar, $merged])
            ->with('success', 'The two records were merged. Eligibility was recalculated; issue or reissue only after review.');
    }

    /** @param array<string, mixed> $filters */
    private function query(array $filters, Webinar $webinar)
    {
        return $webinar->participants()
            ->select([
                'participants.id', 'participants.public_id', 'participants.webinar_id', 'participants.full_name',
                'participants.email', 'participants.organization',
                'participants.verified_at', 'participants.checked_in_at', 'participants.created_at',
            ])
            ->with([
                'submissions' => fn ($query) => $query->select([
                    'id', 'form_id', 'participant_id', 'attempt_number', 'status', 'score', 'maximum_score',
                ]),
                'certificates' => fn ($query) => $query
                    ->select(['id', 'participant_id', 'verification_code', 'issued_at', 'sent_at', 'revoked_at', 'status']),
            ])
            ->when(filled($filters['search'] ?? null), function ($query) use ($filters): void {
                $search = '%'.$filters['search'].'%';
                $query->where(fn ($nested) => $nested->where('full_name', 'like', $search)->orWhere('email', 'like', $search));
            })
            ->latest();
    }

    private function filterSessionKey(Webinar $webinar): string
    {
        return 'admin.participant_filters.webinar.'.$webinar->id;
    }

    private function assertBelongsTo(Webinar $webinar, Participant $participant): void
    {
        abort_unless($participant->webinar_id === $webinar->id, 404);
    }

    /**
     * Prefix cells Excel/LibreOffice could interpret as formulas. Quoting alone
     * is not protection: spreadsheet applications still execute quoted formulas.
     *
     * @param  resource  $handle
     */
    private function writeCsvRow($handle, array $row): void
    {
        fputcsv($handle, array_map($this->safeCsvCell(...), $row), escape: '');
    }

    private function safeCsvCell(mixed $value): string
    {
        return CsvSanitizer::safeCell($value);
    }
}
