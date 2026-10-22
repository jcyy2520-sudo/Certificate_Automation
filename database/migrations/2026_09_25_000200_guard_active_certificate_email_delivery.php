<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table): void {
            // NULL values remain freely repeatable. A non-null value is the
            // one active certificate-delivery claim for its certificate.
            $table->string('active_certificate_delivery_key')->nullable()->unique()->after('certificate_id');
        });

        // Historical rows predate the claim. Preserve the oldest active row
        // and make any later duplicate inert before adding its unique claim;
        // retaining two pending rows would leave a release able to double-send.
        $active = DB::table('email_deliveries')
            ->where('type', 'certificate')
            ->whereIn('status', ['pending', 'processing'])
            ->whereNotNull('certificate_id')
            ->orderBy('certificate_id')
            ->orderBy('id')
            ->get(['id', 'certificate_id']);

        $claimed = [];
        foreach ($active as $delivery) {
            if (! isset($claimed[$delivery->certificate_id])) {
                DB::table('email_deliveries')->where('id', $delivery->id)->update([
                    'active_certificate_delivery_key' => 'certificate:'.$delivery->certificate_id,
                ]);
                $claimed[$delivery->certificate_id] = true;

                continue;
            }

            DB::table('email_deliveries')->where('id', $delivery->id)->update([
                'status' => 'cancelled',
                'recipient_email' => null,
                'payload' => null,
                'processing_at' => null,
                'active_certificate_delivery_key' => null,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('email_deliveries', function (Blueprint $table): void {
            $table->dropUnique(['active_certificate_delivery_key']);
            $table->dropColumn('active_certificate_delivery_key');
        });
    }
};
