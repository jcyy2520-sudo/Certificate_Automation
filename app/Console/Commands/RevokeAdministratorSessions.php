<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Support\SessionStorageSafety;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

use function Laravel\Prompts\confirm;

final class RevokeAdministratorSessions extends Command
{
    protected $signature = 'admin:sessions:revoke
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Revoke every administrator session without flushing application cache or queues';

    public function handle(Filesystem $files): int
    {
        $driver = (string) config('session.driver');

        if (app()->isProduction() && $driver !== 'redis') {
            $this->error('Production session revocation requires the approved dedicated Redis session backend. Nothing was changed.');

            return self::FAILURE;
        }

        if ($driver === 'redis' && ! SessionStorageSafety::usesDedicatedRedisDatabase()) {
            $this->error('The Redis session database is missing or shared with cache/queues. Nothing was flushed.');

            return self::FAILURE;
        }

        if (! $this->option('force') && ! confirm('Revoke every administrator session now?', default: false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        try {
            $count = match ($driver) {
                'database' => $this->revokeDatabaseSessions(),
                'file' => $this->revokeFileSessions($files),
                'redis' => $this->revokeRedisSessions(),
                default => throw new RuntimeException("The {$driver} session driver cannot be safely revoked server-side."),
            };
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage().' Nothing was changed.');

            return self::FAILURE;
        }

        AuditLog::query()->create([
            'user_id' => null,
            'action' => 'administrator.sessions_revoked',
            'metadata' => ['source' => 'console', 'driver' => $driver, 'records' => $count],
        ]);

        $this->info("{$count} server-side session record(s) revoked.");

        return self::SUCCESS;
    }

    private function revokeDatabaseSessions(): int
    {
        $connection = config('session.connection');
        $table = config('session.table');

        if (! is_string($connection) || $connection === ''
            || ! is_string($table) || ! preg_match('/^[A-Za-z0-9_]+$/', $table)
            || ! Schema::connection($connection)->hasTable($table)) {
            throw new RuntimeException('The dedicated database session connection/table is not configured safely.');
        }

        return DB::connection($connection)->table($table)->delete();
    }

    private function revokeFileSessions(Filesystem $files): int
    {
        $configuredPath = realpath((string) config('session.files'));
        $approvedPath = realpath(storage_path('framework/sessions'));

        if ($configuredPath === false || $approvedPath === false || $configuredPath !== $approvedPath) {
            throw new RuntimeException('The file session path is outside the approved session directory.');
        }

        $sessionFiles = $files->files($approvedPath);

        if ($sessionFiles !== [] && ! $files->delete($sessionFiles)) {
            throw new RuntimeException('One or more session files could not be deleted.');
        }

        return count($sessionFiles);
    }

    private function revokeRedisSessions(): int
    {
        $connectionName = (string) config('session.connection');
        $connection = app('redis')->connection($connectionName);
        $count = (int) $connection->command('dbsize');
        $result = $connection->command('flushdb');

        if ($result !== true && strtoupper((string) $result) !== 'OK') {
            throw new RuntimeException('Redis did not confirm the dedicated session database flush.');
        }

        return $count;
    }
}
