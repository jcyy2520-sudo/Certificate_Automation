<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_approvals', function (Blueprint $table): void {
            $table->string('source', 20)->default('eligible');
            $table->boolean('manages_identity')->default(false);
            $table->timestamp('removed_at')->nullable();
        });
    }

    public function down(): void
    {
        // Keep exception provenance on rollback. Removing it could silently
        // reinterpret manual authorizations as ordinary eligibility approvals.
    }
};
