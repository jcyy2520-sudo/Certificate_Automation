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
        Schema::table('email_deliveries', function (Blueprint $table): void {
            $table->text('subject')->nullable()->change();
        });

        DB::table('email_deliveries')
            ->whereNotNull('subject')
            ->select(['id', 'subject'])
            ->orderBy('id')
            ->chunkById(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    try {
                        Crypt::decryptString((string) $delivery->subject);
                    } catch (DecryptException) {
                        DB::table('email_deliveries')->where('id', $delivery->id)->update([
                            'subject' => Crypt::encryptString((string) $delivery->subject),
                        ]);
                    }
                }
            });
    }

    public function down(): void
    {
        DB::table('email_deliveries')
            ->whereNotNull('subject')
            ->select(['id', 'subject'])
            ->orderBy('id')
            ->chunkById(100, function ($deliveries): void {
                foreach ($deliveries as $delivery) {
                    try {
                        $subject = Crypt::decryptString((string) $delivery->subject);
                    } catch (DecryptException) {
                        continue;
                    }

                    DB::table('email_deliveries')->where('id', $delivery->id)->update(['subject' => $subject]);
                }
            });

        Schema::table('email_deliveries', function (Blueprint $table): void {
            $table->string('subject')->nullable(false)->change();
        });
    }
};
