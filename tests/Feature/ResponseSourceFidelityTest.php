<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\FormResponseService;
use App\Services\ResponseFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class ResponseSourceFidelityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create());
    }

    public function test_excel_quiz_number_format_preserves_the_displayed_denominator(): void
    {
        $file = $this->xlsx([
            ['Email', 'Score', 'Timestamp'],
            ['person@example.test', 8, new \DateTimeImmutable('2026-10-20 14:30:00')],
        ], [1 => (new Style)->setFormat('0" / 10"'), 2 => (new Style)->setFormat('m/d/yyyy h:mm:ss')]);
        $csv = app(ResponseFileReader::class)->contents($file);
        $this->assertStringContainsString('8 / 10', $csv);
        $webinar = Webinar::factory()->create();
        $form = $webinar->forms()->create(['type' => 'pretest', 'title' => 'Pre-Test']);
        $webinar->participants()->create(['email' => 'person@example.test', 'full_name' => 'Person Example', 'verified_at' => now()]);
        $service = app(CsvImportService::class);
        $import = $service->storePending($webinar, $form, $file);
        $counts = $service->process($import, $import->column_map['map'], options: $this->importOptions());
        $this->assertSame(1, $counts['valid']);
        $submission = $form->submissions()->sole();
        $this->assertSame('8.00', $submission->score);
        $this->assertSame('10.00', $submission->maximum_score);
        $this->assertSame('8 / 10', $submission->imported_data['original'][1]['value']);
    }

    public function test_original_headers_identity_and_mapped_answers_survive_storage_table_and_export(): void
    {
        $webinar = Webinar::factory()->create(['created_by' => auth()->id()]);
        $form = $webinar->forms()->create(['type' => 'registration', 'title' => 'Registration']);
        $field = $form->fields()->create(['key' => 'rating', 'label' => 'Rating', 'field_type' => 'radio', 'options' => ['5']]);
        $headers = [' Email ', ' Name ', ' Rating ', ' Organization ', ' Timestamp '];
        $values = [' Person@Example.test ', '  Person Éxample  ', ' 5 ', '  School Name  ', '2026-10-20 14:30:01'];
        $stream = fopen('php://temp', 'w+b');
        fputcsv($stream, $headers, ',', '"', '');
        fputcsv($stream, $values, ',', '"', '');
        rewind($stream);
        $file = UploadedFile::fake()->createWithContent('responses.csv', stream_get_contents($stream));
        fclose($stream);
        $service = app(CsvImportService::class);
        $import = $service->storePending($webinar, $form, $file);
        $this->assertSame($headers, $import->column_map['headers']);
        $counts = $service->process($import, $import->column_map['map'], options: $this->importOptions(['answer_map' => ['field_'.$field->id => 2]]));
        $this->assertSame(1, $counts['valid']);
        $submission = $form->submissions()->with('answers', 'participant')->sole();
        $this->assertSame($values, array_column($submission->imported_data['original'], 'value'));
        $responses = app(FormResponseService::class);
        $columns = $responses->columns($form->fresh());
        $this->assertSame(['5'], $responses->values($submission, $columns)['field_'.$field->id]);
        $this->assertSame([' 5 '], $responses->displayValues($submission, $columns)['field_'.$field->id]);
        $identity = $responses->sourceIdentity($submission);
        $this->assertSame($values[0], $identity['email']);
        $this->assertSame($values[1], $identity['name']);
        $this->assertSame($values[4], $identity['submitted_at']);
        $html = $this->get(route('admin.responses.index', $webinar))->assertOk()->getContent();
        $this->assertStringContainsString(e($values[0]), $html);
        $this->assertStringContainsString(e($values[1]), $html);
        $this->assertStringContainsString(' 5 ', $html);
        $csv = $this->get(route('admin.responses.export', $webinar))->assertOk()->streamedContent();
        $this->assertStringContainsString($values[0], $csv);
        $this->assertStringContainsString($values[1], $csv);
        $summary = $responses->summary($form->fresh(), $columns);
        $this->assertSame(['5' => 1], $summary['questions']['field_'.$field->id]['counts']);
    }

    public function test_excel_padded_identifiers_are_not_silently_changed(): void
    {
        $csv = app(ResponseFileReader::class)->contents($this->xlsx([
            ['Email', 'Name', 'Contact'], ['person@example.test', 'Person Example', 12345],
        ], [2 => (new Style)->setFormat('00000000000')]));
        $this->assertStringContainsString('00000012345', $csv);
    }

    private function importOptions(array $extra = []): array
    {
        return $extra + ['allow_import_time' => true, 'source_description' => 'Synthetic source fidelity fixture', 'source_confirmed' => true];
    }

    private function xlsx(array $rows, array $styles): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'source-fidelity-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            foreach ($rows as $index => $row) {
                $writer->addRow(Row::fromValuesWithStyles($row, null, $index === 0 ? [] : $styles));
            }
            $writer->close();

            return UploadedFile::fake()->createWithContent('responses.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
