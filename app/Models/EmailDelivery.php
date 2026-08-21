<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class EmailDelivery extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['idempotency_key'];

    protected static function booted(): void
    {
        static::creating(function (EmailDelivery $delivery): void {
            $delivery->idempotency_key ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return [
            'recipient_email' => 'encrypted', 'subject' => 'encrypted',
            'payload' => 'encrypted:array', 'last_error' => 'encrypted',
            'scheduled_at' => 'datetime', 'processing_at' => 'datetime',
            'expires_at' => 'datetime', 'sent_at' => 'datetime', 'failed_at' => 'datetime',
        ];
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
