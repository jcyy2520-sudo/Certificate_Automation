<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Submission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['score' => 'decimal:2', 'maximum_score' => 'decimal:2', 'submitted_at' => 'datetime', 'answers_erased_at' => 'datetime', 'metadata' => 'array'];
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }

    public function answers()
    {
        return $this->hasMany(SubmissionAnswer::class);
    }
}
