<?php

namespace Tests\Feature;

use App\Models\Import;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GuidedImportWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        Storage::set('local', Storage::fake('guided-import-'.bin2hex(random_bytes(6))));
        $this->actingAs(User::factory()->create(['role' => User::USER_ROLE]));
    }

    public function test_review_choices_survive_preview_navigation_and_refresh_and_commit_still_requires_current_review(): void
    {
        [$webinar, $import] = $this->upload();
        $payload = $this->choices();
        $this->post(route('admin.webinars.imports.choices', [$webinar, $import]), $payload)
            ->assertRedirect(route('admin.webinars.imports.map', [$webinar, $import]));
        $this->assertSame(['email' => 1, 'full_name' => 0], $import->fresh()->column_map['map']);
        $this->get(route('admin.webinars.imports.map', [$webinar, $import, 'preview_page' => 2]))
            ->assertOk()->assertSee('Confirm columns')->assertSee('Guided source v1');
        $this->post(route('admin.webinars.imports.dry-run', [$webinar, $import]), $payload)
            ->assertRedirect(route('admin.webinars.imports.map', [$webinar, $import, 'step' => 'review']));
        $this->assertSame(7, $import->fresh()->column_map['review_results']['valid']);
        $this->get(route('admin.webinars.imports.map', [$webinar, $import, 'step' => 'review']))
            ->assertOk()->assertSee('Review results')->assertSee('7 ready to import')->assertSee('Import reviewed responses');
        $this->get(route('admin.webinars.imports.map', [$webinar, $import, 'step' => 'review']))
            ->assertOk()->assertSee('7 ready to import');
        $changed = array_replace($payload, ['source_description' => 'Guided source revised']);
        $this->post(route('admin.webinars.imports.choices', [$webinar, $import]), $changed)->assertRedirect();
        $this->assertArrayNotHasKey('review_hash', $import->fresh()->column_map);
        $this->get(route('admin.webinars.imports.map', [$webinar, $import, 'step' => 'review']))
            ->assertRedirect(route('admin.webinars.imports.map', [$webinar, $import]));
        $this->post(route('admin.webinars.imports.store', [$webinar, $import]), $changed + ['review_confirmed' => '1'])
            ->assertSessionHas('error');
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_mode_can_be_changed_before_identity_or_source_confirmation_and_invalid_modes_cannot_be_saved(): void
    {
        [$webinar, $import] = $this->upload('evaluation');
        $this->post(route('admin.webinars.imports.choices', [$webinar, $import]), ['response_mode' => 'unattributed', 'mode_only' => '1'])
            ->assertRedirect(route('admin.webinars.imports.map', [$webinar, $import]));
        $this->assertSame('unattributed', $import->fresh()->column_map['options']['response_mode']);
        $this->get(route('admin.webinars.imports.map', [$webinar, $import]))->assertOk()->assertSee('Unattributed evaluation');
        $this->post(route('admin.webinars.imports.choices', [$webinar, $import]), ['response_mode' => 'guess', 'mode_only' => '1'])
            ->assertSessionHas('error');
        $this->assertSame('unattributed', $import->fresh()->column_map['options']['response_mode']);
        $this->assertDatabaseCount('participants', 0);
    }

    private function upload(string $type = 'registration'): array
    {
        $webinar = Webinar::factory()->create(['created_by' => auth()->id(), 'retention_due_at' => now()->addYear()]);
        $form = $webinar->forms()->create(['type' => $type, 'title' => ucfirst($type), 'status' => 'published']);
        $lines = ['Name,Email,Feedback'];
        foreach (range(1, 7) as $row) {
            $lines[] = 'Person '.$row.',person'.$row.'@example.test,Exact feedback '.$row;
        }
        $import = app(CsvImportService::class)->storePending($webinar, $form, UploadedFile::fake()->createWithContent('guided.csv', implode("\n", $lines)));

        return [$webinar, $import];
    }

    public function test_a_concurrent_commit_keeps_its_schema_when_an_older_request_reports_failure(): void
    {
        [$webinar, $import] = $this->upload();
        $payload = $this->choices();
        $this->post(route('admin.webinars.imports.dry-run', [$webinar, $import]), $payload)->assertSessionHas('dry_run');
        $schema = ['exact_source_question' => ['label' => 'Exact original heading']];
        $this->mock(CsvImportService::class)->shouldReceive('process')->once()->andReturnUsing(function () use ($import, $schema) {
            $current = $import->fresh();
            $current->update(['status' => Import::STATUS_COMMITTED, 'valid_rows' => 7,
                'column_map' => array_merge($current->column_map, ['response_schema' => $schema, 'cleanup_pending' => false])]);
            throw new \RuntimeException('This upload is no longer pending.');
        });
        $this->post(route('admin.webinars.imports.store', [$webinar, $import]), $payload + ['review_confirmed' => '1'])->assertSessionHas('error');
        $this->assertSame($schema, $import->fresh()->column_map['response_schema'] ?? null);
        $this->assertSame(7, $import->fresh()->valid_rows);
        $this->assertSame(Import::STATUS_COMMITTED, $import->fresh()->status);
    }

    private function choices(): array
    {
        return ['map' => ['email' => 1, 'full_name' => 0], 'timestamp_format' => 'auto', 'allow_import_time' => '1',
            'source_description' => 'Guided source v1', 'source_confirmed' => '1', 'response_mode' => 'individual'];
    }
}
