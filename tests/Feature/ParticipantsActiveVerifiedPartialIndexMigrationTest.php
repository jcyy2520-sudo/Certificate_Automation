<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ParticipantsActiveVerifiedPartialIndexMigrationTest extends TestCase
{
    public function test_postgresql_partial_index_migration_uses_the_database_connection_for_its_unchanged_sql(): void
    {
        $connection = \Mockery::mock();
        $connection->shouldReceive('getDriverName')->twice()->andReturn('pgsql');

        Schema::shouldReceive('getConnection')->twice()->andReturn($connection);
        DB::shouldReceive('statement')
            ->once()
            ->with(
                'CREATE INDEX IF NOT EXISTS participants_webinar_active_verified_index '
                .'ON participants (webinar_id) '
                .'WHERE verified_at IS NOT NULL AND privacy_erased_at IS NULL AND deleted_at IS NULL',
            )
            ->andReturnTrue()
            ->ordered();
        DB::shouldReceive('statement')
            ->once()
            ->with('DROP INDEX IF EXISTS participants_webinar_active_verified_index')
            ->andReturnTrue()
            ->ordered();

        $migration = require database_path(
            'migrations/2026_08_26_000200_add_participants_active_verified_partial_index.php',
        );

        $migration->up();
        $migration->down();
    }
}
