<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\ImportIssue;
use App\Models\User;
use App\Models\Webinar;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ImportIssuePaginationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Storage::set('local', Storage::fake('import-issue-pagination-'.bin2hex(random_bytes(8))));
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
        $this->actingAs(User::factory()->create(['role' => User::USER_ROLE]));
    }

    public function test_every_held_source_value_is_reachable_once_without_losing_the_form_filter_or_import_page(): void
    {
        $webinar = Webinar::factory()->create(['created_by' => auth()->id()]);
        $registration = $this->import($webinar, 'registration');
        $expected = [];
        for ($index = 1; $index <= 205; $index++) {
            $value = '  Held response '.str_pad((string) $index, 3, '0', STR_PAD_LEFT).' É <tag>&'."\nSecond line  ";
            $this->issue($registration, $index + 1, $value);
            $expected[] = $value;
        }
        // Keep the uploaded-files paginator on its second page while reviewing issues.
        for ($index = 1; $index <= 11; $index++) {
            $this->import($webinar, 'registration');
        }
        $this->issue($registration, 300, 'Resolved source must stay excluded', resolved: true);
        $this->issue($this->import($webinar, 'pretest'), 2, 'Other form must stay excluded');
        $foreign = Webinar::factory()->create();
        $this->issue($this->import($foreign, 'registration'), 2, 'Other webinar must stay excluded');

        $next = route('admin.webinars.imports.index', [$webinar, 'type' => 'registration', 'page' => 2]);
        $seen = [];
        $headers = [];
        $pages = 0;
        do {
            $response = $this->get($next)->assertOk();
            $document = $this->html($response->getContent());
            $values = $document->query('//details[summary[text()="Inspect source values"]]/dl/div/dd');
            foreach ($values as $value) {
                $seen[] = $value->textContent;
            }
            foreach ($document->query('//details[@data-source-values]/dl/div/dt') as $header) {
                $headers[] = $header->textContent;
            }
            $response->assertDontSee('Resolved source must stay excluded')
                ->assertDontSee('Other form must stay excluded')
                ->assertDontSee('Other webinar must stay excluded');
            $this->assertSame(2, $response->viewData('imports')->currentPage());
            $pages++;
            $link = $document->query('//section[.//summary[text()="Inspect source values"]]//a[@rel="next"]')->item(0);
            $next = $link?->getAttribute('href');
            if ($next !== null) {
                parse_str((string) parse_url($next, PHP_URL_QUERY), $query);
                $this->assertSame('registration', $query['type'] ?? null);
                $this->assertSame('2', $query['page'] ?? null);
                $this->assertSame((string) ($pages + 1), $query['issues_page'] ?? null);
                $this->assertEqualsCanonicalizing(['type', 'page', 'issues_page'], array_keys($query));
            }
            $this->assertLessThanOrEqual(3, $pages, 'Held-review pagination must terminate.');
        } while ($next !== null);

        $this->assertSame(2, $pages, 'Rows beyond the first 200 must have a reachable review page.');
        $this->assertSame($expected, $seen, 'Every scoped held source value must appear exactly once and keep its original characters.');
        $this->assertSame(array_fill(0, 205, '  Original question  '), $headers);
        $this->assertCount(205, array_unique($seen));
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_empty_filtered_held_review_still_renders_its_empty_state(): void
    {
        $webinar = Webinar::factory()->create(['created_by' => auth()->id()]);
        $this->issue($this->import($webinar, 'pretest'), 2, 'Another form source');

        $this->get(route('admin.webinars.imports.index', [$webinar, 'type' => 'registration']))
            ->assertOk()
            ->assertSee('No unresolved rows in this view.')
            ->assertDontSee('Another form source');
    }

    private function import(Webinar $webinar, string $type): Import
    {
        $form = $webinar->forms()->firstOrCreate(['type' => $type], ['title' => ucfirst($type)]);

        return $webinar->imports()->create([
            'form_id' => $form->id, 'status' => Import::STATUS_COMMITTED,
            'original_filename' => 'synthetic-'.$type.'.csv', 'file_hash' => hash('sha256', random_bytes(16)),
            'file_size' => 1, 'imported_by' => auth()->id(), 'committed_at' => now(),
        ]);
    }

    private function issue(Import $import, int $row, string $value, bool $resolved = false): void
    {
        $import->issues()->create([
            'webinar_id' => $import->webinar_id, 'row_number' => $row,
            'issue_type' => ImportIssue::TYPE_INVALID_EMAIL, 'issue_message' => 'Synthetic held response',
            'raw_data' => ['  Original question  ' => $value], 'resolved_at' => $resolved ? now() : null,
        ]);
    }

    private function html(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $before = libxml_use_internal_errors(true);
        try {
            $document->loadHTML('<?xml encoding="UTF-8">'.$html, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($before);
        }

        return new DOMXPath($document);
    }
}
