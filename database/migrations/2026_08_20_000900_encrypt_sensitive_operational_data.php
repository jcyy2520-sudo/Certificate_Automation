<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypt sensitive values which the application never filters or sorts by.
     * Queryable participant identity is deliberately handled separately through
     * database, volume, and backup encryption rather than unusable ciphertext.
     */
    public function up(): void
    {
        Schema::table('submission_answers', function (Blueprint $table): void {
            $table->longText('value')->nullable()->change();
        });

        Schema::table('email_deliveries', function (Blueprint $table): void {
            $table->text('recipient_email')->nullable()->change();
            $table->longText('payload')->nullable()->change();
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->text('recipient_name')->nullable()->change();
        });

        $this->encryptColumns('submission_answers', ['value']);
        $this->encryptColumns('email_deliveries', ['recipient_email', 'payload', 'last_error']);
        $this->encryptColumns('certificates', ['recipient_name', 'revocation_reason']);
        $this->encryptColumns('eligibility_overrides', ['reason']);
    }

    public function down(): void
    {
        $this->decryptColumns('submission_answers', ['value']);
        $this->decryptColumns('email_deliveries', ['recipient_email', 'payload', 'last_error']);
        $this->decryptColumns('certificates', ['recipient_name', 'revocation_reason']);
        $this->decryptColumns('eligibility_overrides', ['reason']);

        Schema::table('submission_answers', function (Blueprint $table): void {
            $table->json('value')->nullable()->change();
        });

        Schema::table('email_deliveries', function (Blueprint $table): void {
            $table->string('recipient_email')->nullable()->change();
            $table->json('payload')->nullable()->change();
        });

        Schema::table('certificates', function (Blueprint $table): void {
            $table->string('recipient_name')->nullable()->change();
        });
    }

    /** @param list<string> $columns */
    private function encryptColumns(string $table, array $columns): void
    {
        DB::table($table)
            ->select(['id', ...$columns])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $columns): void {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach ($columns as $column) {
                        if ($row->{$column} === null) {
                            continue;
                        }

                        $value = (string) $row->{$column};

                        try {
                            // A previous attempt may have committed this row
                            // before a later row failed. Preserve ciphertext so
                            // rerunning the migration never double-encrypts it.
                            Crypt::decryptString($value);
                        } catch (DecryptException) {
                            $changes[$column] = Crypt::encryptString($value);
                        }
                    }

                    if ($changes !== []) {
                        DB::table($table)->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    /** @param list<string> $columns */
    private function decryptColumns(string $table, array $columns): void
    {
        DB::table($table)
            ->select(['id', ...$columns])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $columns): void {
                foreach ($rows as $row) {
                    $changes = [];

                    foreach ($columns as $column) {
                        if ($row->{$column} === null) {
                            continue;
                        }

                        try {
                            $changes[$column] = Crypt::decryptString((string) $row->{$column});
                        } catch (DecryptException) {
                            // A partially rolled-back migration may already have
                            // restored this value. Leave plaintext untouched.
                        }
                    }

                    if ($changes !== []) {
                        DB::table($table)->where('id', $row->id)->update($changes);
                    }
                }
            });
    }
};
