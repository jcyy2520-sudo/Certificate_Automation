<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_trusted_devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->char('credential_binding', 64);
            $table->char('browser_hash', 64);
            $table->char('network_hash', 64)->nullable();
            $table->timestamp('verified_at');
            $table->timestamp('last_used_at');
            $table->timestamp('expires_at')->index();
            $table->timestamp('revoked_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_trusted_devices');
    }
};
