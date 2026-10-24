<?php

namespace App\Services;

use App\Models\Import;
use App\Models\UnattributedResponse;
use App\Models\Webinar;
use Illuminate\Database\Eloquent\Builder;

/** Review literal evaluation cells without interpreting identity, scores or completion. */
class UnattributedResponseService
{
    public function imports(Webinar $webinar): Builder
    {
        return Import::query()->where('webinar_id', $webinar->id)
            ->where('status', Import::STATUS_COMMITTED)->whereNull('privacy_erased_at')
            ->where('column_map->options->response_mode', 'unattributed')
            ->whereHas('form', fn (Builder $query) => $query->where('webinar_id', $webinar->id)->where('type', 'evaluation'));
    }

    public function query(Import $import): Builder
    {
        return UnattributedResponse::query()->where('import_id', $import->id)
            ->where('webinar_id', $import->webinar_id)->where('form_id', $import->form_id)
            ->orderBy('row_number')->orderBy('id');
    }

    /** Stable keys preserve differences in source spelling and whitespace. */
    public function columns(Import $import): array
    {
        $columns = [];
        foreach ($import->column_map['headers'] ?? [] as $index => $label) {
            $columns['source_'.hash('sha256', $label)] = ['label' => $label, 'source_index' => $index];
        }

        return $columns;
    }

    /** Called only inside the import's existing locked transaction. */
    public function store(Import $import, int $rowNumber, array $headers, array $cells): UnattributedResponse
    {
        return UnattributedResponse::query()->create([
            'webinar_id' => $import->webinar_id, 'form_id' => $import->form_id, 'import_id' => $import->id,
            'row_number' => $rowNumber, 'original_data' => array_map(fn ($value) => (string) $value, array_values($cells)),
        ]);
    }

    /**
     * Count one selected column in source-row order. No splitting, trimming,
     * numeric conversion or sample cap changes the literal answers. One import
     * has at most 5,000 rows; the controller paginates every distinct value.
     */
    public function summary(Import $import, array $column): array
    {
        $counts = [];
        $positions = [];
        $total = 0;
        foreach ($this->query($import)->lazy(100) as $response) {
            $value = (string) ($response->original_data[$column['source_index']] ?? '');
            $key = 'literal:'.$value;
            if (! array_key_exists($key, $positions)) {
                $positions[$key] = count($counts);
                $counts[] = ['value' => $value, 'count' => 0];
            }
            $counts[$positions[$key]]['count']++;
            $total++;
        }

        return ['total' => $total, 'counts' => $counts];
    }
}
