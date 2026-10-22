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

        // At most one sendable certificate delivery may exist at a time. This
        // is deliberately a database-backed claim rather than a time-based UI
        // guard: two browser requests or two workers must not be able to send
        // the same PDF twice merely because they raced each other.
        static::saving(function (EmailDelivery $delivery): void {
            $delivery->active_certificate_delivery_key = $delivery->certificate_id !== null
                && $delivery->type === 'certificate'
                && in_array($delivery->status, ['pending', 'processing'], true)
                ? 'certificate:'.$delivery->certificate_id
                : null;
        });
    }

    protected function casts(): array
    {
        return [
            'recipient_email' => 'encrypted',
            'subject' => 'encrypted',
            'payload' => 'encrypted:array',
            'last_error' => 'encrypted',
            'scheduled_at' => 'datetime',
            'processing_at' => 'datetime',
            'expires_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'first_attempted_at' => 'datetime',
            'delivery_checked_at' => 'datetime',
        ];
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function messageBatch()
    {
        return $this->belongsTo(ParticipantMessageBatch::class, 'participant_message_batch_id');
    }

    public function account()
    {
        return $this->belongsTo(User::class, 'account_user_id');
    }

    public function statusLabel(): string
    {
        if ($this->status === 'sent' && $this->provider === 'brevo' && $this->delivery_outcome) {
            return match ($this->delivery_outcome) {
                'delivered' => 'Delivered to mail server',
                'hard_bounce', 'blocked', 'invalid', 'error' => 'Delivery failed',
                'soft_bounce', 'deferred' => 'Delivery delayed',
                default => 'Provider accepted',
            };
        }

        return match ($this->status) {
            'sent' => $this->provider === 'log' ? 'Captured locally' : 'Provider accepted',
            'processing' => 'Sending attempt',
            'pending' => (int) $this->attempts > 0 ? 'Retry pending' : ($this->scheduled_at?->isFuture() ? 'Queued for later' : 'Queued'),
            default => ucfirst($this->status),
        };
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
