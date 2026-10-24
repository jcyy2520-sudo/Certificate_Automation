<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Import;
use App\Models\UnattributedResponse;
use App\Models\Webinar;
use App\Services\AuditService;
use App\Services\UnattributedResponseService;
use App\Support\CsvSanitizer;
use App\Support\ResponseTable;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UnattributedResponseController extends Controller
{
    private function authorizeWorkspace(Webinar $webinar): void
    {
        Gate::authorize('permission', [$webinar, 'manage_participants']);
        abort_if($webinar->isUploadOnly() || $webinar->deletion_started_at !== null || $webinar->retention_due_at?->isPast(), 404);
    }

    private function selectedImport(Request $request, Webinar $webinar, UnattributedResponseService $responses): ?Import
    {
        $data = $request->validate(['import' => ['sometimes', 'integer', 'min:1']]);
        $query = $responses->imports($webinar);

        return isset($data['import']) ? $query->findOrFail($data['import']) : $query->latest('id')->first();
    }

    public function index(Request $request, Webinar $webinar, UnattributedResponseService $responses)
    {
        $this->authorizeWorkspace($webinar);
        $data = $request->validate([
            'view' => ['sometimes', Rule::in(['responses', 'summary'])], 'column' => ['sometimes', 'string', 'max:80'],
        ]);
        $selected = $this->selectedImport($request, $webinar, $responses);
        $columns = $selected ? $responses->columns($selected) : [];
        $view = $data['view'] ?? 'responses';
        $columnKey = $data['column'] ?? array_key_first($columns);
        abort_if($columnKey !== null && ! isset($columns[$columnKey]), 404);
        $summary = $view === 'summary' && $selected && $columnKey ? $responses->summary($selected, $columns[$columnKey]) : null;
        $summaryRows = null;
        if ($summary) {
            $page = max(1, LengthAwarePaginator::resolveCurrentPage('values_page'));
            $summaryRows = new LengthAwarePaginator(array_slice($summary['counts'], ($page - 1) * 25, 25), count($summary['counts']), 25, $page, [
                'path' => $request->url(), 'pageName' => 'values_page', 'query' => $request->query(),
            ]);
        }

        return view('admin.unattributed.index', [
            'webinar' => $webinar, 'selectedImport' => $selected, 'columns' => $columns, 'view' => $view,
            'imports' => $responses->imports($webinar)->latest('id')->paginate(10, ['*'], 'imports_page')->withQueryString(),
            'rows' => $selected && $view === 'responses' ? $responses->query($selected)->paginate(ResponseTable::rowsPerPage($columns))->withQueryString() : null,
            'summary' => $summary ? ['total' => $summary['total'], 'distinct' => count($summary['counts'])] : null,
            'summaryRows' => $summaryRows, 'columnKey' => $columnKey,
        ]);
    }

    public function show(Webinar $webinar, UnattributedResponse $unattributedResponse, UnattributedResponseService $responses)
    {
        $response = $unattributedResponse;
        $this->authorizeWorkspace($webinar);
        $import = $responses->imports($webinar)->findOrFail($response->import_id);
        abort_unless((int) $response->webinar_id === (int) $webinar->id && (int) $response->form_id === (int) $import->form_id, 404);

        return view('admin.unattributed.show', ['webinar' => $webinar, 'response' => $response, 'import' => $import, 'cells' => $response->originalCells($import)]);
    }

    public function export(Request $request, Webinar $webinar, UnattributedResponseService $responses, AuditService $audit)
    {
        $this->authorizeWorkspace($webinar);
        $import = $this->selectedImport($request, $webinar, $responses) ?? abort(404);
        $columns = $responses->columns($import);
        $audit->record($request, 'unattributed.exported', $import);

        return response()->streamDownload(function () use ($import, $columns, $responses): void {
            $out = fopen('php://output', 'wb');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, CsvSanitizer::uniqueHeaders(['Source row', ...array_column($columns, 'label'), 'Original imported cells (JSON)']), ',', '"', '');
            foreach ($responses->query($import)->lazy(100) as $response) {
                $original = $response->originalCells($import);
                fputcsv($out, array_map(CsvSanitizer::safeCell(...), [
                    $response->row_number, ...array_column($original, 'value'),
                    json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]), ',', '"', '');
            }
            fclose($out);
        }, 'unattributed-evaluation-'.$import->id.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
