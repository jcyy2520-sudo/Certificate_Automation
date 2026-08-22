<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Webinar extends Model
{
    use HasFactory;

    protected $guarded = ['id', 'retention_due_at', 'deletion_started_at'];

    protected static function booted(): void
    {
        static::creating(function (Webinar $webinar): void {
            if (blank($webinar->data_retention_days)) {
                $webinar->data_retention_days = self::defaultRetentionDays();
            }
        });

        static::saving(function (Webinar $webinar): void {
            $webinar->preservePrivacyLifecycle();
        });
    }

    public static function defaultRetentionDays(): int
    {
        return max(1, min(3650, (int) config('webinar.default_retention_days', 7)));
    }

    /** Whether public submissions must prove ownership of their email address. */
    public function requiresVerification(): bool
    {
        // Missing/null values fail closed for partially hydrated or legacy rows.
        return $this->requires_verification !== false;
    }

    /**
     * Every IANA zone the timezone field will accept, each with its current
     * UTC offset so the picker can be searched and read without memorizing
     * zone names.
     *
     * @return array<int, array{id: string, label: string}>
     */
    public static function timezoneOptions(): array
    {
        return collect(\DateTimeZone::listIdentifiers())
            ->map(fn (string $id): array => [
                'id' => $id,
                'label' => self::timezoneLabel($id),
                'offset' => (new \DateTimeZone($id))->getOffset(new \DateTime('now')),
            ])
            ->sortBy(['offset', 'id'])
            ->values()
            ->all();
    }

    /** A short "UTC-04:00 · EDT" label for a stored IANA zone, or null if it isn't one. */
    public static function timezoneLabel(?string $timezone): ?string
    {
        if (blank($timezone)) {
            return null;
        }

        try {
            $zone = new \DateTimeZone($timezone);
        } catch (\Exception) {
            return null;
        }

        $now = (new \DateTime('now'))->setTimezone($zone);

        return 'UTC'.$now->format('P').' · '.$now->format('T');
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'registration_opens_at' => 'datetime', 'registration_closes_at' => 'datetime',
            'retention_due_at' => 'datetime', 'deletion_started_at' => 'datetime',
            'archived_at' => 'datetime', 'requires_verification' => 'boolean', 'settings' => 'array',
        ];
    }

    /**
     * Retention may only stay fixed or become shorter after it is committed.
     * Direct assignment cannot clear or extend it, and the deletion tombstone
     * is similarly one-way. Query-builder updates remain reserved for migrations.
     */
    private function preservePrivacyLifecycle(): void
    {
        $originalDeadline = $this->exists
            ? $this->asImmutableDate($this->getRawOriginal('retention_due_at'))
            : null;
        $originalDeletionStartedAt = $this->exists
            ? $this->getRawOriginal('deletion_started_at')
            : null;

        if ($originalDeletionStartedAt !== null) {
            $this->setAttribute('deletion_started_at', $originalDeletionStartedAt);
        }

        if ($originalDeadline !== null) {
            $candidate = $this->scheduleChanged()
                ? $this->deadlineFromCurrentSchedule()
                : null;

            $this->setAttribute(
                'retention_due_at',
                $candidate !== null && $candidate->lessThan($originalDeadline)
                    ? $candidate
                    : $originalDeadline,
            );

            return;
        }

        if ($this->commitsRetentionSchedule()) {
            $this->setAttribute('retention_due_at', $this->deadlineFromCurrentSchedule());
        }
    }

    private function commitsRetentionSchedule(): bool
    {
        return in_array((string) $this->status, ['published', 'completed', 'archived'], true);
    }

    private function scheduleChanged(): bool
    {
        return $this->isDirty('ends_at') || $this->isDirty('data_retention_days');
    }

    private function deadlineFromCurrentSchedule(): ?CarbonImmutable
    {
        if ($this->ends_at === null) {
            return null;
        }

        return CarbonImmutable::instance($this->ends_at)
            ->addDays(max(1, (int) $this->data_retention_days));
    }

    private function asImmutableDate(mixed $value): ?CarbonImmutable
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function forms()
    {
        return $this->hasMany(Form::class);
    }

    public function participants()
    {
        return $this->hasMany(Participant::class);
    }

    public function eligibilityRules()
    {
        return $this->hasMany(EligibilityRule::class);
    }

    public function certificateTemplates()
    {
        return $this->hasMany(CertificateTemplate::class);
    }

    public function certificateBatches()
    {
        return $this->hasMany(CertificateBatch::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }
}
