<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubmissionAnswer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['value' => 'encrypted:array', 'is_correct' => 'boolean', 'awarded_points' => 'decimal:2'];
    }

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function formField()
    {
        return $this->belongsTo(FormField::class);
    }

    public function question()
    {
        return $this->belongsTo(Question::class);
    }
}
