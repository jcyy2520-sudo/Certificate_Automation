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
        if (! Schema::hasColumn('users', 'email_normalized')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->string('email_normalized')->nullable()->after('email');
            });
        }

        if (! Schema::hasColumn('participants', 'email_normalized')) {
            Schema::table('participants', function (Blueprint $table): void {
                $table->string('email_normalized')->nullable()->after('email');
            });
        }

        $this->backfill('users');
        $this->backfill('participants');

        // Index creation intentionally fails closed if a legacy data set has
        // case-only duplicates. Resolve those identities before serving traffic.
        if (! Schema::hasIndex('users', 'users_email_normalized_unique')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->unique('email_normalized');
            });
        }

        if (! Schema::hasIndex('participants', 'participants_webinar_email_normalized_unique')) {
            Schema::table('participants', function (Blueprint $table): void {
                $table->unique(
                    ['webinar_id', 'email_normalized'],
                    'participants_webinar_email_normalized_unique',
                );
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('participants', 'email_normalized')) {
            Schema::table('participants', function (Blueprint $table): void {
                if (Schema::hasIndex('participants', 'participants_webinar_email_normalized_unique')) {
                    $table->dropUnique('participants_webinar_email_normalized_unique');
                }

                $table->dropColumn('email_normalized');
            });
        }

        if (Schema::hasColumn('users', 'email_normalized')) {
            Schema::table('users', function (Blueprint $table): void {
                if (Schema::hasIndex('users', 'users_email_normalized_unique')) {
                    $table->dropUnique('users_email_normalized_unique');
                }

                $table->dropColumn('email_normalized');
            });
        }
    }

    private function backfill(string $table): void
    {
        DB::table($table)
            ->whereNotNull('email')
            ->whereNull('email_normalized')
            ->select(['id', 'email'])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table): void {
                foreach ($rows as $row) {
                    DB::table($table)->where('id', $row->id)->update([
                        'email_normalized' => Str::lower(trim((string) $row->email)),
                    ]);
                }
            });
    }
};
