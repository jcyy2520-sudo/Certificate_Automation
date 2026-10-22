<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('email_send_budgets')) {
            Schema::create('email_send_budgets', function (Blueprint $table) {
                $table->date('day')->primary();
                $table->unsignedInteger('reserved')->default(0);
                $table->unsignedInteger('provider_remaining')->nullable();
                $table->timestamp('provider_checked_at')->nullable();
            });
        }
        if (! Schema::hasColumn('email_deliveries', 'budget_reserved_on')) {
            Schema::table('email_deliveries', fn (Blueprint $table) => $table->date('budget_reserved_on')->nullable()->index());
        }
    }

    public function down(): void
    {
        // Preserve quota reservations on rollback to prevent repeat sends.
    }
};
