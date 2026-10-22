<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('users', 'account_source')) {
            Schema::table('users', function (Blueprint $table) {
                $table->string('account_source', 24)->nullable();
            });
        }
        if (! Schema::hasColumn('certificates', 'requested_by')) {
            Schema::table('certificates', function (Blueprint $table) {
                $table->unsignedBigInteger('requested_by')->nullable()->index();
            });
        }
        if (! Schema::hasColumn('email_deliveries', 'initiated_by')) {
            Schema::table('email_deliveries', function (Blueprint $table) {
                $table->unsignedBigInteger('initiated_by')->nullable()->index();
                $table->string('delivery_outcome', 32)->nullable()->index();
                $table->timestamp('delivery_checked_at')->nullable();
            });
        }
        if (! Schema::hasTable('webinar_user')) {
            Schema::create('webinar_user', function (Blueprint $table) {
                $table->id();
                $table->foreignId('webinar_id')->constrained()->cascadeOnDelete();
                $table->foreignId('user_id')->constrained()->cascadeOnDelete();
                $table->boolean('manage_participants')->default(false);
                $table->boolean('manage_certificates')->default(false);
                $table->boolean('send_emails')->default(false);
                $table->unsignedBigInteger('assigned_by');
                $table->timestamps();
                $table->unique(['webinar_id', 'user_id']);
            });
        }
        if (! Schema::hasColumn('audit_logs', 'actor_user_id')) {
            Schema::table('audit_logs', function (Blueprint $table) {
                // Permanent identifiers have no cascading foreign key. Removing an
                // account from active use cannot erase its historical attribution.
                $table->unsignedBigInteger('actor_user_id')->nullable()->index();
                $table->text('actor_name')->nullable();
                $table->text('actor_email')->nullable();
                $table->unsignedBigInteger('webinar_id')->nullable()->index();
                $table->string('outcome', 16)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Deliberately retain assignments and audit attribution on rollback.
        // Removing these would discard security history and access decisions.
    }
};
