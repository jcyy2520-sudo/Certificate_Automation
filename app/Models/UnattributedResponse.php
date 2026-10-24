<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/** A literal source row, deliberately unrelated to any participant or submission. */
class UnattributedResponse extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['original_data' => 'encrypted:array', 'row_number' => 'integer'];
    }

    /** Reconstruct originals explicitly; source labels belong to the known import once. */
    public function originalCells(Import $import): array
    {
        $headers = $import->column_map['headers'] ?? [];
        $values = $this->original_data;
        if ((int) $import->id !== (int) $this->import_id || (int) $import->webinar_id !== (int) $this->webinar_id
            || (int) $import->form_id !== (int) $this->form_id || $import->privacy_erased_at !== null
            || ! is_array($values) || ! array_is_list($values) || count($values) !== count($headers)) {
            throw new RuntimeException('The original evaluation row no longer matches its import schema.');
        }
        $original = [];
        foreach ($headers as $index => $label) {
            if (! is_string($label) || ! is_string($values[$index])) {
                throw new RuntimeException('The original evaluation row has an unsupported stored cell format.');
            }
            $original[] = ['label' => $label, 'value' => $values[$index]];
        }

        return $original;
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function import()
    {
        return $this->belongsTo(Import::class);
    }
}
