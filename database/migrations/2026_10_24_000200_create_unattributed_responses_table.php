<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('unattributed_responses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
            $table->foreignId('form_id')->constrained()->cascadeOnDelete();
            $table->foreignId('import_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('row_number');
            $table->longText('original_data');
            $table->timestamps();
            $table->unique(['import_id', 'row_number']);
            $table->index(['webinar_id', 'form_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unattributed_responses');
    }
};
