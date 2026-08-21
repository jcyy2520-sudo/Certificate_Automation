<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Question extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['points' => 'decimal:2', 'is_required' => 'boolean', 'metadata' => 'array'];
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function choices()
    {
        return $this->hasMany(QuestionChoice::class)->orderBy('sort_order');
    }

    public function answers()
    {
        return $this->hasMany(SubmissionAnswer::class);
    }
}
