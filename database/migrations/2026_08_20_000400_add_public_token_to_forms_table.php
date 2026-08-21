<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            // The unguessable component of a form's shareable link.
            $table->string('public_token', 32)->nullable()->unique()->after('type');
            $table->timestampTz('link_rotated_at')->nullable()->after('public_token');
        });

        DB::table('forms')->whereNull('public_token')->orderBy('id')->each(function ($form): void {
            DB::table('forms')->where('id', $form->id)->update(['public_token' => Str::lower(Str::random(24))]);
        });
    }

    public function down(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn(['public_token', 'link_rotated_at']);
        });
    }
};
