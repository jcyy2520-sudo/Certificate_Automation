<?php

namespace App\Services;

use App\Models\Form;
use App\Models\Import;

/** Schema belongs to an import and its destination form, never to physical columns. */
final class ImportedResponseSchema
{
    public static function headerKey(string $label): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($label)) ?? $label);
    }

    public static function extraKey(string $label): string
    {
        return 'imported_'.hash('sha256', self::headerKey($label));
    }

    /** Only unique exact labels are suggested; similar wording is never a match. */
    public function suggestAnswers(Form $form, array $headers, array $identityMap): array
    {
        $columns = app(FormResponseService::class)->nativeColumns($form);
        $labels = array_count_values(array_map(fn ($column) => self::headerKey($column['label']), $columns));
        $answerMap = [];
        foreach ($columns as $key => $column) {
            $label = self::headerKey($column['label']);
            $matches = array_keys(array_filter($headers, fn ($header) => self::headerKey($header) === $label));
            if ($labels[$label] === 1 && count($matches) === 1 && ! in_array($matches[0], $identityMap, true)) {
                $answerMap[$key] = $matches[0];
            }
        }

        return $answerMap;
    }

    public function columns(Import $import, array $map, array $options): array
    {
        $native = app(FormResponseService::class)->nativeColumns($import->form);
        $answers = array_flip($options['answer_map'] ?? []);
        $identifiers = array_diff_key($map, ['organization' => true]);
        $mapping = $import->column_map;
        $schema = [];
        foreach ($mapping['headers'] ?? [] as $index => $label) {
            if (in_array($index, $identifiers, true)) {
                continue;
            }
            $key = $answers[$index] ?? self::extraKey($label);
            $schema[$key] = isset($answers[$index], $native[$key]) ? $native[$key] : [
                'label' => $label, 'type' => 'text', 'options' => [], 'required' => false,
                'detected_type' => $mapping['analysis']['columns'][$index]['type'] ?? 'text',
                'imported' => true,
            ];
            $schema[$key]['source_index'] = $index;
            $schema[$key]['source_label'] = $label;
        }

        return $schema;
    }

    /** Data types describe syntax only. They never establish scores, ratings or completion. */
    public function analyze(array $headers, iterable $rows): array
    {
        $columns = array_map(fn ($label) => ['label' => $label, 'empty' => 0, 'filled' => 0, 'types' => []], $headers);
        $count = 0;
        $irregular = 0;
        foreach ($rows as $row) {
            $count++;
            $irregular += count($row) !== count($headers) ? 1 : 0;
            foreach ($columns as $index => &$column) {
                $value = trim((string) ($row[$index] ?? ''));
                if ($value === '') {
                    $column['empty']++;

                    continue;
                }
                $column['filled']++;
                $type = preg_match('/^-?\d+(?:\.\d+)?$/D', $value) ? 'number'
                    : (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $value) ? 'date'
                        : (preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}/', $value) ? 'timestamp' : 'text'));
                $column['types'][$type] = true;
            }
            unset($column);
        }
        foreach ($columns as &$column) {
            $column['type'] = count($column['types']) === 1 ? array_key_first($column['types']) : 'text';
            $column['mixed'] = count($column['types']) > 1;
            unset($column['types']);
        }

        return ['rows' => $count, 'column_count' => count($headers), 'irregular_rows' => $irregular, 'columns' => $columns];
    }
}
