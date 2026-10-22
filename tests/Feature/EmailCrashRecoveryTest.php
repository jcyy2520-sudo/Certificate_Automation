<?php

namespace Tests\Feature;

use App\Contracts\TransactionalMailer;
use App\Jobs\SendTransactionalEmail;
use App\Models\EmailDelivery;
use App\Models\User;
use App\Services\Email\PermanentDeliveryException;
use App\Services\Email\ProviderRateLimitException;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmailCrashRecoveryTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databasePath = tempnam(sys_get_temp_dir(), 'webinar-email-crash-');
        config([
            'database.default' => 'sqlite', 'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => $this->databasePath,
            'database.connections.crash_observer' => array_replace(config('database.connections.sqlite'), ['database' => $this->databasePath]),
            'cache.default' => 'array', 'accounts.email_per_minute' => 100,
        ]);
        DB::purge('sqlite');
        $this->artisan('migrate', ['--database' => 'sqlite', '--force' => true])->assertSuccessful();
    }

    protected function tearDown(): void
    {
        DB::purge('crash_observer');
        DB::purge('sqlite');
        if (isset($this->databasePath)) {
            unlink($this->databasePath);
        }
        parent::tearDown();
    }

    public function test_process_death_after_provider_acceptance_retains_the_retry_deadline_and_blocks_a_late_resend(): void
    {
        $delivery = $this->delivery();
        $this->crashAfterAcceptance($delivery);
        $delivery->refresh();
        $this->assertSame(1, (int) $delivery->attempts);
        $this->assertNotNull($delivery->first_attempted_at);
        $this->assertNull($delivery->sent_at);
        $this->assertNotNull($delivery->payload);

        $this->travel(26)->minutes();
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldNotReceive('send');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringContainsString('Provider outcome unknown', $delivery->fresh()->last_error);
        $this->assertSame(1, (int) $delivery->fresh()->attempts);
    }

    public function test_worker_expiry_before_handle_preserves_unknown_outcome_after_an_actual_process_crash(): void
    {
        $delivery = $this->delivery();
        $this->crashAfterAcceptance($delivery);
        $this->travel(2)->days();

        // Worker invokes failed() directly when retryUntil has expired, before
        // the job's handle() can enforce its shorter provider retry window.
        (new SendTransactionalEmail($delivery->id))->failed(new MaxAttemptsExceededException('Synthetic expired queue job.'));

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringStartsWith('Provider outcome unknown', $delivery->fresh()->last_error);
        $this->assertSame(1, (int) $delivery->fresh()->attempts);
    }

    public function test_a_database_failure_after_provider_acceptance_requires_reconciliation(): void
    {
        config(['webinar.email.max_attempts' => 1]);
        $delivery = $this->delivery();
        EmailDelivery::updating(function (EmailDelivery $updated) use ($delivery): void {
            if ($updated->id === $delivery->id && $updated->status === 'sent') {
                throw new RuntimeException('Synthetic database finalization failure after acceptance.');
            }
        });
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->andReturn('synthetic-accepted-before-database-failure');

        (new SendTransactionalEmail($delivery->id))->handle($mailer);

        $this->assertSame('failed', $delivery->fresh()->status);
        $this->assertStringStartsWith('Provider outcome unknown', $delivery->fresh()->last_error);
        $this->assertSame(1, (int) $delivery->fresh()->attempts);
        $this->assertNull($delivery->fresh()->sent_at);
    }

    public function test_classified_provider_refusals_do_not_become_unknown_outcomes(): void
    {
        config(['webinar.email.max_attempts' => 1]);
        foreach ([new PermanentDeliveryException('Synthetic clean refusal.'), new ProviderRateLimitException(30)] as $exception) {
            $delivery = $this->delivery();
            $mailer = \Mockery::mock(TransactionalMailer::class);
            $mailer->shouldReceive('send')->once()->andThrow($exception);

            (new SendTransactionalEmail($delivery->id))->handle($mailer);

            $this->assertSame('failed', $delivery->fresh()->status);
            $this->assertSame($exception->getMessage(), $delivery->fresh()->last_error);
            $this->assertSame(1, (int) $delivery->fresh()->attempts);
        }
    }

    private function crashAfterAcceptance(EmailDelivery $delivery): void
    {
        // This subprocess uses only our new temporary SQLite database and a
        // fake provider. exit() skips the job's catch/failure bookkeeping and
        // lets PDO roll back the in-flight provider transaction on shutdown.
        $worker = <<<'PHP'
        require $argv[1].'/vendor/autoload.php';
        $app = require $argv[1].'/bootstrap/app.php';
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['database.default' => 'sqlite', 'database.connections.sqlite.url' => null,
            'database.connections.sqlite.database' => $argv[2], 'cache.default' => 'array']);
        Illuminate\Support\Facades\DB::purge('sqlite');
        $mailer = new class implements App\Contracts\TransactionalMailer {
            public function send(string $recipient, string $subject, string $html, array $attachments = [], ?string $idempotencyKey = null): ?string
            {
                echo 'synthetic-provider-accepted';
                exit(73);
            }
        };
        (new App\Jobs\SendTransactionalEmail((int) $argv[3]))->handle($mailer);
        PHP;
        $process = new Process([PHP_BINARY, '-r', $worker, base_path(), $this->databasePath, (string) $delivery->id], base_path(), [
            'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $this->databasePath,
            'DB_URL' => false, 'DATABASE_URL' => false, 'CACHE_STORE' => 'array',
            'QUEUE_CONNECTION' => 'sync', 'TRANSACTIONAL_EMAIL_PROVIDER' => 'log',
        ]);
        $process->setTimeout(30)->run();
        $this->assertSame(73, $process->getExitCode(), $process->getErrorOutput());
        $this->assertSame('synthetic-provider-accepted', $process->getOutput());
    }

    public function test_the_marker_is_committed_before_transport_and_each_failed_or_successful_call_counts_once(): void
    {
        $delivery = $this->delivery();
        $firstAttempt = null;
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldReceive('send')->once()->andReturnUsing(function () use ($delivery, &$firstAttempt): never {
            // An independent connection cannot observe uncommitted writes.
            $marker = DB::connection('crash_observer')->table('email_deliveries')->find($delivery->id);
            $this->assertSame(1, (int) $marker->attempts);
            $this->assertNotNull($marker->first_attempted_at);
            $firstAttempt = $marker->first_attempted_at;
            throw new RuntimeException('Synthetic lost acknowledgement');
        });
        try {
            (new SendTransactionalEmail($delivery->id))->handle($mailer);
            $this->fail('Expected the synthetic provider failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic lost acknowledgement', $exception->getMessage());
        }
        $this->assertSame(1, (int) $delivery->fresh()->attempts);
        $this->assertSame('pending', $delivery->fresh()->status);
        $this->travel(61)->seconds();
        $mailer->shouldReceive('send')->once()->andReturn('synthetic-message-id');
        (new SendTransactionalEmail($delivery->id))->handle($mailer);
        $delivery->refresh();
        $this->assertSame(2, (int) $delivery->attempts);
        $this->assertSame($firstAttempt, $delivery->getRawOriginal('first_attempted_at'));
        $this->assertSame('sent', $delivery->status);
    }

    public function test_account_revocation_after_the_committed_marker_is_rechecked_before_transport(): void
    {
        $delivery = $this->delivery();
        EmailDelivery::updated(function (EmailDelivery $updated) use ($delivery): void {
            if ($updated->id === $delivery->id && $updated->wasChanged('first_attempted_at')) {
                DB::afterCommit(function () use ($delivery): void {
                    User::query()->whereKey($delivery->account_user_id)->update(['is_active' => false, 'approval_status' => 'suspended']);
                });
            }
        });
        $mailer = $this->mock(TransactionalMailer::class);
        $mailer->shouldNotReceive('send');

        (new SendTransactionalEmail($delivery->id))->handle($mailer);

        $this->assertSame('cancelled', $delivery->fresh()->status);
        $this->assertSame(1, (int) $delivery->fresh()->attempts);
        $this->assertNull($delivery->fresh()->recipient_email);
        $this->assertNull($delivery->fresh()->payload);
    }

    private function delivery(): EmailDelivery
    {
        $account = User::factory()->create(['role' => User::USER_ROLE]);

        return EmailDelivery::query()->create([
            'account_user_id' => $account->id, 'type' => 'account_reset', 'provider' => 'log',
            'recipient_email' => $account->email, 'subject' => 'Synthetic password reset',
            'payload' => ['html' => '<p>Synthetic reset.</p>', 'account_version' => $account->auth_version],
            'status' => 'pending', 'scheduled_at' => now(),
        ]);
    }
}
