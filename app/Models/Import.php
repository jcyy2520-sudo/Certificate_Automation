<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One uploaded Google Form response file.
 *
 * The upload is parsed and discarded; this row is the durable record of what
 * happened. After retention strips the personal data it remains as evidence
 * that the import occurred, in the same way an EmailDelivery survives with its
 * message content removed.
 */
class Import extends Model
{
    /** Lifecycle: an upload is previewed and confirmed before anything is written. */
    public const STATUS_PENDING = 'pending';

    public const STATUS_PREVIEWED = 'previewed';

    public const STATUS_COMMITTED = 'committed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_FAILED = 'failed';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'column_map' => 'array',
            'total_rows' => 'integer',
            'valid_rows' => 'integer',
            'invalid_rows' => 'integer',
            'duplicate_rows' => 'integer',
            'unmatched_rows' => 'integer',
            'file_size' => 'integer',
            'committed_at' => 'datetime',
            'privacy_erased_at' => 'datetime',
        ];
    }

    /** SHA-256 over the raw bytes of an uploaded file. */
    public static function hashContents(string $contents): string
    {
        return hash('sha256', $contents);
    }

    /**
     * Whether this import still holds any personal data.
     *
     * Once retention has run, the counts remain but nothing identifying does.
     */
    public function retainsPersonalData(): bool
    {
        return $this->privacy_erased_at === null;
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function form()
    {
        return $this->belongsTo(Form::class);
    }

    public function importedBy()
    {
        return $this->belongsTo(User::class, 'imported_by');
    }

    public function issues()
    {
        return $this->hasMany(ImportIssue::class);
    }
}
