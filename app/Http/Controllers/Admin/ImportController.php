<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Submission;
use App\Models\UnattributedResponse;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\CsvImportService;
use App\Services\FormResponseService;
use App\Services\ImportIssueReview;
use App\Services\ImportMappingProfileService;
use App\Services\ImportReviewService;
use App\Support\CsvSanitizer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use RuntimeException;
use Throwable;

/**
 * The CSV intake path: upload an exported Google Forms response file, confirm
 * which column carries what, and let the service match rows onto the
 * participant master list by email address alone.
 */
class ImportController extends Controller
{
    public function __construct(private CsvImportService $imports) {}

    public function index(Request $request, Webinar $webinar): View
    {
        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(FormResponseService::TYPES))],
            'issue_group' => ['sometimes', Rule::in(array_keys(ImportIssueReview::GROUPS))],
        ]);
        $review = app(ImportIssueReview::class);
        $counts = $review->counts($webinar, $filters['type'] ?? null);
        $webinar->load(['forms' => fn ($query) => $query->select(['id', 'webinar_id', 'type', 'title'])]);

        return view('admin.webinars.imports.index', [
            'webinar' => $webinar,
            'imports' => $webinar->imports()
                ->when($request->filled('type'), fn ($q) => $q->whereHas('form', fn ($f) => $f->where('type', $request->input('type'))))
                ->with(['form:id,webinar_id,type,title', 'importedBy:id,name'])
                ->latest('id')
                ->paginate(10)
                ->appends($request->only(['type', 'issues_page', 'issue_group'])),
            'issues' => $review->query($webinar, $filters['type'] ?? null, $filters['issue_group'] ?? 'all')
                ->with('import:id,form_id,original_filename')
                ->orderBy('issue_type')
                ->orderBy('id')
                ->paginate(200, ['*'], 'issues_page')
                ->appends($request->only(['type', 'page', 'issue_group'])),
            'counts' => $counts['types'],
            'issueGroups' => ImportIssueReview::GROUPS,
            'groupCounts' => $counts['groups'],
            'issueGroup' => $filters['issue_group'] ?? 'all',
        ]);
    }

    public function create(Request $request, Webinar $webinar): View
    {
        $type = $request->validate(['type' => ['sometimes', Rule::in(array_keys(FormResponseService::TYPES))]])['type'] ?? 'registration';

        return view('admin.webinars.imports.create', [
            'webinar' => $webinar,
            'selectedType' => $type,
            'forms' => $webinar->forms()->orderByRaw(
                "case type when 'registration' then 1 when 'pretest' then 2 when 'posttest' then 3 else 4 end",
            )->get(['id', 'type', 'title']),
        ]);
    }

    /**
     * First step of an upload: validate the file, store it transiently with a
     * pending import row, and send the administrator to the mapping screen.
     */
    public function preview(Request $request, Webinar $webinar, AuditService $audit): RedirectResponse
    {
        abort_if($webinar->deletion_started_at !== null, 409);

        $data = $request->validate([
            'form_id' => ['required', Rule::exists('forms', 'id')->where('webinar_id', $webinar->id)],
            'file' => ['required', 'file', 'max:4096', 'extensions:csv,xlsx'],
            'force' => ['sometimes', 'boolean'],
        ], [
            'file.extensions' => 'Upload a UTF-8 CSV or Excel .xlsx response file.',
        ]);

        $form = Form::query()->findOrFail($data['form_id']);

        $previous = Import::query()
            ->where('webinar_id', $webinar->id)
            ->where('form_id', $form->id)
            ->where('status', Import::STATUS_COMMITTED)
            ->where('file_hash', Import::hashContents((string) file_get_contents($request->file('file')->getRealPath())))
            ->first();

        if ($previous && ! $request->boolean('force')) {
            return back()
                ->withInput()
                ->withErrors(['file' => 'This exact file was already imported on '.$previous->created_at->format('M j, Y g:i A').'. Tick “Import anyway” if you are sure.']);
        }

        try {
            $import = $this->imports->storePending($webinar, $form, $request->file('file'));
        } catch (Throwable $exception) {
            return back()->withInput()->withErrors(['file' => $exception->getMessage()]);
        }

        $audit->record($request, 'import.uploaded', $import);

        return redirect()->route('admin.webinars.imports.map', [$webinar, $import]);
    }

    /** Second step: confirm which column carries which field. */
    public function map(Request $request, Webinar $webinar, Import $import): View|RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id && $import->status === Import::STATUS_PENDING, 404);
        // Expired imports must not remain previewable: retention erasure
        // deletes the source file, so block mapping once the deadline passes.
        if ($webinar->retention_due_at !== null && $webinar->retention_due_at->isPast()) {
            try {
                $this->imports->cancel($import);
            } catch (RuntimeException $exception) {
                return redirect()->route('admin.webinars.imports.index', $webinar)->with('error', $exception->getMessage());
            }

            return redirect()
                ->route('admin.webinars.imports.index', $webinar)
                ->with('error', 'This webinar is past its privacy retention deadline. The pending upload was cancelled.');
        }

        try {
            $data = $request->validate([
                'preview_page' => ['sometimes', 'integer', 'min:1', 'max:1000'],
                'step' => ['sometimes', Rule::in(['columns', 'review'])],
            ]);
            $step = $data['step'] ?? 'columns';
            $reviewResults = $import->column_map['review_results'] ?? null;
            if ($step === 'review' && (! is_array($reviewResults) || empty($import->column_map['review_hash']))) {
                return redirect()->route('admin.webinars.imports.map', [$webinar, $import])
                    ->with('warning', 'Confirm the columns and run the dry check before reviewing results.');
            }
            $previewTotal = $this->imports->previewRowCount($import);
            $previewPage = min((int) ($data['preview_page'] ?? 1), max(1, (int) ceil($previewTotal / CsvImportService::PREVIEW_ROWS)));
            $previewRows = $this->imports->previewRows($import, $previewPage);
            $headers = $import->column_map['headers'] ?? [];
            // Wrong-form/wrong-version signal: compare against the most recent
            // committed import for the same destination form. A changed
            // questionnaire is legitimate, but it must be a deliberate operator
            // decision — never a silent schema drift into the wrong requirement.
            $previousCommitted = Import::query()
                ->where('webinar_id', $webinar->id)
                ->where('form_id', $import->form_id)
                ->where('status', Import::STATUS_COMMITTED)
                ->where('id', '!=', $import->id)
                ->latest('id')
                ->first();
            $headerChanged = false;
            $headerChangeNote = null;
            if ($previousCommitted && is_array($previousCommitted->column_map['headers'] ?? null)) {
                $headerChanged = CsvImportService::headerSignature($previousCommitted->column_map['headers'])
                    !== CsvImportService::headerSignature($headers);
                if ($headerChanged) {
                    $when = $previousCommitted->committed_at ?? $previousCommitted->created_at;
                    $headerChangeNote = 'These columns differ from the last committed import for this form'
                        .($when ? ' ('.$when->format('M j, Y g:i A').')' : '')
                        .'. A revised questionnaire is fine, but confirm the source description names the new version — do not import a different form’s export here.';
                }
            }

            $profiles = app(ImportMappingProfileService::class);
            $mappingProfiles = $profiles->matching($import, $request->user());

            return view('admin.webinars.imports.map', [
                'webinar' => $webinar,
                'import' => $import,
                'headers' => $headers,
                'map' => $import->column_map['map'] ?? [],
                'fields' => CsvImportService::logicalFields($import->form->type),
                'step' => $step, 'reviewResults' => $reviewResults,
                'mappingProfiles' => $mappingProfiles,
                'profileChecks' => $mappingProfiles->mapWithKeys(fn ($profile) => [$profile->id => $profiles->compatibility($import, $profile)]),
                'previewRows' => $previewRows,
                'previewPagination' => new LengthAwarePaginator($previewRows, $previewTotal, CsvImportService::PREVIEW_ROWS, $previewPage, [
                    'path' => route('admin.webinars.imports.map', [$webinar, $import]), 'pageName' => 'preview_page', 'fragment' => 'source-preview',
                    'query' => $step === 'review' ? ['step' => 'review'] : [],
                ]),
                'headerChangeNote' => $headerChangeNote,
            ]);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.webinars.imports.create', $webinar)
                ->with('error', 'The pending file could not be read again: '.$exception->getMessage());
        }
    }

    /** Persist choices before leaving this screen; never store respondent samples. */
    public function choices(Request $request, Webinar $webinar, Import $import, ImportReviewService $review): RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id && $import->status === Import::STATUS_PENDING, 404);
        try {
            if ($request->boolean('mode_only')) {
                $review->changeMode($import, $request->input('response_mode'));
            } else {
                $review->save($import, $review->choices($import, $request->all()));
            }
        } catch (RuntimeException $exception) {
            return redirect()->route('admin.webinars.imports.map', [$webinar, $import])->withInput()->with('error', $exception->getMessage());
        }

        return redirect()->route('admin.webinars.imports.map', [$webinar, $import])->with('success', 'Your column choices are saved.');
    }

    /** Apply the confirmed mapping and commit the import. */
    public function store(Request $request, Webinar $webinar, Import $import, AuditService $audit): RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id && $import->status === Import::STATUS_PENDING, 404);
        abort_if($webinar->deletion_started_at !== null, 409);

        $request->validate(['review_confirmed' => ['accepted']], [
            'review_confirmed.accepted' => 'Confirm the destination, names, and dry-check results before importing.',
        ]);

        $chosen = collect(CsvImportService::logicalFields($import->form->type))
            ->mapWithKeys(fn ($label, $field) => [$field => $request->input('map.'.$field)])
            ->all();

        try {
            $mode = CsvImportService::responseMode($import, $request->input('response_mode', 'individual'));
            $map = CsvImportService::validateMap($import, $chosen, $mode);
            $timestampFormat = CsvImportService::validateTimestampFormat($request->input('timestamp_format'));
            $options = CsvImportService::validateOptions($import, $request->all(), $map);
            $reviewHash = CsvImportService::reviewHash($import, $map, $timestampFormat, $options);
            if (! hash_equals($import->column_map['review_hash'] ?? '', $reviewHash)) {
                throw new RuntimeException('Run the dry check with these exact columns and options before importing.');
            }
            $counts = $this->imports->process($import, $map, $timestampFormat, options: $options);
        } catch (RuntimeException $exception) {
            // Persist the failure fingerprint so a failed commit is retryable
            // with evidence instead of an ephemeral flash message only.
            app(ImportReviewService::class)->recordFailure($import, $exception->getMessage());

            // Nothing was committed; the pending upload stays available to retry.
            return redirect()
                ->route('admin.webinars.imports.map', [$webinar, $import])
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $audit->record($request, 'import.committed', $import, $counts + [
            'source_description' => $options['source_description'] ?? null,
        ]);

        $summary = "{$counts['valid']} response(s) imported, {$counts['duplicate']} duplicate(s), {$counts['unmatched']} held for review, {$counts['invalid']} invalid.";
        if ($counts['cleanup_pending'] ?? false) {
            $summary .= ' Responses are saved. Private source-file cleanup will be retried by the scheduled import cleanup; check storage permissions if this persists.';
        }
        if (($options['response_mode'] ?? 'individual') === 'unattributed') {
            return redirect()->route('admin.unattributed.index', [$webinar, 'import' => $import->id])->with('success', $summary);
        }
        if ($counts['unmatched'] > 0 || $counts['invalid'] > 0 || $counts['duplicate'] > 0) {
            return redirect()
                ->route('admin.webinars.imports.index', $webinar)
                ->with('warning', $summary.' Review the items below.');
        }

        return redirect()
            ->route('admin.webinars.imports.index', $webinar)
            ->with('success', $summary);
    }

    /** Exercise the full import parser and matching logic inside a rollback. */
    public function dryRun(Request $request, Webinar $webinar, Import $import, AuditService $audit): RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id && $import->status === Import::STATUS_PENDING, 404);

        $chosen = collect(CsvImportService::logicalFields($import->form->type))
            ->mapWithKeys(fn ($label, $field) => [$field => $request->input('map.'.$field)])
            ->all();

        try {
            $mode = CsvImportService::responseMode($import, $request->input('response_mode', 'individual'));
            $map = CsvImportService::validateMap($import, $chosen, $mode);
            $timestampFormat = CsvImportService::validateTimestampFormat($request->input('timestamp_format'));
            $options = CsvImportService::validateOptions($import, $request->all(), $map);
            $counts = $this->imports->process($import, $map, $timestampFormat, dryRun: true, options: $options);
            app(ImportReviewService::class)->save($import, ['map' => $map, 'timestamp_format' => $timestampFormat, 'options' => $options], $counts);
        } catch (RuntimeException $exception) {
            app(ImportReviewService::class)->recordFailure($import, $exception->getMessage());

            return redirect()
                ->route('admin.webinars.imports.map', [$webinar, $import])
                ->withInput()
                ->with('error', $exception->getMessage());
        }

        $audit->record($request, 'import.dry_run', $import, array_intersect_key($counts, array_flip(['total', 'valid', 'invalid', 'duplicate', 'unmatched'])) + ['timestamp_format' => $timestampFormat, 'source_description' => $options['source_description'] ?? null]);

        return redirect()
            ->route('admin.webinars.imports.map', [$webinar, $import, 'step' => 'review'])
            ->with('dry_run', $counts + ['timestamp_format' => $timestampFormat]);
    }

    /** A correction worksheet for the currently selected unresolved rows. */
    public function corrections(Request $request, Webinar $webinar, ImportIssueReview $review, AuditService $audit)
    {
        $filters = $request->validate([
            'type' => ['sometimes', Rule::in(array_keys(FormResponseService::TYPES))],
            'issue_group' => ['sometimes', Rule::in(array_keys(ImportIssueReview::GROUPS))],
            'import_id' => ['sometimes', 'integer', Rule::exists('imports', 'id')->where('webinar_id', $webinar->id)],
        ]);
        $query = $review->query($webinar, $filters['type'] ?? null, $filters['issue_group'] ?? 'all', $filters['import_id'] ?? null);
        $labels = [];
        foreach ((clone $query)->orderBy('id')->lazyById(200) as $issue) {
            foreach ($issue->raw_data ?? [] as $label => $value) {
                if ($label !== '_resolved' && is_scalar($value) && ! in_array($label, $labels, true)) {
                    $labels[] = $label;
                }
            }
            abort_if(count($labels) > 1000, 422, 'These imports have too many different columns for one correction sheet. Download one import at a time.');
        }
        $fixed = ['Import ID', 'Source row', 'Issue', 'Reason', 'Original source cells (JSON)'];
        $outputLabels = $fixed;
        foreach ($labels as $label) {
            $candidate = $label;
            for ($suffix = 1; in_array($candidate, $outputLabels, true); $suffix++) {
                $candidate = 'Source '.$suffix.': '.$label;
            }
            $outputLabels[] = $candidate;
        }
        $audit->record($request, 'import.corrections_exported', $webinar, array_intersect_key($filters, array_flip(['type', 'issue_group', 'import_id'])));

        return response()->streamDownload(function () use ($query, $labels, $outputLabels): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, CsvSanitizer::uniqueHeaders($outputLabels), ',', '"', '');
            foreach ((clone $query)->orderBy('id')->lazyById(200) as $issue) {
                $original = array_filter($issue->raw_data ?? [], fn ($value, $label) => $label !== '_resolved' && is_scalar($value), ARRAY_FILTER_USE_BOTH);
                fputcsv($out, array_map(CsvSanitizer::safeCell(...), [$issue->import_id, $issue->row_number, $issue->issue_type, $issue->issue_message,
                    json_encode($original, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    ...array_map(fn ($label) => $original[$label] ?? '', $labels),
                ]), ',', '"', '');
            }
            fclose($out);
        }, 'held-response-corrections.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function cancel(Request $request, Webinar $webinar, Import $import, AuditService $audit): RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id, 404);

        $wasPending = $import->status === Import::STATUS_PENDING;
        try {
            $this->imports->cancel($import);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        $audit->record($request, 'import.cancelled', $import);

        return redirect()
            ->route($wasPending ? 'admin.webinars.imports.create' : 'admin.webinars.imports.index', $webinar)
            ->with('success', 'The upload was cancelled and its temporary copy deleted.');
    }

    /**
     * Download the durable outcome record, not a second copy of the source file.
     * This is deliberately password-confirmed by the route because it includes
     * participant information; SecurityHeaders marks it no-store as well.
     */
    public function reconciliation(Webinar $webinar, Import $import)
    {
        abort_unless($import->webinar_id === $webinar->id && $import->status === Import::STATUS_COMMITTED, 404);

        $filename = 'import-reconciliation-'.$import->id.'.csv';

        return response()->streamDownload(function () use ($import): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, ['Outcome', 'Source row', 'Email', 'Participant', 'Submitted at', 'Score', 'Maximum score', 'Detail'], ',', '"', '');

            $submissions = Submission::query()
                ->with('participant:id,email,full_name')
                ->where('form_id', $import->form_id)
                ->where('metadata->import_id', $import->id)
                ->get();
            $issues = $import->issues()->orderBy('row_number')->get();
            $recordedRows = [];

            foreach ($submissions as $submission) {
                $metadata = $submission->metadata ?? [];
                $recordedRows[] = $metadata['import_row_number'] ?? null;
                fputcsv($out, array_map($this->csvSafe(...), [
                    $submission->status === 'superseded' ? 'Superseded' : 'Imported',
                    $metadata['import_row_number'] ?? '',
                    $submission->participant?->email ?? '',
                    $submission->participant?->full_name ?? '',
                    $submission->submitted_at?->toIso8601String() ?? '',
                    $submission->score ?? '',
                    $submission->maximum_score ?? '',
                    $submission->status === 'superseded'
                        ? 'Preserved original; replaced by import '.($metadata['superseded_by_import'] ?? '').'.'
                        : 'Timestamp: '.($metadata['timestamp_source'] ?? 'source').'; completion: '.($metadata['completion_check'] ?? 'legacy import').'.',
                ]), ',', '"', '');
            }

            foreach (UnattributedResponse::query()->where('webinar_id', $import->webinar_id)->where('form_id', $import->form_id)->where('import_id', $import->id)->orderBy('row_number')->cursor(['id', 'row_number']) as $response) {
                $recordedRows[] = $response->row_number;
                fputcsv($out, ['Saved feedback', $response->row_number, '', '', '', '', '', 'Unattributed evaluation; feedback only, without individual completion.'], ',', '"', '');
            }

            foreach ($issues as $issue) {
                if (in_array($issue->row_number, $recordedRows, true)) {
                    continue;
                }
                fputcsv($out, array_map($this->csvSafe(...), [
                    $issue->resolved_at === null ? 'Held for review' : 'Resolved',
                    $issue->row_number,
                    $issue->normalized_email ?? '',
                    '',
                    '',
                    '',
                    '',
                    $issue->resolution ?? $issue->issue_message,
                ]), ',', '"', '');
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Prevent spreadsheet software from evaluating untrusted import values as formulas. */
    private function csvSafe(mixed $value): string
    {
        return CsvSanitizer::safeCell($value);
    }

    /** Create the participant a held-for-review row describes, attaching its response. */
    public function promote(Request $request, Webinar $webinar, ImportIssue $importIssue, AuditService $audit): RedirectResponse
    {
        abort_unless($importIssue->webinar_id === $webinar->id, 404);
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:180'],
            'response_verified' => ['accepted'],
        ]);

        try {
            $participant = $this->imports->promote($importIssue, $request->user(), $data['full_name']);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        $audit->record($request, 'import_issue.promoted', $participant, ['import_issue_id' => $importIssue->id]);

        return back()->with('success', 'The participant was added and the response attached.');
    }

    public function dismiss(Request $request, Webinar $webinar, ImportIssue $importIssue, AuditService $audit): RedirectResponse
    {
        abort_unless($importIssue->webinar_id === $webinar->id, 404);

        $this->imports->dismiss($importIssue, $request->user());
        $audit->record($request, 'import_issue.dismissed', $importIssue);

        return back()->with('success', 'The row was dismissed without action.');
    }

    public function replaceResponse(Request $request, Webinar $webinar, ImportIssue $importIssue, AuditService $audit): RedirectResponse
    {
        abort_unless($importIssue->webinar_id === $webinar->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:500'], 'replacement_confirmed' => ['accepted']]);
        try {
            $submission = $this->imports->replaceResponse($importIssue, $request->user(), $data['reason']);
        } catch (RuntimeException $exception) {
            return back()->with('error', $exception->getMessage());
        }
        $audit->record($request, 'import.response_replaced', $submission, ['import_issue_id' => $importIssue->id]);

        return back()->with('success', 'The corrected response is now active. The previous response is preserved; certificate eligibility is recalculated.');
    }
}
