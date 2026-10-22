<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Import;
use App\Models\Submission;
use Illuminate\Database\Eloquent\Builder;

/** Read native and imported answers through one form-owned column definition. */
class FormResponseService
{
    public const TYPES = ['registration' => 'Registration', 'pretest' => 'Pre-Test', 'posttest' => 'Post-Test', 'evaluation' => 'Evaluation'];

    public function columns(Form $form): array
    {
        $columns = $this->nativeColumns($form);
        foreach ($form->fields()->onlyTrashed()->get() as $field) {
            $columns['field_'.$field->id] = ['label' => $field->label.' (retired)', 'type' => $field->field_type,
                'options' => $field->options ?? [], 'required' => false];
        }
        foreach ($form->questions()->onlyTrashed()->with('choices')->get() as $question) {
            $columns['question_'.$question->id] = ['label' => $question->prompt.' (retired)', 'type' => $question->question_type,
                'options' => $question->choices->pluck('label', 'id')->all(), 'required' => false];
        }
        foreach (Import::query()->where('form_id', $form->id)->where('webinar_id', $form->webinar_id)
            ->where('status', Import::STATUS_COMMITTED)->whereNull('privacy_erased_at')->orderBy('id')->lazy(100) as $import) {
            $schema = $import->column_map['response_schema'] ?? app(ImportedResponseSchema::class)->columns(
                $import->setRelation('form', $form), $import->column_map['map'] ?? [], $import->column_map['options'] ?? []
            );
            foreach ($schema as $key => $column) {
                if (! isset($columns[$key])) {
                    $columns[$key] = $column;
                    // Required flags belong to that import, not every historical response.
                    $columns[$key]['required'] = false;
                }
            }
        }

        return $columns;
    }

    public function nativeColumns(Form $form): array
    {
        $form->loadMissing(['fields', 'questions.choices']);
        $columns = [];
        foreach ($form->fields as $field) {
            $columns['field_'.$field->id] = [
                'label' => $field->label, 'type' => $field->field_type, 'required' => $field->is_required,
                'options' => $field->options ?? [],
            ];
        }
        foreach ($form->questions as $question) {
            $columns['question_'.$question->id] = [
                'label' => $question->prompt, 'type' => $question->question_type, 'required' => $question->is_required,
                'options' => $question->choices->pluck('label', 'id')->all(),
            ];
        }

        return $columns;
    }

    public function query(Form $form, array $filters = []): Builder
    {
        $query = $form->submissions()->getQuery()
            ->whereNull('answers_erased_at')
            ->whereHas('participant', fn ($query) => $query->where('webinar_id', $form->webinar_id)->whereNull('privacy_erased_at'));
        if (($filters['history'] ?? '') !== 'all') {
            $query->where('status', 'submitted');
        }
        if (filled($filters['search'] ?? null)) {
            $search = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']).'%';
            $query->whereHas('participant', fn ($q) => $q->where(fn ($q) => $q
                ->whereRaw('LOWER(full_name) LIKE ?', [mb_strtolower($search)])
                ->orWhereRaw('LOWER(email) LIKE ?', [mb_strtolower($search)])));
        }
        if (($filters['source'] ?? '') === 'imported') {
            $query->where('metadata->source', 'csv_import');
        } elseif (($filters['source'] ?? '') === 'native') {
            $query->where(fn ($q) => $q->whereNull('metadata->source')->orWhere('metadata->source', '!=', 'csv_import'));
        }
        $sort = in_array($filters['sort'] ?? '', ['oldest', 'score'], true) ? $filters['sort'] : 'newest';

        return $query->orderBy($sort === 'score' ? 'score' : 'submitted_at', $sort === 'oldest' ? 'asc' : 'desc')->orderByDesc('id');
    }

