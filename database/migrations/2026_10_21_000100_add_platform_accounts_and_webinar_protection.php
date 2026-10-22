<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Do not guess the owner of an orphaned historical webinar.
        $orphans = DB::table('webinars')->where(fn ($q) => $q->whereNull('created_by')
            ->orWhereNotIn('created_by', DB::table('users')->select('id')))->exists();
        if ($orphans) {
            throw new RuntimeException('Assign historical webinars to an explicitly verified owner before this migration.');
        }
        // The original application removed the unused broker table.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::table('users', function (Blueprint $table) {
            $table->string('approval_status', 32)->default('pending_verification')->index();
            $table->timestamp('password_setup_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->unsignedInteger('auth_version')->default(1);
            $table->string('verification_token_hash', 64)->nullable();
            $table->timestamp('verification_expires_at')->nullable();
        });
        DB::table('users')->where('role', 'administrator')->update([
            'approval_status' => 'approved', 'password_setup_at' => now(), 'approved_at' => now(),
        ]);
        DB::table('users')->where('role', 'administrator')->where('is_active', false)->update(['approval_status' => 'suspended']);
        // Existing administrator verification is preserved; no ordinary record is promoted.
        Schema::table('webinars', function (Blueprint $table) {
            $table->string('management_password')->nullable();
            $table->uuid('management_lock_version')->nullable();
            $table->index(['created_by', 'status']);
        });
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->foreignId('account_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('first_attempted_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::table('email_deliveries', fn (Blueprint $table) => $table->dropConstrainedForeignId('account_user_id'));
        Schema::table('email_deliveries', fn (Blueprint $table) => $table->dropColumn('first_attempted_at'));
        Schema::table('webinars', function (Blueprint $table) {
            $table->dropIndex(['created_by', 'status']);
            $table->dropColumn(['management_password', 'management_lock_version']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['approval_status']);
            $table->dropColumn([
                'approval_status', 'password_setup_at', 'approved_at', 'auth_version',
                'verification_token_hash', 'verification_expires_at',
            ]);
        });
    }
};
