<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificateTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['layout' => 'array', 'is_active' => 'boolean'];
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function batches()
    {
        return $this->hasMany(CertificateBatch::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }
}
