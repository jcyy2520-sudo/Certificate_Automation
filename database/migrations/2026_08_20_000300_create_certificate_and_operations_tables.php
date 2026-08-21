<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('certificate_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('storage_disk')->default('s3');
            $table->string('template_path');
            $table->unsignedInteger('canvas_width')->nullable();
            $table->unsignedInteger('canvas_height')->nullable();
            $table->json('layout');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
        });

        Schema::create('certificate_batches', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('certificate_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('completed_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestampTz('completed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('certificates', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('verification_code', 40)->unique();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('certificate_template_id')->constrained()->restrictOnDelete();
            $table->foreignId('certificate_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('recipient_name')->nullable();
            $table->string('storage_disk')->default('s3');
            $table->string('file_path')->nullable();
            $table->string('status')->default('pending')->index();
            $table->timestampTz('issued_at')->nullable()->index();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->text('revocation_reason')->nullable();
            $table->timestampTz('privacy_erased_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('email_deliveries', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webinar_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('participant_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('certificate_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type');
            $table->string('provider')->default('brevo');
            $table->string('recipient_email')->nullable();
            $table->string('subject');
            $table->json('payload')->nullable();
            $table->string('status')->default('pending')->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(3);
            $table->string('provider_message_id')->nullable()->index();
            $table->text('last_error')->nullable();
            $table->timestampTz('scheduled_at')->nullable()->index();
            $table->timestampTz('processing_at')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('failed_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('action')->index();
            $table->nullableMorphs('auditable');
            $table->string('ip_address_hash', 64)->nullable();
            $table->text('user_agent')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampTz('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('email_deliveries');
        Schema::dropIfExists('certificates');
        Schema::dropIfExists('certificate_batches');
        Schema::dropIfExists('certificate_templates');
    }
};
