<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ParticipantAccessToken extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }
}
