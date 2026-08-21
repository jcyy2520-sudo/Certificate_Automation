<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Form extends Model
{
    use HasFactory;

    protected $guarded = ['id'];

    protected $hidden = ['public_token', 'public_token_hash'];

    protected static function booted(): void
    {
        static::saving(function (Form $form): void {
            if (! $form->exists || $form->isDirty('public_token')) {
                $token = Str::lower($form->public_token ?: self::newPublicToken());

                $form->public_token = $token;
                $form->public_token_hash = self::publicTokenHash($token);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'public_token' => 'encrypted',
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'show_score' => 'boolean',
            'settings' => 'array',
            'link_rotated_at' => 'datetime',
        ];
    }

    public static function newPublicToken(): string
    {
        return Str::lower(Str::random(24));
    }

    public static function publicTokenHash(string $token): string
    {
        return hash('sha256', Str::lower($token));
    }

    /** The public link shared with participants. Opens this form and nothing else. */
    public function shareUrl(): string
    {
        return route('forms.public', $this->public_token);
    }

    /** Whether the form currently accepts responses. */
    public function acceptsResponses(): bool
    {
        if ($this->status !== 'published'
            || ! $this->webinar
            || $this->webinar->status !== 'published'
            || $this->webinar->archived_at !== null
            || $this->webinar->deletion_started_at !== null
            || $this->webinar->retention_due_at === null
            || $this->webinar->retention_due_at?->isPast()) {
            return false;
        }

        if ($this->type === 'registration'
            && (($this->webinar->registration_opens_at && $this->webinar->registration_opens_at->isFuture())
                || ($this->webinar->registration_closes_at && $this->webinar->registration_closes_at->isPast()))) {
            return false;
        }

        return ! ($this->opens_at && $this->opens_at->isFuture())
            && ! ($this->closes_at && $this->closes_at->isPast());
    }

    /** Why the form is not accepting responses, for the closed-form notice. */
    public function closedReason(): string
    {
        return match (true) {
            $this->webinar && $this->webinar->retention_due_at === null => 'This event has no committed privacy retention deadline.',
            $this->webinar?->retention_due_at?->isPast() => 'This event has reached the end of its privacy retention period.',
            $this->type === 'registration' && $this->webinar?->registration_opens_at?->isFuture() => 'Registration is not open yet.',
            $this->type === 'registration' && $this->webinar?->registration_closes_at?->isPast() => 'Registration is closed.',
            $this->opens_at && $this->opens_at->isFuture() => 'This form is not open yet.',
            $this->closes_at && $this->closes_at->isPast() => 'This form is no longer accepting responses.',
            default => 'This form is not accepting responses.',
        };
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function fields()
    {
        return $this->hasMany(FormField::class)->orderBy('sort_order');
    }

    public function questions()
    {
        return $this->hasMany(Question::class)->orderBy('sort_order');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }
}
