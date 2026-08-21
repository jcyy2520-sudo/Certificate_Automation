<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Historical entries used reversible IP hashes, raw user-agent strings,
        // and unrestricted metadata. They cannot be transformed safely because
        // the original values may contain personal data, so minimize them once.
        DB::table('audit_logs')->update([
            'ip_address_hash' => null,
            'user_agent' => null,
            'metadata' => null,
        ]);
    }

    public function down(): void
    {
        // Privacy scrubbing is intentionally irreversible.
    }
};
