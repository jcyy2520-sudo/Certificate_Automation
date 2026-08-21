<?php

namespace App\Models;

use App\Models\Concerns\HasPublicId;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Certificate extends Model
{
    use HasPublicId;

    protected $guarded = ['id', 'public_id'];

    protected $hidden = ['verification_code_hash', 'issuance_key'];

    protected static function booted(): void
    {
        static::saving(function (Certificate $certificate): void {
            if ($certificate->isDirty('verification_code')) {
                $code = Str::upper($certificate->verification_code);

                $certificate->verification_code = $code;
                $certificate->verification_code_hash = self::verificationCodeHash($code);
            }

            if ($certificate->participant_id === null || $certificate->revoked_at !== null) {
                $certificate->issuance_key = null;
            } elseif (in_array($certificate->status, ['processing', 'issued'], true)) {
                $certificate->issuance_key = $certificate->webinar_id.':'.$certificate->participant_id;
            }
        });
    }

    protected function casts(): array
    {
        return [
            'verification_code' => 'encrypted',
            'recipient_name' => 'encrypted',
            'revocation_reason' => 'encrypted',
            'issued_at' => 'datetime',
            'sent_at' => 'datetime',
            'revoked_at' => 'datetime',
            'privacy_erased_at' => 'datetime',
        ];
    }

    public static function verificationCodeHash(string $code): string
    {
        return hash('sha256', Str::upper($code));
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function participant()
    {
        return $this->belongsTo(Participant::class);
    }

    public function template()
    {
        return $this->belongsTo(CertificateTemplate::class, 'certificate_template_id');
    }

    public function batch()
    {
        return $this->belongsTo(CertificateBatch::class, 'certificate_batch_id');
    }

    public function deliveries()
    {
        return $this->hasMany(EmailDelivery::class);
    }

    public function isPubliclyValid(): bool
    {
        return $this->issued_at !== null
            && $this->revoked_at === null
            && ($this->privacy_erased_at === null
                || config('webinar.public_verification_after_privacy_erasure', false));
    }
}
