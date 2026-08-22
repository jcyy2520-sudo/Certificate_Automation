<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EligibilityOverride;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\EligibilityService;
use App\Services\ParticipantPrivacyService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
                'Name', 'Email', 'Organization', 'Registered on',
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
                'participants.id', 'participants.webinar_id', 'participants.full_name',
                'participants.email', 'participants.organization',
                'participants.verified_at', 'participants.created_at',
            ])
            ->with([
                'submissions' => fn ($query) => $query->select([
                    'id', 'form_id', 'participant_id', 'attempt_number', 'status', 'score', 'maximum_score',
                ]),
                'certificates' => fn ($query) => $query
                    ->select(['id', 'participant_id', 'verification_code', 'issued_at', 'revoked_at']),
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
