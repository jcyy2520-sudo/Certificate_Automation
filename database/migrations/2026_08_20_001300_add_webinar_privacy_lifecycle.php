<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('webinars', 'retention_due_at')) {
            Schema::table('webinars', function (Blueprint $table): void {
                $table->timestampTz('retention_due_at')->nullable();
            });
        }

        if (! Schema::hasColumn('webinars', 'deletion_started_at')) {
            Schema::table('webinars', function (Blueprint $table): void {
                $table->timestampTz('deletion_started_at')->nullable();
            });
        }

        if (! Schema::hasIndex('webinars', 'webinars_retention_due_at_index')) {
            Schema::table('webinars', function (Blueprint $table): void {
                $table->index('retention_due_at');
            });
        }

        if (! Schema::hasIndex('webinars', 'webinars_deletion_started_at_index')) {
            Schema::table('webinars', function (Blueprint $table): void {
                $table->index('deletion_started_at');
            });
        }

        // Commit a deadline for every legacy webinar that was already exposed
        // to participants (or already contains participant data). A data-bearing
        // row without an end time deliberately remains unset: the erasure command
        // will fail loudly instead of inventing a potentially unsafe deadline.
        DB::table('webinars')
            ->whereNull('retention_due_at')
            ->whereNotNull('ends_at')
            ->where(function ($query): void {
                $query->whereIn('status', ['published', 'completed', 'archived'])
                    ->orWhereExists(function ($participants): void {
                        $participants->selectRaw('1')
                            ->from('participants')
                            ->whereColumn('participants.webinar_id', 'webinars.id');
                    });
            })
            ->select(['id', 'ends_at', 'data_retention_days'])
            ->orderBy('id')
            ->chunkById(100, function ($webinars): void {
                foreach ($webinars as $webinar) {
                    DB::table('webinars')->where('id', $webinar->id)->update([
                        'retention_due_at' => CarbonImmutable::parse((string) $webinar->ends_at)
                            ->addDays((int) $webinar->data_retention_days),
                    ]);
                }
            });
    }

    public function down(): void
    {
        if (Schema::hasColumn('webinars', 'deletion_started_at')) {
            Schema::table('webinars', function (Blueprint $table): void {
                if (Schema::hasIndex('webinars', 'webinars_deletion_started_at_index')) {
                    $table->dropIndex('webinars_deletion_started_at_index');
                }

                $table->dropColumn('deletion_started_at');
            });
        }

        if (Schema::hasColumn('webinars', 'retention_due_at')) {
            Schema::table('webinars', function (Blueprint $table): void {
                if (Schema::hasIndex('webinars', 'webinars_retention_due_at_index')) {
                    $table->dropIndex('webinars_retention_due_at_index');
                }

                $table->dropColumn('retention_due_at');
            });
        }
    }
};
