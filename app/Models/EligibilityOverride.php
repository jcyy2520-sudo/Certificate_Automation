<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class EligibilityOverride extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['reason' => 'encrypted', 'expires_at' => 'datetime'];
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }

    public function administrator()
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
