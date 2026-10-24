<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Webinar extends Model
{
    public function submissions()
    {
        return $this->hasManyThrough(Submission::class, Form::class);
    }

    public function unattributedResponses()
    {
        return $this->hasMany(UnattributedResponse::class);
    }

    use HasFactory;

    protected $guarded = ['id', 'retention_due_at', 'deletion_started_at', 'admin_locked_at', 'admin_locked_by'];

    protected $hidden = ['management_password', 'management_lock_version'];

    public function scopeVisibleTo($query, User $user)
    {
        return $query->when(! $user->isAdministrator(), fn ($q) => $q->where(function ($q) use ($user) {
            // Preserve the existing regular-user ownership workflow. Managers
            // and invited regular users receive only explicit assignments.
            $q->whereIn('id', $user->assignedWebinars()->select('webinars.id'));
            if ($user->role === User::USER_ROLE && $user->account_source !== 'admin_invitation') {
                $q->orWhere('created_by', $user->id);
            }
        }));
    }

    public function scopeManageableBy($query, User $user)
    {
        return $query->visibleTo($user)
            ->when(! $user->isAdministrator(), fn ($q) => $q->whereNull('admin_locked_at'));
    }

    public function isAdministratorLocked(): bool
    {
        return $this->admin_locked_at !== null;
    }

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
        return max(1, min(3650, (int) config('webinar.default_retention_days', 30)));
    }

    /** Whether public submissions must prove ownership of their email address. */
    public function requiresVerification(): bool
    {
        // Missing/null values fail closed for partially hydrated or legacy rows.
        return $this->requires_verification !== false;
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime', 'ends_at' => 'datetime',
            'registration_opens_at' => 'datetime', 'registration_closes_at' => 'datetime',
            'retention_due_at' => 'datetime', 'deletion_started_at' => 'datetime',
            'archived_at' => 'datetime', 'requires_verification' => 'boolean', 'settings' => 'array',
            'admin_locked_at' => 'datetime', 'admin_locked_by' => 'integer',
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

    /** The workspace navigation needs the authoritative organizer-facing state. */
    public function isOpen(): bool
    {
        return $this->status === 'published'
            && $this->archived_at === null
            && $this->deletion_started_at === null;
    }

    public function availabilityLabel(): string
    {
        return $this->archived_at !== null || $this->status === 'archived'
            ? 'Archived'
            : ($this->isOpen() ? 'Open' : 'Closed');
    }

    public const DEFAULT_CERTIFICATE_SUBJECT = '{{certificate_type}} — {{webinar_title}}';

    public const DEFAULT_CERTIFICATE_MESSAGE = "Dear {{participant_name}},\n\nThank you for participating in our webinar, \"{{webinar_title}}\". We appreciate your time and commitment to learning.\n\nPlease find your {{certificate_type}} attached in recognition of your participation.\n\nWe look forward to welcoming you to future activities.\n\nBest regards,\nWebinar Organizing Team";

    /** Resolve organizer copy as plain text; legacy placeholders remain supported. */
    public function certificateEmailSubject(?string $participantName = null): string
    {
        $subject = trim((string) ($this->certificate_email_subject ?? ''));

        return preg_replace('/[\r\n]+/', ' ', $this->replaceCertificateEmailPlaceholders(
            $subject !== '' ? $subject : self::DEFAULT_CERTIFICATE_SUBJECT,
            $participantName,
        )) ?? '';
    }

    public function certificateEmailMessage(?string $participantName = null): string
    {
        $message = trim((string) ($this->certificate_email_message ?? ''));

        return $this->replaceCertificateEmailPlaceholders($message !== '' ? $message : self::DEFAULT_CERTIFICATE_MESSAGE, $participantName);
    }

    public function certificateType(): string
    {
        $template = $this->certificateTemplates()->where('is_active', true)->first();

        return $template?->certificateType() ?? 'Certificate of Participation';
    }

    private function replaceCertificateEmailPlaceholders(string $copy, ?string $participantName): string
    {
        return strtr($copy, [
            '{{participant_name}}' => trim((string) $participantName), '{{webinar_title}}' => (string) $this->title,
            '{{certificate_type}}' => $this->certificateType(),
            '{participant}' => trim((string) $participantName), '{webinar}' => (string) $this->title,
        ]);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function forms()
    {
        return $this->hasMany(Form::class);
    }

    public function isUploadOnly(): bool
    {
        return $this->creation_mode === 'uploads';
    }

    public function documents()
    {
        return $this->hasMany(WebinarDocument::class);
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

    /** Import routes use scoped binding so an upload can never cross webinars. */
    public function imports()
    {
        return $this->hasMany(Import::class);
    }

    public function importIssues()
    {
        return $this->hasMany(ImportIssue::class);
    }

    public function emailDeliveries()
    {
        return $this->hasMany(EmailDelivery::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }
}
