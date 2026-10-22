<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class EligibilityReview extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['submission_key', 'open_key', 'file_path', 'evidence_fingerprint'];

    protected function casts(): array
    {
        return ['explanation' => 'encrypted', 'decision_reason' => 'encrypted', 'decided_at' => 'datetime'];
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }
}
