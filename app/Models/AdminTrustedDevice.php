<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminTrustedDevice extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $hidden = ['token_hash', 'credential_binding', 'browser_hash', 'network_hash'];

    protected function casts(): array
    {
        return array_fill_keys(['verified_at', 'last_used_at', 'expires_at', 'revoked_at'], 'datetime');
    }
}