    /** Values are labels, never opaque quiz choice IDs. Imported values retain exact spelling. */
    public function values(Submission $submission, array $columns): array
    {
        $definitions = $submission->metadata['response_schema'] ?? [];
        // Encrypted array casts decrypt on access; reuse this row's payload.
        $data = $submission->imported_data;
        $values = [];
        foreach ($submission->answers as $answer) {
            $key = $answer->form_field_id ? 'field_'.$answer->form_field_id : 'question_'.$answer->question_id;
            $value = $answer->value[0] ?? null;
            if ($answer->question_id && ($columns[$key]['type'] ?? 'text') !== 'text') {
                $value = $definitions[$key]['options'][$value] ?? $columns[$key]['options'][$value] ?? $value;
            }
            $values[$key] = is_array($value) ? array_values($value) : ($value === null ? [] : [(string) $value]);
        }
        foreach ($data['mapped'] ?? [] as $key => $value) {
            $values[$key] = is_array($value) ? $value : [$value];
        }
        $schema = $data['schema'] ?? [];
        $sourceKeys = [];
        foreach ($schema as $schemaKey => $column) {
            if (isset($column['source_index'])) {
                $sourceKeys[$column['source_index']] = $schemaKey;
            }
        }
        foreach ($data['original'] ?? [] as $index => $cell) {
            $key = ImportedResponseSchema::extraKey($cell['label']);
            if (isset($columns[$key]) && ($schema === [] || isset($schema[$key]))) {
                $values[$key] = [(string) ($cell['value'] ?? '')];
            }
            if (isset($sourceKeys[$index]) && ! isset($values[$sourceKeys[$index]])) {
                $values[$sourceKeys[$index]] = [(string) ($cell['value'] ?? '')];
            }
        }

        return $values;
    }

    /** Stream the dataset in bounded chunks; retain at most 20 text examples per question. */
    public function summary(Form $form, array $columns): array
    {
        $summary = ['total' => 0, 'native' => 0, 'imported' => 0, 'days' => [], 'questions' => [],
            'scores' => ['count' => 0, 'sum' => 0, 'min' => null, 'max' => null]];
        $options = [];
        foreach ($columns as $key => $column) {
            $options[$key] = array_map('strval', array_values($column['options']));
            $summary['questions'][$key] = ['answered' => 0, 'counts' => [], 'texts' => [], 'unrecognized' => 0,
                'numbers' => ['count' => 0, 'sum' => 0, 'min' => null, 'max' => null]];
        }
        $this->query($form)->reorder()->with('answers')->chunkById(200, function ($rows) use (&$summary, $columns, $form, $options): void {
            foreach ($rows as $row) {
                $summary['total']++;
                if ($row->score !== null) {
                    $this->addNumber($summary['scores'], (float) $row->score);
                }
                $summary[($row->metadata['source'] ?? '') === 'csv_import' ? 'imported' : 'native']++;
                $day = $row->submitted_at?->timezone($form->webinar->timezone)->toDateString() ?? 'Unknown';
                $summary['days'][$day] = ($summary['days'][$day] ?? 0) + 1;
                $values = $this->values($row, $columns);
                foreach ($columns as $key => $column) {
                    $answer = array_values(array_filter($values[$key] ?? [], fn ($value) => $value !== null && trim((string) $value) !== ''));
                    if ($answer === []) {
                        continue;
                    }
                    $item = &$summary['questions'][$key];
                    $item['answered']++;
                    if (in_array($column['type'], ['radio', 'select', 'checkbox', 'multiple_choice', 'true_false', 'rating'], true)) {
                        foreach (array_unique($answer) as $value) {
                            if (! in_array($value, $options[$key], true)) {
                                $item['unrecognized']++;

                                continue;
                            }
                            $item['counts'][$value] = ($item['counts'][$value] ?? 0) + 1;
                        }
                    } else {
                        if ($column['type'] === 'number' && count($answer) === 1 && is_numeric($answer[0]) && is_finite((float) $answer[0])) {
                            $this->addNumber($item['numbers'], (float) $answer[0]);
                        }
                        if (count($item['texts']) < 20) {
                            $item['texts'][] = implode(', ', $answer);
                        }
                    }
                    unset($item);
                }
            }
        });
        ksort($summary['days']);
        $summary['days'] = array_slice($summary['days'], -30, null, true);

        return $summary;
    }

    private function addNumber(array &$stats, float $value): void
    {
        $stats['count']++;
        $stats['sum'] += $value;
        $stats['min'] = $stats['min'] === null ? $value : min($stats['min'], $value);
        $stats['max'] = $stats['max'] === null ? $value : max($stats['max'], $value);
    }
}
