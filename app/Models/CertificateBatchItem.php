<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;

class CertificateBatchItem extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    public function batch()
    {
        return $this->belongsTo(CertificateBatch::class, 'certificate_batch_id');
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }

    public function certificate()
    {
        return $this->belongsTo(Certificate::class);
    }
}
