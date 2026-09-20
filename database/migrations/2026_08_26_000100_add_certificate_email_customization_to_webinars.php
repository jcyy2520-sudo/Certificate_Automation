<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Optional organizer overrides for the certificate delivery email.
        // Blank values fall back to the built-in subject and body so existing
        // webinars keep their current behaviour unchanged.
        Schema::table('webinars', function (Blueprint $table) {
            $table->string('certificate_email_subject', 120)->nullable()->after('requires_verification');
            $table->text('certificate_email_message')->nullable()->after('certificate_email_subject');
        });
    }

    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table) {
            $table->dropColumn(['certificate_email_subject', 'certificate_email_message']);
        });
    }
};
