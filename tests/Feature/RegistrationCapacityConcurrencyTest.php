<?php

namespace Tests\Feature;

use App\Http\Controllers\PublicFormController;
use App\Models\Participant;
use App\Models\Webinar;
use App\Services\ParticipantMagicLinkService;
use App\Services\PublicFormResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Tests\TestCase;
use Throwable;

class RegistrationCapacityConcurrencyTest extends TestCase
{
    private string $databasePath;

    private mixed $originalDatabase;

    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function test_simultaneous_registration_submissions_never_exceed_capacity(): void
    {
        $this->useSharedSqliteDatabase();

        $webinar = Webinar::factory()->create([
            'requires_verification' => false,
            'registration_capacity' => 5,
        ]);
        $registration = $webinar->forms()->create([
            'type' => 'registration',
            'title' => 'Concurrent registration',
            'status' => 'published',
        ]);
        $token = $registration->public_token;

        $tasks = [];
        foreach (range(1, 12) as $number) {
            $tasks[] = static function () use ($number, $token): int {
                return retry(8, static function () use ($number, $token): int {
                    $request = Request::create("/f/{$token}", 'POST', [
                        'full_name' => "Concurrent Participant {$number}",
                        'email' => "concurrent-{$number}@example.test",
                        'privacy_acknowledged' => '1',
                    ]);
                    $request->setLaravelSession(app('session')->driver());

                    try {
                        return app(PublicFormController::class)->submit(
                            $request,
                            $token,
                            app(PublicFormResolver::class),
                            app(ParticipantMagicLinkService::class),
                        )->getStatusCode();
                    } catch (HttpExceptionInterface $exception) {
                        return $exception->getStatusCode();
                    }
                }, 75, static fn (Throwable $exception): bool => str_contains(
                    strtolower($exception->getMessage()),
                    'database is locked',
                ));
            };
        }

        $statuses = Concurrency::driver('process')->run($tasks);

        $this->assertCount(12, $statuses);
        $this->assertContains(302, $statuses);
        $this->assertContains(403, $statuses);
        $this->assertSame(5, Participant::query()
            ->where('webinar_id', $webinar->id)
            ->whereNotNull('verified_at')
            ->whereNull('privacy_erased_at')
            ->count());
    }

    protected function tearDown(): void
    {
        if (isset($this->databasePath)) {
            DB::disconnect('sqlite');
            DB::purge('sqlite');
            config(['database.connections.sqlite.database' => $this->originalDatabase]);
            putenv('DB_DATABASE');
            unset($_ENV['DB_DATABASE'], $_SERVER['DB_DATABASE']);

            if (is_file($this->databasePath)) {
                unlink($this->databasePath);
            }
        }

        parent::tearDown();
    }

    private function useSharedSqliteDatabase(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'webinar-capacity-');
        $this->assertNotFalse($path);
        $this->databasePath = $path;
        $this->originalDatabase = config('database.connections.sqlite.database');

        putenv('DB_DATABASE='.$this->databasePath);
        $_ENV['DB_DATABASE'] = $this->databasePath;
        $_SERVER['DB_DATABASE'] = $this->databasePath;
        config(['database.connections.sqlite.database' => $this->databasePath]);
        DB::purge('sqlite');

        Artisan::call('migrate:fresh', ['--force' => true]);
    }
}
