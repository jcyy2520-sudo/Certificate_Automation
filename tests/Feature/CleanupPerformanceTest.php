<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\WebinarController;
use App\Models\Form;
use App\Models\Import;
use App\Models\Submission;
use App\Models\SubmissionAnswer;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\EligibilityService;
use App\Services\FormResponseService;
use App\Services\ImportedResponseSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CleanupPerformanceTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_preserve_distinct_respondents_and_scored_attempt_averages(): void
    {
        $owner = User::factory()->create();
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $participants = collect(range(1, 3))->map(fn ($id) => $webinar->participants()->create([
            'full_name' => 'Synthetic '.$id, 'email' => 'synthetic'.$id.'@example.test',
        ]));
        foreach (FormResponseService::TYPES as $type => $title) {
            $form = $webinar->forms()->create(['type' => $type, 'title' => $title]);
            foreach ([[0, 8, 10, 'submitted'], [0, 6, 20, 'submitted'], [1, null, 100, 'submitted'], [2, 0, null, 'submitted'], [2, 99, 99, 'superseded']] as $attempt => [$person, $score, $max, $status]) {
                $form->submissions()->create(['participant_id' => $participants[$person]->id,
                    'attempt_number' => $attempt + 1, 'status' => $status, 'score' => $score, 'maximum_score' => $max]);
            }
        }
        $webinar->forms()->create(['type' => 'other', 'title' => 'Empty form']);
        DB::enableQueryLog();
        DB::flushQueryLog();
        $stats = app(WebinarController::class)->reports($webinar, app(EligibilityService::class))->getData()['formStats'];
        $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'from "submissions"'));
        DB::disableQueryLog();
        foreach ($stats->whereIn('type', ['pretest', 'posttest']) as $row) {
            $this->assertSame(3, $row['respondents']);
            $this->assertSame(100, $row['rate']);
            $this->assertSame(4.7, $row['avg']);
            $this->assertSame(15.0, $row['avgMax']);
        }
        $this->assertNull($stats->firstWhere('type', 'registration')['avg']);
        $this->assertSame(0, $stats->firstWhere('type', 'other')['respondents']);
        $this->measure('report_submission_queries', $queries->count());
        $this->assertCount(1, $queries, 'Reports should aggregate submissions in one grouped query.');
    }

    public function test_response_values_preserve_mapped_original_and_historical_choice_answers(): void
    {
        $submission = new class extends Submission
        {
            public int $payloadReads = 0;

            public function getAttribute($key)
            {
                $this->payloadReads += $key === 'imported_data' ? 1 : 0;

                return parent::getAttribute($key);
            }
        };
        $extra = ImportedResponseSchema::extraKey('Detailed response');
        $submission->metadata = ['response_schema' => ['question_1' => ['options' => [5 => 'Historical choice']]]];
        $submission->imported_data = ['mapped' => ['field_1' => ['One', 'Two']],
            'schema' => [$extra => ['source_index' => 0]],
            'original' => [['label' => 'Detailed response', 'value' => str_repeat('Original answer ', 1000)]]];
        $submission->setRelation('answers', collect([new SubmissionAnswer([
            'question_id' => 1, 'value' => [5],
        ])]));
        $columns = ['question_1' => ['type' => 'multiple_choice', 'options' => [5 => 'Current choice']],
            'field_1' => ['type' => 'checkbox'], $extra => ['type' => 'text']];
        $service = app(FormResponseService::class);
        $started = hrtime(true);
        for ($i = 0; $i < 500; $i++) {
            $values = $service->values($submission, $columns);
        }
        $this->assertSame(['Historical choice'], $values['question_1']);
        $this->assertSame(['One', 'Two'], $values['field_1']);
        $this->assertSame([str_repeat('Original answer ', 1000)], $values[$extra]);
        $this->measure('response_payload_reads_per_row', $submission->payloadReads / 500);
        $this->measure('response_values_500_ms', round((hrtime(true) - $started) / 1e6, 2));
        $this->assertSame(500, $submission->payloadReads, 'Decrypt an imported payload once per row.');
    }

    public function test_wide_import_schema_preserves_columns_without_repeated_mapping_decodes(): void
    {
        $webinar = Webinar::factory()->create();
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Synthetic form']);
        $field = $form->fields()->create(['key' => 'exact_question', 'label' => 'Exact question', 'field_type' => 'text', 'is_required' => true]);
        $import = new class extends Import
        {
            public int $mapReads = 0;

            public function getAttribute($key)
            {
                $this->mapReads += $key === 'column_map' ? 1 : 0;

                return parent::getAttribute($key);
            }
        };
        $headers = ['Email', 'Exact question', ...array_map(fn ($i) => 'Extra '.$i, range(1, 100))];
        $import->column_map = ['headers' => $headers, 'analysis' => ['columns' => array_fill(0, 102, ['type' => 'number'])]];
        $import->setRelation('form', $form);
        $started = hrtime(true);
        for ($i = 0; $i < 100; $i++) {
            $columns = app(ImportedResponseSchema::class)->columns($import, ['email' => 0], ['answer_map' => ['field_'.$field->id => 1]]);
        }
        $this->assertCount(101, $columns);
        $this->assertSame('Exact question', $columns['field_'.$field->id]['label']);
        $this->assertTrue($columns['field_'.$field->id]['required']);
        $this->assertSame('number', $columns[ImportedResponseSchema::extraKey('Extra 100')]['detected_type']);
        $this->measure('schema_mapping_reads_per_build', $import->mapReads / 100);
        $this->measure('schema_builds_100_ms', round((hrtime(true) - $started) / 1e6, 2));
        $this->assertSame(100, $import->mapReads, 'Decode an import mapping once per schema build.');
    }

    public function test_batch_import_reuses_locked_definitions_and_keeps_each_original_response(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
        $webinar = Webinar::factory()->create();
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Synthetic registration']);
        $field = $form->fields()->create(['key' => 'course', 'label' => 'Course', 'field_type' => 'text', 'is_required' => true]);
        $counter = new class extends FormResponseService
        {
            public int $builds = 0;

            public function nativeColumns(Form $form): array
            {
                $this->builds++;

                return parent::nativeColumns($form);
            }
        };
        $this->app->instance(FormResponseService::class, $counter);
        $csv = "Email,Name,Course\n";
        foreach (range(1, 20) as $id) {
            $csv .= "synthetic{$id}@example.test,Person {$id},Course {$id}\n";
        }
        $service = app(CsvImportService::class);
        $import = $service->storePending($webinar, $form, UploadedFile::fake()->createWithContent('synthetic.csv', $csv));
        $counter->builds = 0;
        $result = $service->process($import, $import->column_map['map'], options: [
            'allow_import_time' => true, 'source_description' => 'Synthetic cleanup test', 'source_confirmed' => true,
            'answer_map' => ['field_'.$field->id => 2],
        ]);
        $this->assertSame(20, $result['valid']);
        $this->assertLessThanOrEqual(4, $counter->builds, 'Build locked definitions per batch, never per row.');
        foreach (Submission::query()->with('answers')->get() as $submission) {
            $data = $submission->imported_data;
            $this->assertSame([$data['original'][2]['value']], $data['mapped']['field_'.$field->id]);
            $this->assertSame($data['mapped']['field_'.$field->id], $submission->answers->sole()->value);
            $this->assertSame('Course', $data['schema']['field_'.$field->id]['label']);
        }
    }

    public function test_eligibility_and_settings_skip_answer_schemas_and_large_submission_payloads(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $webinar = Webinar::factory()->create(['created_by' => $owner->id]);
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Registration']);
        $participant = $webinar->participants()->create(['full_name' => 'Synthetic Person', 'email' => 'synthetic@example.test']);
        $form->submissions()->create(['participant_id' => $participant->id, 'status' => 'submitted',
            'attempt_number' => 1, 'imported_data' => ['original' => str_repeat('Large synthetic response ', 1000)]]);
        foreach (['eligibility', 'settings'] as $view) {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $response = $this->get(route('admin.responses.index', [$webinar, 'view' => $view]))->assertOk();
            $queries = DB::getQueryLog();
            DB::disableQueryLog();
            $this->assertSame([], $response->viewData('columns'));
            $this->assertEmpty(array_filter($queries, fn ($query) => str_contains($query['query'], 'from "imports"')));
            if ($view === 'eligibility') {
                $submission = $response->viewData('participants')->first()->submissions->sole();
                $this->assertArrayNotHasKey('imported_data', $submission->getAttributes());
                $this->assertSame('registration', $submission->form->type);
            }
        }
    }

    private function measure(string $key, int|float $value): void
    {
        $path = storage_path('framework/testing/cleanup-measurements.json');
        $metrics = is_file($path) ? json_decode(file_get_contents($path), true) : [];
        $metrics[$key] = $value;
        file_put_contents($path, json_encode($metrics, JSON_PRETTY_PRINT));
    }
}
