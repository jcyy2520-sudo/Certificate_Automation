<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Form;
use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\Submission;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\CsvImportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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
        $this->imports->pruneStalePending();

        $webinar->load(['forms' => fn ($query) => $query->select(['id', 'webinar_id', 'type', 'title'])]);

        return view('admin.webinars.imports.index', [
            'webinar' => $webinar,
            'imports' => $webinar->imports()
                ->with(['form:id,webinar_id,type,title', 'importedBy:id,name'])
                ->latest('id')
                ->paginate(10),
            'issues' => $webinar->importIssues()
                ->with('import:id,form_id,original_filename')
                ->whereNull('resolved_at')
                ->orderBy('issue_type')
                ->orderBy('id')
                ->limit(200)
                ->get(),
            'counts' => $webinar->importIssues()
                ->whereNull('resolved_at')
                ->selectRaw('issue_type, count(*) as total')
                ->groupBy('issue_type')
                ->pluck('total', 'issue_type'),
        ]);
    }

    public function create(Webinar $webinar): View
    {
        return view('admin.webinars.imports.create', [
            'webinar' => $webinar,
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
            'file' => ['required', 'file', 'max:4096', 'extensions:csv'],
            'force' => ['sometimes', 'boolean'],
        ], [
            'file.extensions' => 'Upload the response file as .csv (export it from Google Forms using File → Download → Comma-separated values).',
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
    public function map(Webinar $webinar, Import $import): View|RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id && $import->status === Import::STATUS_PENDING, 404);

        try {
            return view('admin.webinars.imports.map', [
                'webinar' => $webinar,
                'import' => $import,
                'headers' => $import->column_map['headers'] ?? [],
                'map' => $import->column_map['map'] ?? [],
                'fields' => CsvImportService::logicalFields($import->form->type),
                'previewRows' => $this->imports->previewRows($import),
            ]);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.webinars.imports.create', $webinar)
                ->with('error', 'The pending file could not be read again: '.$exception->getMessage());
        }
    }

    /** Apply the confirmed mapping and commit the import. */
    public function store(Request $request, Webinar $webinar, Import $import, AuditService $audit): RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id && $import->status === Import::STATUS_PENDING, 404);
        abort_if($webinar->deletion_started_at !== null, 409);

        $chosen = collect(CsvImportService::logicalFields($import->form->type))
            ->mapWithKeys(fn ($label, $field) => [$field => $request->input('map.'.$field)])
            ->all();

        try {
            $map = CsvImportService::validateMap($import, $chosen);
            $timestampFormat = CsvImportService::validateTimestampFormat($request->input('timestamp_format'));
            $counts = $this->imports->process($import, $map, $timestampFormat);
        } catch (RuntimeException $exception) {
            // Nothing was committed; the pending upload stays available to retry.
            return redirect()
                ->route('admin.webinars.imports.map', [$webinar, $import])
                ->with('error', $exception->getMessage());
        }

        $audit->record($request, 'import.committed', $import, $counts);

        $summary = "{$counts['valid']} response(s) imported, {$counts['duplicate']} duplicate(s), {$counts['unmatched']} held for review, {$counts['invalid']} invalid.";
        if ($counts['unmatched'] > 0 || $counts['invalid'] > 0) {
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
            $map = CsvImportService::validateMap($import, $chosen);
            $timestampFormat = CsvImportService::validateTimestampFormat($request->input('timestamp_format'));
            $counts = $this->imports->process($import, $map, $timestampFormat, dryRun: true);
        } catch (RuntimeException $exception) {
            return redirect()
                ->route('admin.webinars.imports.map', [$webinar, $import])
                ->with('error', $exception->getMessage());
        }

        $audit->record($request, 'import.dry_run', $import, $counts + ['timestamp_format' => $timestampFormat]);

        return redirect()
            ->route('admin.webinars.imports.map', [$webinar, $import])
            ->with('dry_run', $counts + ['timestamp_format' => $timestampFormat]);
    }

    public function cancel(Request $request, Webinar $webinar, Import $import, AuditService $audit): RedirectResponse
    {
        abort_unless($import->webinar_id === $webinar->id, 404);

        $wasPending = $import->status === Import::STATUS_PENDING;
        $this->imports->cancel($import);
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
            fputcsv($out, ['Outcome', 'Source row', 'Email', 'Participant', 'Submitted at', 'Score', 'Maximum score', 'Detail']);

            $submissions = Submission::query()
                ->with('participant:id,email,full_name')
                ->where('form_id', $import->form_id)
                ->where('metadata->import_id', $import->id)
                ->get();

            foreach ($submissions as $submission) {
                $metadata = $submission->metadata ?? [];
                fputcsv($out, array_map($this->csvSafe(...), [
                    'Imported',
                    $metadata['import_row_number'] ?? '',
                    $submission->participant?->email ?? '',
                    $submission->participant?->full_name ?? '',
                    $submission->submitted_at?->toIso8601String() ?? '',
                    $submission->score ?? '',
                    $submission->maximum_score ?? '',
                    'Recorded without overwrite.',
                ]));
            }

            foreach ($import->issues()->orderBy('row_number')->get() as $issue) {
                fputcsv($out, array_map($this->csvSafe(...), [
                    $issue->resolved_at === null ? 'Held for review' : 'Resolved',
                    $issue->row_number,
                    $issue->normalized_email ?? '',
                    '',
                    '',
                    '',
                    '',
                    $issue->resolution ?? $issue->issue_message,
                ]));
            }

            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Prevent spreadsheet software from evaluating untrusted import values as formulas. */
    private function csvSafe(mixed $value): string
    {
        $value = (string) $value;

        return preg_match('/^[=+\-@]/', $value) === 1 ? "'".$value : $value;
    }

    /** Create the participant a held-for-review row describes, attaching its response. */
    public function promote(Request $request, Webinar $webinar, ImportIssue $importIssue, AuditService $audit): RedirectResponse
    {
        abort_unless($importIssue->webinar_id === $webinar->id, 404);

        try {
            $participant = $this->imports->promote($importIssue, $request->user());
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
}
