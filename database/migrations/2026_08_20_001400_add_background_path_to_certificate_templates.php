<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            // Set once an admin uploads their own certificate image instead of
            // using the generated heading/body/accent design. Null means the
            // template still uses the generated design.
            $table->string('background_path')->nullable()->after('template_path');
        });
    }

    public function down(): void
    {
        Schema::table('certificate_templates', function (Blueprint $table) {
            $table->dropColumn('background_path');
        });
    }
};
