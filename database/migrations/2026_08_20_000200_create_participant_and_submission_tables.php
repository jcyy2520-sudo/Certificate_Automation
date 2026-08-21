<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('participants', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->string('full_name')->nullable();
            $table->string('email')->nullable();
            $table->string('organization')->nullable();
            $table->timestampTz('verified_at')->nullable()->index();
            $table->timestampTz('last_access_at')->nullable();
            $table->timestampTz('privacy_erased_at')->nullable()->index();
            $table->timestampsTz();
            $table->softDeletesTz();
            $table->unique(['webinar_id', 'email']);
        });

        Schema::create('participant_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('purpose')->default('email_verification');
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();
        });

        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('attempt_number')->default(1);
            $table->string('status')->default('submitted')->index();
            $table->decimal('score', 10, 2)->nullable();
            $table->decimal('maximum_score', 10, 2)->nullable();
            $table->timestampTz('submitted_at')->nullable()->index();
            $table->timestampTz('answers_erased_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestampsTz();
            $table->unique(['form_id', 'participant_id', 'attempt_number']);
        });

        Schema::create('submission_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_field_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('question_id')->nullable()->constrained()->cascadeOnDelete();
            $table->json('value')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('awarded_points', 8, 2)->nullable();
            $table->timestampsTz();
            $table->unique(['submission_id', 'form_field_id']);
            $table->unique(['submission_id', 'question_id']);
        });

        Schema::create('eligibility_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->string('requirement');
            $table->boolean('is_required')->default(true);
            $table->decimal('minimum_score', 10, 2)->nullable();
            $table->json('settings')->nullable();
            $table->timestampsTz();
            $table->unique(['webinar_id', 'requirement']);
        });

        Schema::create('eligibility_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('participant_id')->constrained()->cascadeOnDelete();
            $table->string('decision');
            $table->text('reason');
            $table->foreignId('overridden_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('expires_at')->nullable();
            $table->timestampsTz();
            $table->index(['participant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('eligibility_overrides');
        Schema::dropIfExists('eligibility_rules');
        Schema::dropIfExists('submission_answers');
        Schema::dropIfExists('submissions');
        Schema::dropIfExists('participant_access_tokens');
        Schema::dropIfExists('participants');
    }
};
