<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Deployment changes the secure default to session-only authentication.
        // Invalidate any recaller cookies issued under the earlier policy.
        DB::table('users')->whereNotNull('remember_token')->update(['remember_token' => null]);
    }

    public function down(): void
    {
        // Invalidated authentication secrets cannot and should not be restored.
    }
};
