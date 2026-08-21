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
        if (! Schema::hasColumn('email_deliveries', 'expires_at')) {
            Schema::table('email_deliveries', function (Blueprint $table): void {
                $table->timestampTz('expires_at')->nullable()->index()->after('scheduled_at');
            });
        }

        if (! Schema::hasColumn('email_deliveries', 'idempotency_key')) {
            Schema::table('email_deliveries', function (Blueprint $table): void {
                $table->uuid('idempotency_key')->nullable()->after('public_id');
            });
        }

        DB::table('email_deliveries')
            ->where('type', 'participant_form_access')
            ->whereNull('sent_at')
            ->whereNotIn('status', ['sent', 'cancelled'])
            ->update([
                'status' => 'cancelled',
                'recipient_email' => null,
                'payload' => null,
                'last_error' => null,
                'processing_at' => null,
            ]);

        DB::table('email_deliveries')
            ->whereNull('idempotency_key')
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    DB::table('email_deliveries')->where('id', $delivery->id)->update([
                        'idempotency_key' => (string) Str::uuid(),
                    ]);
                }
            });

        if (! Schema::hasIndex('email_deliveries', 'email_deliveries_idempotency_key_unique')) {
            Schema::table('email_deliveries', function (Blueprint $table): void {
                $table->unique('idempotency_key');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('email_deliveries', 'expires_at')) {
            Schema::table('email_deliveries', function (Blueprint $table): void {
                $table->dropColumn('expires_at');
            });
        }

        if (Schema::hasColumn('email_deliveries', 'idempotency_key')) {
            Schema::table('email_deliveries', function (Blueprint $table): void {
                if (Schema::hasIndex('email_deliveries', 'email_deliveries_idempotency_key_unique')) {
                    $table->dropUnique('email_deliveries_idempotency_key_unique');
                }

                $table->dropColumn('idempotency_key');
            });
        }
    }
};
