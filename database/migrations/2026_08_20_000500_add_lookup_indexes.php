<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL does not index a foreign key column automatically, and these are the
 * columns every participant screen filters on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->index('participant_id', 'submissions_participant_id_index');
        });

        Schema::table('certificates', function (Blueprint $table) {
            $table->index('participant_id', 'certificates_participant_id_index');
            $table->index(['webinar_id', 'issued_at'], 'certificates_webinar_issued_index');
        });

        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->index('participant_id', 'email_deliveries_participant_id_index');
        });

        Schema::table('participants', function (Blueprint $table) {
            $table->index(['webinar_id', 'created_at'], 'participants_webinar_created_index');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', fn (Blueprint $t) => $t->dropIndex('submissions_participant_id_index'));
        Schema::table('certificates', function (Blueprint $table) {
            $table->dropIndex('certificates_participant_id_index');
            $table->dropIndex('certificates_webinar_issued_index');
        });
        Schema::table('email_deliveries', fn (Blueprint $t) => $t->dropIndex('email_deliveries_participant_id_index'));
        Schema::table('participants', fn (Blueprint $t) => $t->dropIndex('participants_webinar_created_index'));
    }
};
