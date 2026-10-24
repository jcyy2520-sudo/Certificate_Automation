<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_mapping_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('form_type', 20);
            // Schema labels and user-provided names may contain personal text.
            // Encrypt these just like the existing response-schema payloads.
            $table->text('name');
            $table->text('schema');
            $table->text('preferences');
            $table->timestampsTz();
            $table->index(['user_id', 'form_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_mapping_profiles');
    }
};
