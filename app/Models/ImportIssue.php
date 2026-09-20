<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A row that could not be imported cleanly, or that needs a human decision.
 *
 * This is also where someone who completed the tests without ever registering
 * waits for review. Such a person is never rejected automatically and never
 * matched by guessing: an administrator promotes them deliberately, with a
 * reason, or dismisses them.
 */
class ImportIssue extends Model
{
    public const UPDATED_AT = null;

    /** No email at all in the row. */
    public const TYPE_MISSING_EMAIL = 'missing_email';

    /** Present but not a valid address, so it is never guessed at. */
    public const TYPE_INVALID_EMAIL = 'invalid_email';

    /** The same address appears more than once inside one uploaded file. */
    public const TYPE_DUPLICATE_IN_FILE = 'duplicate_in_file';

    /** Already imported for this form by an earlier run. */
    public const TYPE_DUPLICATE_EXISTING = 'duplicate_existing';

    /**
     * Completed a test or the evaluation but is not a registered participant.
     * Held for review rather than discarded: late registrants and people who
     * received the link directly are legitimate attendees.
     */
    public const TYPE_UNMATCHED_EMAIL = 'unmatched_email';

    public const TYPE_INVALID_SCORE = 'invalid_score';

    /** Present but cannot be interpreted without changing its meaning. */
    public const TYPE_INVALID_TIMESTAMP = 'invalid_timestamp';

    /** A CSV record has a different number of columns than its header. */
    public const TYPE_INVALID_ROW_STRUCTURE = 'invalid_row_structure';

    /** A value would otherwise be silently truncated before storage. */
    public const TYPE_VALUE_TOO_LONG = 'value_too_long';

    public const TYPE_MISSING_COLUMN = 'missing_column';

    public const TYPE_EMPTY_ROW = 'empty_row';

    /** A required registration identity field is blank. */
    public const TYPE_MISSING_NAME = 'missing_name';

    protected $guarded = ['id'];

    /**
     * The address is never exposed by accident in a serialized response; the
     * digest beside it is a keyed value that must not leak either.
     *
     * @var list<string>
     */
    protected $hidden = ['normalized_email_hash'];

    protected function casts(): array
    {
        return [
            'normalized_email' => 'encrypted',
            'raw_data' => 'encrypted:array',
            'resolution' => 'encrypted',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
            'row_number' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // Keep the searchable digest in step with the address it describes, so
        // no caller can store one without the other.
        static::saving(function (ImportIssue $issue): void {
            if (! $issue->isDirty('normalized_email')) {
                return;
            }

            $email = $issue->normalized_email;
            $issue->normalized_email_hash = filled($email)
                ? self::emailHash((string) $email)
                : null;
        });
    }

    /**
     * A keyed, context-separated digest of an already-normalized address.
     *
     * Encrypted columns use a random IV, so two rows holding the same address
     * do not share ciphertext and cannot be grouped. This digest is what the
     * unmatched review screen groups by, and what matching compares against.
     * It is keyed so that the small search space of email addresses cannot be
     * walked offline the way a bare hash could.
     */
    public static function emailHash(string $normalizedEmail): string
    {
        return hash_hmac(
            'sha256',
            "import-issue-email\0".Str::lower(trim($normalizedEmail)),
            (string) config('app.key'),
        );
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }

    public function import()
    {
        return $this->belongsTo(Import::class);
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function resolvedBy()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
