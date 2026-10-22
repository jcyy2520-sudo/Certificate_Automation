<?php

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            // encrypted:array stores an opaque ciphertext string, not JSON.
            // PostgreSQL validates JSON whereas SQLite accepted those strings.
            if (DB::getDriverName() === 'pgsql') {
                $table = DB::getQueryGrammar()->wrapTable('import_issues');
                DB::statement("ALTER TABLE {$table} ALTER COLUMN raw_data TYPE TEXT USING raw_data::text");
            } else {
                Schema::table('import_issues', function (Blueprint $table): void {
                    $table->text('raw_data')->nullable()->change();
                });
            }

            DB::table('import_issues')->whereNotNull('raw_data')->orderBy('id')->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $stored = (string) $row->raw_data;
                    try {
                        Crypt::decryptString($stored);

                        continue; // Preserve existing ciphertext byte for byte.
                    } catch (DecryptException) {
                        $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
                    }
                    if (is_string($decoded)) {
                        // A PostgreSQL JSON string acquires surrounding quotes
                        // when converted to text. Remove that envelope only.
                        Crypt::decryptString($decoded);
                        $encrypted = $decoded;
                    } elseif (is_array($decoded)) {
                        $encrypted = Crypt::encryptString(json_encode($decoded, JSON_THROW_ON_ERROR));
                    } else {
                        throw new RuntimeException('Import issue '.$row->id.' has an unsupported raw-data representation. Migration stopped without discarding it.');
                    }
                    DB::table('import_issues')->where('id', $row->id)->update(['raw_data' => $encrypted]);
                }
            });
        });
    }

    public function down(): void
    {
        // Deliberately retain TEXT and ciphertext. Converting back to JSON would
        // make encrypted writes invalid again or require decrypting private data.
    }
};
