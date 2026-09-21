<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The overview screen counts verified, non-erased participants per form,
     * and registration capacity checks filter on exactly this predicate. A
     * partial index keeps those COUNT(*)s bounded as the participant table
     * grows; PostgreSQL does not index foreign-key columns automatically.
     * SQLite (tests) lacks partial-index syntax parity, so it is skipped there.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'CREATE INDEX IF NOT EXISTS participants_webinar_active_verified_index '
            .'ON participants (webinar_id) '
            .'WHERE verified_at IS NOT NULL AND privacy_erased_at IS NULL AND deleted_at IS NULL',
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS participants_webinar_active_verified_index');
    }
};
