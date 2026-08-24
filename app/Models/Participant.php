<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Participant extends Model
{
    use HasFactory, HasPublicId, SoftDeletes;

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['email_normalized'];

    protected static function booted(): void
    {
        static::saving(function (Participant $participant): void {
            if ($participant->isDirty('email')) {
                $email = filled($participant->email)
                    ? Str::lower(trim((string) $participant->email))
                    : null;
                $participant->email = $email;
                $participant->email_normalized = $email;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'verified_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'last_access_at' => 'datetime',
            'privacy_erased_at' => 'datetime',
        ];
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function accessTokens()
    {
        return $this->hasMany(ParticipantAccessToken::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function eligibilityOverrides()
    {
        return $this->hasMany(EligibilityOverride::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }
}
