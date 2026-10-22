<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('Audit records cannot be edited.'));
        static::deleting(fn () => throw new \LogicException('Audit records are removed only by the configured retention process.'));
    }

    protected function casts(): array
    {
        return ['metadata' => 'array', 'created_at' => 'datetime', 'actor_name' => 'encrypted', 'actor_email' => 'encrypted'];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function auditable()
    {
        return $this->morphTo();
    }
}
