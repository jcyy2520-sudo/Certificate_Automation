<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class MultiUserMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_upgrade_and_rollback_preserve_historical_owners_participants_and_password_hashes(): void
    {
        $admin = User::factory()->create();
        $inactive = User::factory()->create(['is_active' => false]);
        $ordinary = User::factory()->create(['role' => 'pending', 'is_active' => false]);
        $webinar = Webinar::factory()->create(['created_by' => $admin->id]);
        $participant = $webinar->participants()->create(['full_name' => 'Preserved Historical Name', 'email' => 'historical@example.test', 'verified_at' => now()]);
        $password = $admin->password;
        $migration = require database_path('migrations/2026_10_21_000100_add_platform_accounts_and_webinar_protection.php');
        $migration->down();
        $snapshot = DB::table('participants')->where('id', $participant->id)->first();
        $migration->up();
        $this->assertSame($password, $admin->fresh()->password);
        $this->assertSame('approved', $admin->fresh()->approval_status);
        $this->assertTrue($admin->fresh()->canAccessPlatform());
        $this->assertSame('suspended', $inactive->fresh()->approval_status);
        $this->assertFalse($ordinary->fresh()->canAccessPlatform());
        $this->assertSame('pending', $ordinary->fresh()->role);
        $this->assertSame($admin->id, $webinar->fresh()->created_by);
        $this->assertEquals($snapshot, DB::table('participants')->where('id', $participant->id)->first());
        $migration->down();
        $this->assertEquals($snapshot, DB::table('participants')->where('id', $participant->id)->first());
        $this->assertDatabaseHas('webinars', ['id' => $webinar->id, 'created_by' => $admin->id]);
    }
}
