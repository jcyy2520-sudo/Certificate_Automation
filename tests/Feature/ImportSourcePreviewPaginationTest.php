<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportSourcePreviewPaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_anonymous_source_row_has_an_exact_readable_preview_without_creating_participants(): void
    {
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Storage::set('local', Storage::fake('source-preview-'.bin2hex(random_bytes(8))));
        Http::preventStrayRequests();
        Mail::fake();
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($owner);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $form = $webinar->forms()->create(['type' => 'evaluation', 'title' => 'Anonymous evaluation']);
        $header = '  '.str_repeat('Question wording ', 6)."\nFinal wording  ";
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, [$header], ',', '"', '');
        $expected = [];
        for ($index = 1; $index <= 13; $index++) {
            $expected[] = '  Row '.$index.' É 李 '.str_repeat('long exact answer ', 12)."\nEnd  ";
            fputcsv($stream, [$expected[array_key_last($expected)]], ',', '"', '');
        }
        rewind($stream);
        $file = UploadedFile::fake()->createWithContent('anonymous.csv', stream_get_contents($stream));
        fclose($stream);
        $import = app(CsvImportService::class)->storePending($webinar, $form, $file);
        $next = route('admin.webinars.imports.map', [$webinar, $import]);
        $seen = [];
        $sourceRows = [];
        $pages = 0;
        do {
            $response = $this->get($next)->assertOk();
            $document = new DOMDocument;
            $before = libxml_use_internal_errors(true);
            $document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent(), LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($before);
            $dom = new DOMXPath($document);
            $this->assertSame($header, $dom->query('//table[@data-source-preview]/thead/tr/th[2]')->item(0)?->textContent);
            foreach ($dom->query('//table[@data-source-preview]/tbody/tr') as $row) {
                $sourceRows[] = (int) $dom->query('./td[1]', $row)->item(0)->textContent;
                $seen[] = $dom->query('./td[2]', $row)->item(0)->textContent;
            }
            $next = $dom->query('//a[@rel="next"]/@href')->item(0)?->nodeValue;
            if ($next !== null) {
                parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
                $this->assertSame(['preview_page'], array_keys($query));
            }
            $pages++;
            $this->assertLessThanOrEqual(3, $pages, 'Source preview pagination must terminate.');
        } while ($next !== null);
        $this->assertSame(3, $pages, 'Every source row must be reachable beyond the first five.');
        $this->assertSame(range(2, 14), $sourceRows);
        $this->assertSame($expected, $seen);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
        $this->assertDatabaseCount('certificates', 0);
        $this->assertDatabaseCount('email_deliveries', 0);
        Mail::assertNothingSent();
    }
}
