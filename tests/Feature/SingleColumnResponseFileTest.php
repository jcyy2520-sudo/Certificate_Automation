<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\UnattributedResponseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class SingleColumnResponseFileTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    private Form $form;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Storage::fake('local');
        Http::preventStrayRequests();
        Mail::fake();
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($owner);
        $this->webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $this->form = $this->webinar->forms()->create(['type' => 'evaluation', 'title' => 'Synthetic evaluation']);
    }

    #[DataProvider('literalHeaderDelimiters')]
    public function test_single_column_csv_and_xlsx_preserve_literal_semicolons_or_tabs_in_the_header(string $header): void
    {
        foreach ([$this->csv($header), $this->xlsx($header)] as $file) {
            $service = app(CsvImportService::class);
            $import = $service->storePending($this->webinar, $this->form, $file);
            $this->assertSame([$header], $import->column_map['headers']);
            $this->assertSame(['Useful session'], $service->previewRows($import)[0]['cells']);
            $counts = $service->process($import, [], options: [
                'response_mode' => 'unattributed', 'source_description' => 'Synthetic single-column evaluation',
                'source_confirmed' => true,
            ]);
            $this->assertSame(['total' => 1, 'valid' => 1, 'invalid' => 0, 'duplicate' => 0, 'unmatched' => 0], $counts);
            $responses = app(UnattributedResponseService::class);
            $this->assertSame([$header], array_column($responses->columns($import->fresh()), 'label'));
            $this->assertSame(['Useful session'], $responses->query($import)->sole()->original_data);
        }
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public static function literalHeaderDelimiters(): array
    {
        return [
            'semicolon' => ['How was content; pacing?'],
            'compact semicolon' => ['Content;Pacing'],
            'tab' => ["Content\tpacing?"],
            'quoted and multiline heading' => ["Was the \"content; pacing\"\nuseful?"],
        ];
    }

    #[DataProvider('unsupportedDelimiters')]
    public function test_actual_semicolon_and_tab_delimited_files_still_receive_conversion_guidance(string $contents, string $message): void
    {
        try {
            app(CsvImportService::class)->storePending($this->webinar, $this->form, UploadedFile::fake()->createWithContent('synthetic.csv', $contents));
            $this->fail('A non-comma-delimited response file was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertStringContainsString('comma delimiter', $exception->getMessage());
        }
        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('participants', 0);
    }

    public static function unsupportedDelimiters(): array
    {
        return [
            'semicolon' => ["Email;Name\none@example.test;Person One\n", 'semicolons'],
            'quoted semicolon' => ["\"Email\";\"Name\"\n\"one@example.test\";\"Person One\"\n", 'semicolons'],
            'tab' => ["Email\tName\none@example.test\tPerson One\n", 'tabs'],
        ];
    }

    private function csv(string $header): UploadedFile
    {
        $contents = '"'.str_replace('"', '""', $header).'"'."\nUseful session\n";

        return UploadedFile::fake()->createWithContent('synthetic.csv', $contents);
    }

    private function xlsx(string $header): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'single-column-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues([$header]));
            $writer->addRow(Row::fromValues(['Useful session']));
            $writer->close();

            return UploadedFile::fake()->createWithContent('synthetic.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
