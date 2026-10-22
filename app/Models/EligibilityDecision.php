<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EligibilityDecision extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted'];
    }
}
