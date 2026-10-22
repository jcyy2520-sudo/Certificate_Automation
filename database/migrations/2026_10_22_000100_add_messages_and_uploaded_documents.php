<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            $table->string('creation_mode', 20)->default('standard');
        });
        Schema::create('webinar_documents', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->string('title', 180);
            $table->string('storage_disk');
            $table->string('file_path');
            $table->string('extension', 10);
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('version')->default(1);
            $table->softDeletesTz();
            $table->timestampsTz();
        });
        Schema::create('participant_message_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->uuid('submission_key')->unique();
            $table->string('request_digest', 64);
            $table->unsignedInteger('recipient_count');
            $table->timestampsTz();
        });
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->foreignId('participant_message_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('retry_of_delivery_id')->nullable()->unique()->constrained('email_deliveries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('participant_message_batch_id');
            $table->dropConstrainedForeignId('retry_of_delivery_id');
        });
        Schema::dropIfExists('participant_message_batches');
        Schema::dropIfExists('webinar_documents');
        Schema::table('webinars', fn (Blueprint $table) => $table->dropColumn('creation_mode'));
    }
};
