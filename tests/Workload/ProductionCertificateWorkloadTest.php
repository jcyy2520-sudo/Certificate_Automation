<?php

namespace Tests\Workload;

use App\Contracts\TransactionalMailer;
use App\Jobs\IssueSelectedCertificate;
use App\Jobs\SendTransactionalEmail;
use App\Models\Certificate;
use App\Models\EmailDelivery;
use App\Models\Participant;
use App\Models\User;
use App\Models\Webinar;
use App\Services\Email\BrevoTransactionalMailer;
use App\Services\EmailOutboxRelay;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Opt-in workload: php artisan test tests/Workload/ProductionCertificateWorkloadTest.php */
class ProductionCertificateWorkloadTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Each application owns a fresh in-memory database, discarded on exit.
        // Exercise forward migrations without pretending old irreversible downs
        // constitute the documented backup-and-restore rollback procedure.
        $this->artisan('migrate', ['--force' => true])->assertSuccessful();
    }

    public function createApplication()
    {
        $app = parent::createApplication();
        if (! $app->environment('testing') || config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:'
            || filled(config('database.connections.sqlite.url'))) {
            throw new \RuntimeException('The workload requires isolated SQLite :memory: before any migrations run.');
        }

        return $app;
    }

    public static function batchSizes(): array
    {
        return [[1], [50], [100], [200], [300, true]];
    }

    #[DataProvider('batchSizes')]
    public function test_recipients_complete_through_serialized_database_jobs_and_the_provider_adapter(int $size, bool $bulk = false): void
    {
        $disk = 'production-workload-'.Str::uuid();
        Storage::fake($disk);
        Http::preventStrayRequests();
        config([
            'queue.default' => 'database',
            'webinar.certificate_disk' => $disk,
            'webinar.email.queue_connection' => 'database-emails',
            'webinar.email.provider' => 'brevo',
            'webinar.email.certificate_provider' => 'brevo',
            'services.brevo.key' => 'synthetic-workload-key',
            'services.brevo.base_url' => 'https://api.brevo.com/v3',
            'accounts.email_per_minute' => 30,
            'accounts.email_per_day' => 300,
        ]);
        $this->app->bind(TransactionalMailer::class, BrevoTransactionalMailer::class);
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($owner);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $webinar->forms()->create(['type' => 'registration', 'title' => 'Registration', 'status' => 'published']);
        $webinar->eligibilityRules()->create(['requirement' => 'registration', 'is_required' => true]);
        $image = UploadedFile::fake()->image('workload.png', 1200, 850);
        Storage::disk($disk)->put('certificate-backgrounds/workload.png', file_get_contents($image->getRealPath()));
        $webinar->certificateTemplates()->create([
            'name' => 'Workload', 'storage_disk' => $disk, 'background_path' => 'certificate-backgrounds/workload.png', 'is_active' => true,
            'layout' => ['bg_w' => 1200, 'bg_h' => 850, 'name_top' => 55, 'name_left' => 50, 'name_font_size' => 36, 'name_font_family' => 'sans', 'accent' => '#172554'],
        ]);
        $participants = collect(range(1, $size))->map(fn ($number) => $webinar->participants()->create([
            'email' => sprintf('recipient-%03d@example.test', $number),
            'full_name' => $number === 1 ? 'José Niño de la Cruz Jr.' : 'Synthetic Recipient '.$number,
            'verified_at' => now(),
        ]));
        $accepted = [];
        $attempts = [];
        Http::fake(function (Request $request) use (&$accepted, &$attempts, $disk) {
            $this->assertSame('https://api.brevo.com/v3/smtp/email', $request->url());
            $recipient = $request['to'][0]['email'];
            $key = $request['headers']['idempotencyKey'];
            $attempts[$recipient] = ($attempts[$recipient] ?? 0) + 1;
            if ($recipient === 'recipient-001@example.test' && $attempts[$recipient] === 1) {
                return Http::response(['code' => 'too_many_requests'], 429, ['Retry-After' => '1']);
            }
            $this->assertArrayNotHasKey($recipient, $accepted, 'A recipient was accepted twice.');
            $participant = Participant::query()->where('email_normalized', $recipient)->sole();
            $certificate = $participant->certificates()->where('status', 'issued')->sole();
            $pdf = Storage::disk($disk)->get($certificate->file_path);
            $this->assertStringStartsWith('%PDF-', $pdf);
            $this->assertSame($participant->full_name, $certificate->recipient_name);
            $this->assertSame($pdf, base64_decode($request['attachment'][0]['content'], true));
            $this->assertNotEmpty($key);
            $accepted[$recipient] = $key;

            return Http::response(['messageId' => 'synthetic-'.count($accepted)], 201);
        });
        memory_reset_peak_usage();
        $started = hrtime(true);
        $requestStarted = hrtime(true);
        $selection = ['participants' => $participants->pluck('public_id')->all(), 'preview_confirmed' => '1'];
        $selections = $participants->chunk(150)->map(fn ($chunk) => ['participants' => $chunk->pluck('public_id')->all(), 'preview_confirmed' => '1']);
        if ($bulk) {
            $this->post(route('admin.certificates.batch', $webinar))->assertRedirect()->assertSessionHas('success');
            $this->post(route('admin.certificates.batch', $webinar))->assertRedirect()->assertSessionHas('warning');
        } else {
            foreach ($selections as $selected) {
                $this->post(route('admin.certificates.issue-selected', $webinar), $selected)->assertRedirect()->assertSessionHas('success');
            }
        }
        $requestSeconds = (hrtime(true) - $requestStarted) / 1e9;
        $this->assertDatabaseCount('certificates', $bulk ? 0 : $size);
        $this->assertDatabaseCount('email_deliveries', 0);
        $this->assertSame($bulk ? 1 : $size, DB::table('jobs')->where('queue', 'default')->count());
        $worker = $this->app->make('queue.worker');
        $options = new WorkerOptions(sleep: 0, maxTries: 3);
        for ($number = 0; $number < ($bulk ? 1 : $size); $number++) {
            $worker->runNextJob('database', 'default', $options);
        }
        $generationSeconds = (hrtime(true) - $started) / 1e9;
        $this->assertSame($size, Certificate::query()->where('status', 'issued')->count(), json_encode([
            'certificate_states' => Certificate::query()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->all(),
            'queue_states' => DB::table('jobs')->select(['queue', 'attempts', 'available_at'])->get()->toArray(),
            'failed_jobs' => DB::table('failed_jobs')->pluck('exception')->all(),
        ]));
        $this->assertDatabaseCount('email_deliveries', $size);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'default')->count());
        $this->assertSame($size, DB::table('jobs')->where('queue', 'emails')->count());
        // A repeated browser submission and a redelivered generation job must be harmless.
        if (! $bulk) {
            foreach ($selections as $selected) {
                $this->post(route('admin.certificates.issue-selected', $webinar), $selected)->assertRedirect();
            }
        } else {
            $this->assertSame($size, (int) $webinar->certificateBatches()->sole()->completed_count);
            $this->assertSame('completed', $webinar->certificateBatches()->sole()->status);
        }
        Queue::connection('database')->push(new IssueSelectedCertificate($participants->first()->id));
        $worker->runNextJob('database', 'default', $options);
        $this->assertDatabaseCount('certificates', $size);
        $this->assertDatabaseCount('email_deliveries', $size);
        $cycles = 0;
        while (EmailDelivery::query()->whereIn('status', ['pending', 'processing'])->exists() && $cycles++ < 16) {
            app(EmailOutboxRelay::class)->relay(500, 120);
            $pending = DB::table('jobs')->where('queue', 'emails')->count();
            for ($number = 0; $number < $pending; $number++) {
                $worker->runNextJob('database-emails', 'emails', $options);
            }
            $this->travel(61)->seconds();
        }
        $this->assertCount($size, $accepted);
        $this->assertSame($size, count(array_unique($accepted)));
        $this->assertSame($size, EmailDelivery::query()->where('status', 'sent')->count());
        $this->assertSame($size, Certificate::query()->whereNotNull('sent_at')->count());
        $this->assertSame(0, EmailDelivery::query()->whereNotNull('payload')->count());
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('failed_jobs', 0);
        $this->assertLessThanOrEqual(192 * 1024 * 1024, memory_get_peak_usage(true), 'Synthetic workload must leave room for HTTP, email and scheduler processes.');
        Http::assertSentCount($size + 1);
        // Duplicate serialized delivery jobs cannot call the adapter again.
        $delivery = EmailDelivery::query()->firstOrFail();
        Queue::connection('database-emails')->push(new SendTransactionalEmail($delivery->id), queue: 'emails');
        $worker->runNextJob('database-emails', 'emails', $options);
        Http::assertSentCount($size + 1);
        $evidence = [
            'scope' => 'SQLite :memory:, one worker at a time, real database-queue serialization, HTTP-faked Brevo adapter; no external mail',
            'recipients' => $size, 'bulk_route' => $bulk, 'issued' => $size, 'mock_provider_accepted' => $size, 'confirmed_inbox_delivery' => false,
            'selection_request_seconds' => round($requestSeconds, 3), 'selection_and_generation_seconds' => round($generationSeconds, 3),
            'total_wall_seconds' => round((hrtime(true) - $started) / 1e9, 3), 'peak_php_memory_bytes' => memory_get_peak_usage(true),
            'provider_requests' => $size + 1, 'injected_429_responses' => 1, 'minute_budget' => 30, 'simulated_time_cycles' => $cycles,
            'duplicate_certificates' => 0, 'duplicate_provider_acceptances' => 0, 'remaining_jobs' => 0, 'failed_jobs' => 0,
        ];
        file_put_contents(storage_path('framework/testing/production-certificate-workload-'.$size.'.json'), json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $this->travelBack();
    }
}
