<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** A user's reusable column choices, without any uploaded response values. */
class ImportMappingProfile extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'name' => 'encrypted',
            'schema' => 'encrypted:array',
            'preferences' => 'encrypted:array',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
