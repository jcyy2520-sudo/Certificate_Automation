<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Remove schema nothing reads or writes (SYSTEM_AUDIT P-7/F-5): certificate
     * templates carry their real geometry and artwork path inside the layout
     * JSON and background_path, and the four speculative JSON columns were
     * never populated by any code path. The stale verification_code unique
     * index from P-5 no longer exists — migration 000700 already dropped it.
     */
    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropColumn(['template_path', 'canvas_width', 'canvas_height']);
        });

        Schema::table('webinars', function (Blueprint $table) {
            $table->dropColumn('settings');
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn('settings');
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->dropColumn('metadata');
        });

        Schema::dropIfExists('password_reset_tokens');
    }

    public function down(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::table('questions', function (Blueprint $table) {
            $table->json('metadata')->nullable();
        });

        Schema::table('form_fields', function (Blueprint $table) {
            $table->json('metadata')->nullable();
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->json('settings')->nullable();
        });

        Schema::table('webinars', function (Blueprint $table) {
            $table->json('settings')->nullable();
        });

        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->string('template_path')->default('');
            $table->unsignedInteger('canvas_width')->nullable();
            $table->unsignedInteger('canvas_height')->nullable();
        });
    }
};
