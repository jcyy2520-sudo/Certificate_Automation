<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('participants', 'status_access_version')) {
            Schema::table('participants', fn (Blueprint $table) => $table->unsignedInteger('status_access_version')->default(0));
        }
        if (! Schema::hasTable('eligibility_reviews')) {
            Schema::create('eligibility_reviews', function (Blueprint $table) {
                $table->id();
                $table->ulid('public_id')->unique();
                $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
                $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
                $table->uuid('submission_key')->unique();
                $table->string('open_key', 100)->nullable()->unique();
                $table->string('status', 20)->default('pending');
                $table->text('explanation')->nullable();
                $table->text('decision_reason')->nullable();
                $table->char('evidence_fingerprint', 64);
                $table->unsignedBigInteger('decided_by')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->string('correction_type', 24)->nullable();
                $table->unsignedBigInteger('import_issue_id')->nullable();
                $table->string('storage_disk', 100)->nullable();
                $table->string('file_path')->nullable();
                $table->string('extension', 5)->nullable();
                $table->string('evidence_status', 16)->nullable();
                $table->unsignedInteger('file_size')->nullable();
                $table->timestamps();
                $table->index(['webinar_id', 'status']);
                $table->index(['participant_id', 'id']);
            });
        }
        if (! Schema::hasTable('eligibility_decisions')) {
            Schema::create('eligibility_decisions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
                $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
                $table->char('evidence_fingerprint', 64);
                $table->text('reason')->nullable();
                $table->unsignedBigInteger('finalized_by');
                $table->timestamps();
                $table->index(['participant_id', 'id']);
            });
        }
    }

    public function down(): void
    {
        // Retain review evidence, final decisions and link revocations on rollback.
    }
};
