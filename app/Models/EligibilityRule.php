<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EligibilityRule extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_required' => 'boolean', 'minimum_score' => 'decimal:2', 'settings' => 'array'];
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }
}
