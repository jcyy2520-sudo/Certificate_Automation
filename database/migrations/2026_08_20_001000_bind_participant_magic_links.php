<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('participants', function (Blueprint $table): void {
            // Email ownership is distinct from completing the registration form.
            // `verified_at` remains the registration/eligibility signal.
            $table->timestampTz('email_verified_at')->nullable()->index()->after('email');
        });

        Schema::table('participant_access_tokens', function (Blueprint $table): void {
            // A credential issued from one share link must not be replayed against
            // another event or form, even if a caller can obtain both links.
            $table->foreignId('webinar_id')->nullable()->after('participant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->nullable()->after('webinar_id')->constrained()->cascadeOnDelete();
            $table->index(
                ['participant_id', 'purpose', 'expires_at'],
                'participant_access_tokens_cleanup_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('participant_access_tokens', function (Blueprint $table): void {
            $table->dropIndex('participant_access_tokens_cleanup_index');
            $table->dropConstrainedForeignId('form_id');
            $table->dropConstrainedForeignId('webinar_id');
        });

        Schema::table('participants', function (Blueprint $table): void {
            $table->dropColumn('email_verified_at');
        });
    }
};
