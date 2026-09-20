<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

use function Laravel\Prompts\confirm;

class ResetSystem extends Command
{
    protected $signature = 'system:reset
                            {--keep= : Email of the administrator to keep (defaults to every administrator)}
                            {--force : Skip the confirmation prompt}';

    protected $description = 'Erase every webinar, participant, submission and certificate, keeping only administrator accounts';

    /**
     * Every table that holds operational data, ordered so children are emptied
     * before their parents. `users` and `migrations` are deliberately absent.
     */
    private const TABLES = [
        'submission_answers',
        'submissions',
        'participant_access_tokens',
        'eligibility_overrides',
        'eligibility_rules',
        'certificates',
        'certificate_batches',
        'certificate_templates',
        'question_choices',
        'questions',
        'form_fields',
        'forms',
        'participants',
        'webinars',
        'email_deliveries',
        'audit_logs',
        'jobs',
        'job_batches',
        'failed_jobs',
        'sessions',
        'cache',
        'cache_locks',
    ];

    public function handle(): int
    {
        if (app()->isProduction()) {
            $this->error('System reset is disabled in production. Nothing was changed.');

            return self::FAILURE;
        }

        $keep = User::query()
            ->when($this->option('keep'), fn ($query, $email) => $query->where('email', $email))
            ->get();

        if ($keep->isEmpty()) {
            $this->error($this->option('keep')
                ? 'No account matches the requested keep filter. Nothing was changed.'
                : 'There are no accounts to keep. Run admin:create first.');

            return self::FAILURE;
        }

        $this->line('Keeping '.$keep->count().' account(s).');

        if (! $this->option('force') && ! confirm('Erase all other data? This cannot be undone.', default: false)) {
            $this->line('Nothing was changed.');

            return self::SUCCESS;
        }

        // Files must be removed before their database inventory disappears.
        // If remote storage is unavailable, leave the database untouched so the
        // reset can be retried without orphaning private certificate PDFs.
        $files = $this->removeGeneratedFiles();
        $erased = $this->truncateTables();
        $this->removeOtherUsers($keep->pluck('id')->all());
        $this->compactDatabase();

        $this->newLine();
        $this->info("System reset. {$erased} rows and {$files} generated files removed.");
        $this->line('  Sign in at '.route('login'));

        return self::SUCCESS;
    }

    private function truncateTables(): int
    {
        $erased = 0;

        // SQLite enforces foreign keys per connection; suspending them lets the
        // tables be emptied in one pass regardless of declaration order.
        Schema::withoutForeignKeyConstraints(function () use (&$erased) {
            foreach (self::TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }

                $erased += DB::table($table)->count();
                DB::table($table)->delete();
            }
        });

        // Restart identifiers so a fresh system numbers its first webinar 1.
        if (DB::getDriverName() === 'sqlite' && Schema::hasTable('sqlite_sequence')) {
            DB::table('sqlite_sequence')->whereIn('name', self::TABLES)->delete();
        }

        return $erased;
    }

    private function removeOtherUsers(array $keepIds): void
    {
        User::query()->whereNotIn('id', $keepIds)->delete();
    }

    /**
     * Issued certificate PDFs and QR images live on disk, not in the database.
     */
    private function removeGeneratedFiles(): int
    {
        $removed = 0;
        $disks = DB::table('certificates')
            ->whereNotNull('storage_disk')
            ->distinct()
            ->pluck('storage_disk')
            ->push(config('webinar.certificate_disk', 'local'))
            ->filter()
            ->unique();

        foreach ($disks as $diskName) {
            $disk = Storage::disk($diskName);

            foreach (['certificates', 'qr-codes'] as $directory) {
                if (! $disk->exists($directory)) {
                    continue;
                }

                $removed += count($disk->allFiles($directory));

                if (! $disk->deleteDirectory($directory)) {
                    throw new RuntimeException("Could not remove {$directory} from the {$diskName} disk. Nothing was reset.");
                }
            }
        }

        return $removed;
    }

    private function compactDatabase(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            return;
        }

        // Deleted rows leave free pages behind; VACUUM returns them to the OS.
        DB::statement('VACUUM');
    }
}
