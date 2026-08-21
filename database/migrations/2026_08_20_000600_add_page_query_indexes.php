<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificates', function (Blueprint $table) {
            $table->index(
                ['participant_id', 'revoked_at', 'issued_at'],
                'certificates_participant_validity_index',
            );
        });

        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->index(
                ['webinar_id', 'is_active'],
                'certificate_templates_webinar_active_index',
            );
        });

        Schema::table('certificate_batches', function (Blueprint $table) {
            $table->index(
                ['webinar_id', 'id'],
                'certificate_batches_webinar_recent_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('certificates', fn (Blueprint $table) => $table
            ->dropIndex('certificates_participant_validity_index'));
        Schema::table('certificate_templates', fn (Blueprint $table) => $table
            ->dropIndex('certificate_templates_webinar_active_index'));
        Schema::table('certificate_batches', fn (Blueprint $table) => $table
            ->dropIndex('certificate_batches_webinar_recent_index'));
    }
};
