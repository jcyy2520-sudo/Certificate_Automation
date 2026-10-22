<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificateApproval extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['eligibility_snapshot' => 'array', 'approved_at' => 'datetime', 'removed_at' => 'datetime', 'manages_identity' => 'boolean'];
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }

    public function administrator()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
