<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('certificates', 'issuance_key')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->string('issuance_key', 64)->nullable()->after('participant_id');
            });
        }

        $duplicateGroups = DB::table('certificates')
            ->whereNotNull('participant_id')
            ->whereNull('revoked_at')
            ->whereNotNull('issued_at')
            // Select only the grouped columns. A bare ->get() selects *, which
            // PostgreSQL (and MySQL in ONLY_FULL_GROUP_BY mode) reject alongside
            // a GROUP BY; SQLite tolerates it, so this only surfaces in prod.
            ->select('webinar_id', 'participant_id')
            ->groupBy('webinar_id', 'participant_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicateGroups > 0) {
            throw new RuntimeException(
                "Certificate issuance guard refused {$duplicateGroups} participant group(s) with duplicate active certificates. Reconcile them before retrying the migration.",
            );
        }

        $unfinished = DB::table('certificates')
            ->where(function ($query): void {
                $query->where('status', 'processing')
                    ->orWhere(fn ($files) => $files->whereNull('issued_at')->whereNotNull('file_path'));
            })
            ->count();

        if ($unfinished > 0) {
            throw new RuntimeException(
                "Certificate issuance guard found {$unfinished} unfinished certificate record(s). Reconcile their files before retrying the migration.",
            );
        }

        // A partial migration can be safely resumed because existing keys are
        // recognized first. Duplicate active records were rejected above.
        $claimed = DB::table('certificates')
            ->whereNotNull('issuance_key')
            ->pluck('issuance_key')
            ->flip()
            ->all();

        DB::table('certificates')
            ->whereNull('issuance_key')
            ->whereNotNull('participant_id')
            ->whereNull('revoked_at')
            ->whereNotNull('issued_at')
            ->select(['id', 'webinar_id', 'participant_id'])
            ->orderBy('id')
            ->chunkById(100, function ($certificates) use (&$claimed): void {
                foreach ($certificates as $certificate) {
                    $key = $certificate->webinar_id.':'.$certificate->participant_id;

                    if (isset($claimed[$key])) {
                        continue;
                    }

                    DB::table('certificates')->where('id', $certificate->id)->update([
                        'issuance_key' => $key,
                    ]);
                    $claimed[$key] = true;
                }
            });

        if (! Schema::hasIndex('certificates', 'certificates_issuance_key_unique')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->unique('issuance_key');
            });
        }
    }

    public function down(): void
    {
        if (! Schema::hasColumn('certificates', 'issuance_key')) {
            return;
        }

        Schema::table('certificates', function (Blueprint $table): void {
            if (Schema::hasIndex('certificates', 'certificates_issuance_key_unique')) {
                $table->dropUnique('certificates_issuance_key_unique');
            }

            $table->dropColumn('issuance_key');
        });
    }
};
