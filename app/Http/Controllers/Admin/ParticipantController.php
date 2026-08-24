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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
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

    /**
     * Add an off-platform attendee to the participant table. The table is the
     * only place an administrator creates certificate recipients: once added,
     * the participant is explicitly eligible and preselected for review.
     */
    public function store(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        abort_if($webinar->deletion_started_at !== null, 409);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'organization' => ['nullable', 'string', 'max:180'],
        ]);
        $email = Str::lower(trim($data['email']));
        $name = trim($data['full_name']);

        $outcome = DB::transaction(function () use ($webinar, $email, $name, $data, $request): array {
            $participant = Participant::withTrashed()
                ->where('webinar_id', $webinar->id)
                ->where('email_normalized', $email)
                ->lockForUpdate()
                ->first();

            if (! $participant) {
                $participant = Participant::query()->create([
                    'webinar_id' => $webinar->id,
                    'email' => $email,
                    'full_name' => $name,
                    'organization' => filled($data['organization'] ?? null) ? trim($data['organization']) : null,
                ]);
                $participant = Participant::withTrashed()->lockForUpdate()->findOrFail($participant->id);
            }

            if ($participant->privacy_erased_at !== null || $participant->trashed()) {
                return ['status' => 'unavailable', 'participant' => $participant];
            }

            $participant->forceFill([
                'full_name' => $name,
                'email' => $email,
                'organization' => filled($data['organization'] ?? null)
                    ? trim($data['organization'])
                    : $participant->organization,
            ])->save();

            EligibilityOverride::query()->create([
                'participant_id' => $participant->id,
                'decision' => 'eligible',
                'reason' => 'Added by an administrator from the participant table.',
                'overridden_by' => $request->user()->id,
            ]);

            return ['status' => 'added', 'participant' => $participant];
        }, attempts: 3);

        if ($outcome['status'] === 'unavailable') {
            return back()->withInput()->with('error', 'That participant record was previously erased and cannot be restored.');
        }

        $participant = $outcome['participant'];
        $audit->record($request, 'participant.added_manually', $participant, [
            'email_fingerprint' => $audit->fingerprint($email, 'manual-participant-email'),
        ]);
        $request->session()->forget($this->filterSessionKey($webinar));

        return redirect()->route('admin.participants.index', $webinar)
            ->with('new_participant_public_id', $participant->public_id)
            ->with('success', $name.' was added and selected. Review the row, then send the certificate.');
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

    /** Download the completion list as CSV, for sending certificates by hand. */
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
                'Meets all requirements', 'Certificate',
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
                        ->select(['id', 'participant_id', 'verification_code', 'issued_at', 'revoked_at']),
                ])
                ->chunkById(200, function ($participants) use ($handle, $webinar, $forms, $scored, $eligibility): void {
                    $eligibility->attachTo($participants, $webinar);

                    foreach ($participants as $participant) {
                        $certificate = $participant->certificates->first(fn ($item) => $item->isPubliclyValid());
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
                            $completed = $form->type === 'registration'
                                ? $participant->verified_at !== null
                                : $participant->submissions->firstWhere('form_id', $form->id) !== null;
                            $row[] = $completed ? 'Yes' : 'No';
                        }

                        foreach ($scored as $form) {
                            $submission = $participant->submissions
                                ->where('form_id', $form->id)
                                ->sortByDesc('attempt_number')
                                ->first();
                            $row[] = $submission?->score !== null
                                ? number_format((float) $submission->score, 1).' / '.number_format((float) $submission->maximum_score, 1)
                                : '';
                        }

                        $row[] = $participant->eligibility['eligible'] ? 'Yes' : 'No';
                        $row[] = $certificate?->verification_code ?? '';

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
        $participant->load(['submissions.form', 'certificates', 'eligibilityOverrides.administrator']);

        return view('admin.participants.show', [
            'webinar' => $webinar,
            'participant' => $participant,
            'eligibility' => $eligibilityService->evaluate($participant),
        ]);
    }

    public function override(Request $request, Webinar $webinar, Participant $participant, AuditService $audit): RedirectResponse
    {
        $this->assertBelongsTo($webinar, $participant);
        $data = $request->validate([
            'decision' => ['required', Rule::in(['eligible', 'ineligible'])],
            'reason' => ['required', 'string', 'min:5', 'max:2000'],
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
            'full_name' => ['required', 'string', 'max:120'],
        ]);
        $name = trim($data['full_name']);

        $participant->update(['full_name' => $name]);
        $audit->record($request, 'participant.name_corrected', $participant);

        if ($request->expectsJson()) {
            return response()->json([
                'participant_id' => $participant->public_id,
                'full_name' => $name,
            ]);
        }

        return back()->with('success', 'Participant name corrected. Future certificate previews now use this name.');
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
        $value = str_replace("\0", '', (string) ($value ?? ''));

        if (preg_match('/^[\p{Z}\x00-\x20]*[=+\-@]/u', $value)) {
            return "'".$value;
        }

        return $value;
    }
}
