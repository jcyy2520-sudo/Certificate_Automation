<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasIndex('forms', 'forms_public_token_unique')) {
            Schema::table('forms', function (Blueprint $table): void {
                $table->dropUnique('forms_public_token_unique');
            });
        }

        if (! Schema::hasColumn('forms', 'public_token_hash')) {
            Schema::table('forms', function (Blueprint $table): void {
                $table->text('public_token')->nullable()->change();
                $table->string('public_token_hash', 64)->nullable()->after('public_token');
            });
        }

        if (! Schema::hasIndex('forms', 'forms_public_token_hash_unique')) {
            Schema::table('forms', function (Blueprint $table): void {
                $table->unique('public_token_hash');
            });
        }

        DB::table('forms')->orderBy('id')->eachById(function (object $form): void {
            if (! filled($form->public_token)) {
                return;
            }

            $stored = (string) $form->public_token;

            try {
                $token = Crypt::decryptString($stored);
                $encrypted = $stored;
            } catch (DecryptException) {
                $token = $stored;
                $encrypted = null;
            }

            $token = Str::lower($token);

            DB::table('forms')->where('id', $form->id)->update([
                // Preserve a completed row on retry; re-encrypt only plaintext
                // or a non-normalized legacy value.
                'public_token' => $encrypted !== null && Crypt::decryptString($encrypted) === $token
                    ? $encrypted
                    : Crypt::encryptString($token),
                'public_token_hash' => hash('sha256', $token),
            ]);
        });

        if (Schema::hasIndex('certificates', 'certificates_verification_code_unique')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->dropUnique('certificates_verification_code_unique');
            });
        }

        if (! Schema::hasColumn('certificates', 'verification_code_hash')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->text('verification_code')->change();
                $table->string('verification_code_hash', 64)->nullable()->after('verification_code');
            });
        }

        if (! Schema::hasIndex('certificates', 'certificates_verification_code_hash_unique')) {
            Schema::table('certificates', function (Blueprint $table): void {
                $table->unique('verification_code_hash');
            });
        }

        DB::table('certificates')->orderBy('id')->eachById(function (object $certificate): void {
            $stored = (string) $certificate->verification_code;

            try {
                $code = Crypt::decryptString($stored);
                $encrypted = $stored;
            } catch (DecryptException) {
                $code = $stored;
                $encrypted = null;
            }

            $code = Str::upper($code);

            DB::table('certificates')->where('id', $certificate->id)->update([
                'verification_code' => $encrypted !== null && Crypt::decryptString($encrypted) === $code
                    ? $encrypted
                    : Crypt::encryptString($code),
                'verification_code_hash' => hash('sha256', $code),
            ]);
        });
    }

    public function down(): void
    {
        DB::table('forms')->orderBy('id')->eachById(function (object $form): void {
            if (! filled($form->public_token)) {
                return;
            }

            try {
                DB::table('forms')->where('id', $form->id)->update([
                    'public_token' => Crypt::decryptString($form->public_token),
                ]);
            } catch (DecryptException) {
                // Already restored during a partial rollback.
            }
        });

        if (Schema::hasColumn('forms', 'public_token_hash')) {
            Schema::table('forms', function (Blueprint $table): void {
                if (Schema::hasIndex('forms', 'forms_public_token_hash_unique')) {
                    $table->dropUnique('forms_public_token_hash_unique');
                }

                $table->dropColumn('public_token_hash');
            });
        }
        Schema::table('forms', function (Blueprint $table): void {
            $table->string('public_token', 32)->nullable()->unique()->change();
        });

        DB::table('certificates')->orderBy('id')->eachById(function (object $certificate): void {
            try {
                DB::table('certificates')->where('id', $certificate->id)->update([
                    'verification_code' => Crypt::decryptString($certificate->verification_code),
                ]);
            } catch (DecryptException) {
                // Already restored during a partial rollback.
            }
        });

        if (Schema::hasColumn('certificates', 'verification_code_hash')) {
            Schema::table('certificates', function (Blueprint $table): void {
                if (Schema::hasIndex('certificates', 'certificates_verification_code_hash_unique')) {
                    $table->dropUnique('certificates_verification_code_hash_unique');
                }

                $table->dropColumn('verification_code_hash');
            });
        }
        Schema::table('certificates', function (Blueprint $table): void {
            $table->string('verification_code', 40)->unique()->change();
        });
    }
};
