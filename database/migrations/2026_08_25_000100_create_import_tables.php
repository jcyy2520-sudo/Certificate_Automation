<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One uploaded response file. The file itself is never retained: it is
        // parsed, its rows are turned into participants and submissions, and
        // only problem rows keep a copy of their original data. Retaining the
        // upload would create a second, ungoverned copy of every attendee's
        // personal details alongside the one the application already manages.
        Schema::create('imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            // The target form decides which requirement this file satisfies.
            // Storing a separate form_type string would be a second source of
            // truth that can disagree with forms.type.
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('pending');
            // Supplied by the uploader, so it is untrusted text. Displayed
            // escaped and never used to build a filesystem path.
            $table->string('original_filename', 255);
            // SHA-256 over the raw bytes. Deliberately NOT unique: a repeat
            // import must remain possible after a cancelled or rolled back run.
            $table->string('file_hash', 64);
            $table->unsignedBigInteger('file_size');
            $table->json('column_map')->nullable();
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('valid_rows')->default(0);
            $table->unsignedInteger('invalid_rows')->default(0);
            $table->unsignedInteger('duplicate_rows')->default(0);
            $table->unsignedInteger('unmatched_rows')->default(0);
            // An administrator who owns import history cannot be deleted out
            // from under it, matching eligibility_overrides.overridden_by.
            $table->foreignId('imported_by')->constrained('users')->restrictOnDelete();
            $table->timestampTz('committed_at')->nullable();
            // Set when retention strips this row's personal data. The counts and
            // timestamps survive as evidence that the import happened.
            $table->timestampTz('privacy_erased_at')->nullable();
            $table->timestampsTz();

            $table->index(['webinar_id', 'form_id', 'created_at']);
            // Warns the administrator that this exact file was already uploaded.
            $table->index(['webinar_id', 'file_hash']);
        });

        // One row that could not be imported cleanly, or that imported but needs
        // a human decision. This is also where an unregistered participant who
        // completed the tests waits for review.
        Schema::create('import_issues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            // Reachable through import_id, but denormalized so the retention
            // sweep can purge by webinar without a join. That sweep is the one
            // thing that must never silently miss a row.
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->string('issue_type');
            $table->string('issue_message', 500);
            // Encrypted at rest like every other stored address. Laravel's
            // encrypted cast uses a random IV, so the ciphertext cannot be
            // compared or grouped; the keyed digest beside it exists for that.
            $table->text('normalized_email')->nullable();
            $table->string('normalized_email_hash', 64)->nullable();
            $table->json('raw_data')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestampTz('created_at')->nullable();

            // Drives the unmatched review screen.
            $table->index(['webinar_id', 'issue_type', 'resolved_at']);
            // Groups one person's rows across all four imported files.
            $table->index(['webinar_id', 'normalized_email_hash']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_issues');
        Schema::dropIfExists('imports');
    }
};
