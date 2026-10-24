<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CertificateApproval;
use App\Models\Submission;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\EligibilityService;
use App\Services\FormResponseService;
use App\Support\CsvSanitizer;
use App\Support\ResponseTable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FormResponseController extends Controller
{
    private function selectedForm(Request $request, Webinar $webinar)
    {
        $data = $request->validate(['type' => ['sometimes', Rule::in(array_keys(FormResponseService::TYPES))]]);
        $webinar->loadMissing('forms', 'eligibilityRules');

        return $webinar->forms->firstWhere('type', $data['type'] ?? 'registration') ?? abort(404);
    }

    public function index(Request $request, Webinar $webinar, FormResponseService $responses, EligibilityService $eligibility)
    {
        $form = $this->selectedForm($request, $webinar);
        $view = $request->validate(['view' => ['sometimes', Rule::in(['responses', 'summary', 'eligibility', 'settings'])]])['view'] ?? 'responses';
        $filters = $request->session()->get('responses.'.$webinar->id.'.'.$form->type, []);
        $columns = in_array($view, ['responses', 'summary'], true) ? $responses->columns($form) : [];
        $rows = $view === 'responses' ? $responses->query($form, $filters)->with([
            'participant' => fn ($q) => $q->withCount(['submissions as form_response_count' => fn ($q) => $q->where('form_id', $form->id)]),
            'answers',
        ])->paginate(ResponseTable::rowsPerPage($columns))->withQueryString() : null;
        $participants = null;
        if ($view === 'eligibility') {
            $status = $request->validate(['status' => ['sometimes', Rule::in(['', 'eligible', 'incomplete', 'approved', 'reviewed'])]])['status'] ?? '';
            $query = $webinar->participants()->whereNull('privacy_erased_at');
            if (in_array($status, ['eligible', 'incomplete'], true)) {
                $method = $status === 'eligible' ? 'whereIn' : 'whereNotIn';
                $query->$method('participants.id', $eligibility->eligibleParticipantsQuery($webinar));
            } elseif ($status === 'approved') {
                $query->whereHas('certificateApproval');
            } elseif ($status === 'reviewed') {
                $query->whereHas('eligibilityOverrides', fn ($q) => $q->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now())));
            }
            if (filled($filters['search'] ?? null)) {
                $query->where(fn ($q) => $q->where('full_name', 'like', '%'.$filters['search'].'%')->orWhere('email', 'like', '%'.$filters['search'].'%'));
            }
            $participants = $query->with([
                'submissions:id,participant_id,form_id,status,score',
                'submissions.form:id,webinar_id,type',
                'certificateApproval.administrator',
            ])->orderBy('full_name')->paginate(25)->withQueryString();
            $eligibility->attachTo($participants->getCollection(), $webinar);
        }

        return view('admin.responses.index', [
            'webinar' => $webinar, 'form' => $form, 'view' => $view, 'columns' => $columns, 'rows' => $rows,
            'filters' => $filters, 'participants' => $participants, 'responses' => $responses,
            'summary' => $view === 'summary' ? $responses->summary($form, $columns) : null,
            'rules' => $webinar->eligibilityRules->keyBy('requirement'),
            'snapshot' => $eligibility->ruleSnapshot($webinar),
            'responseCount' => $view === 'responses' ? $responses->query($form)->count() : null,
            'reviewCount' => $webinar->importIssues()->whereNull('resolved_at')->count(),
        ]);
    }

    public function filter(Request $request, Webinar $webinar)
    {
        $form = $this->selectedForm($request, $webinar);
        $data = $request->validate([
            'search' => ['nullable', 'string', 'max:180'], 'source' => ['nullable', Rule::in(['native', 'imported'])],
            'sort' => ['nullable', Rule::in(['newest', 'oldest', 'score'])], 'history' => ['nullable', Rule::in(['all'])],
            'view' => ['nullable', Rule::in(['responses', 'eligibility'])],
        ]);
        // Follow the existing privacy convention: names/email searches stay out of URLs.
        $request->session()->put('responses.'.$webinar->id.'.'.$form->type, $request->boolean('clear') ? [] : $data);

        return redirect()->route('admin.responses.index', [$webinar, 'type' => $form->type, 'view' => $data['view'] ?? 'responses']);
    }

    public function show(Webinar $webinar, Submission $submission, FormResponseService $responses)
    {
        abort_unless($submission->form?->webinar_id === $webinar->id && $submission->participant?->webinar_id === $webinar->id
            && $submission->participant->privacy_erased_at === null && $submission->answers_erased_at === null, 404);
        $columns = $responses->columns($submission->form);
        $columns = array_replace($columns, $submission->metadata['response_schema'] ?? [], $submission->imported_data['schema'] ?? []);

        return view('admin.responses.show', ['webinar' => $webinar, 'submission' => $submission, 'columns' => $columns,
            'values' => $responses->displayValues($submission, $columns), 'sourceIdentity' => $responses->sourceIdentity($submission)]);
    }

    public function export(Request $request, Webinar $webinar, FormResponseService $responses, AuditService $audit)
    {
        $form = $this->selectedForm($request, $webinar);
        $columns = $responses->columns($form);
        $filters = $request->session()->get('responses.'.$webinar->id.'.'.$form->type, []);
        $audit->record($request, 'responses.exported', $form);

        return response()->streamDownload(function () use ($form, $columns, $filters, $responses): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, CsvSanitizer::uniqueHeaders(['Name', 'Email', 'Submitted at (UTC)', 'Source', 'Status', 'Score', ...array_column($columns, 'label'), 'Original imported cells (JSON)']), ',', '"', '');
            foreach ($responses->query($form, $filters)->with(['participant', 'answers'])->lazy(200) as $row) {
                $values = $responses->displayValues($row, $columns);
                $identity = $responses->sourceIdentity($row);
                fputcsv($out, array_map(CsvSanitizer::safeCell(...), [
                    $identity['name'], $identity['email'], $row->submitted_at?->toIso8601String(),
                    ($row->metadata['source'] ?? '') === 'csv_import' ? 'Imported' : 'Native', $row->status, $row->score,
                    ...array_map(fn ($key) => implode(', ', $values[$key] ?? []), array_keys($columns)),
                    $row->imported_data ? json_encode($row->imported_data['original'] ?? [], JSON_UNESCAPED_UNICODE) : '',
                ]), ',', '"', '');
            }
            fclose($out);
        }, $form->type.'-responses.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function approve(Request $request, Webinar $webinar, EligibilityService $eligibility, AuditService $audit)
    {
        $data = $request->validate(['participants' => ['required', 'array', 'min:1', 'max:200'], 'participants.*' => ['required', 'string', 'distinct'], 'confirmed' => ['accepted']]);
        $count = DB::transaction(function () use ($request, $webinar, $data, $eligibility, $audit): int {
            $webinar = Webinar::query()->whereKey($webinar->id)->whereNull('deletion_started_at')->lockForUpdate()->firstOrFail();
            abort_if($webinar->retention_due_at?->isPast(), 409, 'This webinar has reached its retention deadline.');
            $participants = $webinar->participants()->whereNull('privacy_erased_at')->whereIn('public_id', $data['participants'])->orderBy('id')->lockForUpdate()->get();
            if ($participants->count() !== count($data['participants'])) {
                throw ValidationException::withMessages(['participants' => 'Some selected participants are unavailable. Refresh and review the selection.']);
            }
            $count = 0;
            foreach ($participants as $participant) {
                $evaluation = $eligibility->evaluate($participant);
                if (! $evaluation['eligible'] || blank($participant->email) || blank($participant->full_name)) {
                    throw ValidationException::withMessages(['participants' => 'A selected participant no longer qualifies or is missing a name/email. Nothing was approved. Refresh the review.']);
                }
                $snapshot = [
                    'rules' => $eligibility->ruleSnapshot($webinar), 'evaluation' => $evaluation,
                    'submissions' => $participant->submissions->where('status', 'submitted')->sortBy('id')->map(fn ($submission) => [
                        'id' => $submission->id, 'form_id' => $submission->form_id,
                        'source' => ($submission->metadata['source'] ?? '') === 'csv_import' ? 'imported' : 'native',
                        'import_id' => $submission->metadata['import_id'] ?? null,
                    ])->values()->all(),
                    'override_id' => $participant->eligibilityOverrides()
                        ->where(fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now()))
                        ->latest()->orderByDesc('id')->value('id'),
                ];
                $approval = CertificateApproval::query()->firstOrNew(['participant_id' => $participant->id]);
                if ($approval->exists && $approval->eligibility_snapshot === $snapshot) {
                    continue;
                }
                $approval->fill(['approved_by' => $request->user()->id, 'eligibility_snapshot' => $snapshot, 'approved_at' => now()])->save();
                $audit->record($request, 'certificate.approved', $participant, $snapshot);
                $count++;
            }

            return $count;
        }, attempts: 3);

        return redirect()->route('admin.responses.index', [$webinar, 'view' => 'eligibility'])->with('success', $count.' participant(s) approved for Certificate Studio. Existing approvals were preserved; no certificate was generated.');
    }
}
