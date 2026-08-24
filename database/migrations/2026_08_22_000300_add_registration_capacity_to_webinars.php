<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->unsignedInteger('registration_capacity')->nullable()->after('registration_closes_at');
        });
    }

    public function down(): void
    {
        Schema::table('webinars', function (Blueprint $table): void {
            $table->dropColumn('registration_capacity');
        });
    }
};
