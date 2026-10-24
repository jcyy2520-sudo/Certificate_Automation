<?php

namespace Tests\Feature;

use App\Models\Form;
use App\Models\Import;
use App\Models\User;
use App\Models\Webinar;
use App\Services\CsvImportService;
use App\Services\FormResponseService;
use App\Services\ImportedResponseSchema;
use App\Services\ResponseFileReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class AdaptiveSpreadsheetFidelityTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
        Storage::fake('local');
        Http::preventStrayRequests();
        Mail::fake();
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($owner);
        $this->webinar = Webinar::factory()->create(['created_by' => $owner->id, 'timezone' => 'Asia/Manila']);
        foreach (FormResponseService::TYPES as $type => $title) {
            $this->webinar->forms()->create(['type' => $type, 'title' => $title]);
        }
    }

    public function test_csv_originals_tables_and_export_preserve_unicode_whitespace_and_falsey_text(): void
    {
        $headers = [' Email ', ' Name ', "Cafe\u{0301} / Caffè ☕", "Line\nquestion", 'Empty', 'Zero', 'False', 'Code'];
        $values = [' Mixed.Case@Example.test ', "  Ma\u{0301}ria 李 👩🏽‍🔬  ", "e\u{0301} ≠ é; 中文; العربية", "first\r\nsecond\n\tthird  ", '', '0', 'FALSE', '000123'];
        $form = $this->form();
        $service = app(CsvImportService::class);
        $import = $service->storePending($this->webinar, $form, $this->csv([$headers, $values]));
        $this->assertSame($headers, $import->column_map['headers']);
        $this->assertSame($values, $service->previewRows($import)[0]['cells']);
        $this->commit($import);
        $submission = $form->submissions()->with('participant', 'answers')->sole();
        $this->assertSame($values, array_column($submission->imported_data['original'], 'value'));
        $this->assertSame('mixed.case@example.test', $submission->participant->email_normalized);
        $this->assertSame("Ma\u{0301}ria 李 👩🏽‍🔬", $submission->participant->full_name);
        $responses = app(FormResponseService::class);
        $columns = $responses->columns($form->fresh());
        $this->assertSame(array_slice($headers, 2), array_column($columns, 'label'));
        $this->assertSame(array_slice($values, 2), array_map(fn ($v) => $v[0], array_values($responses->displayValues($submission, $columns))));
        $html = $this->get(route('admin.responses.index', $this->webinar))->assertOk()->getContent();
        foreach ([$values[0], $values[1], $values[2], $values[3], $values[5], $values[6], $values[7]] as $value) {
            $this->assertStringContainsString(e($value), $html);
        }
        $summary = $responses->summary($form->fresh(), $columns);
        foreach (['Zero', 'False', 'Code'] as $header) {
            $this->assertSame(1, $summary['questions'][ImportedResponseSchema::extraKey($header)]['answered']);
        }
        $this->assertSame(0, $summary['questions'][ImportedResponseSchema::extraKey('Empty')]['answered']);
        $export = $this->exportRows();
        $this->assertSame($values[1], $export[1][0]);
        $this->assertSame($values[0], $export[1][1]);
        $this->assertSame(array_slice($values, 2), array_slice($export[1], 6, 6));
        $this->assertSame($submission->imported_data['original'], json_decode($export[1][12], true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_native_ooxml_strings_booleans_sparse_cells_and_literal_escapes_are_exact(): void
    {
        $headers = ['Email', 'Name', 'Rich text', 'Literal escapes', 'Boolean false', 'Boolean true', 'Numeric zero', 'Empty middle', 'After empty', 'Trailing empty'];
        $expected = ['xlsx@example.test', 'Xlsx Person', "  A\u{0301}李 👨‍👩‍👧‍👦\nZ  ", 'Literal _x000D_ and _x0041_', 'FALSE', 'TRUE', '0', '', 'end', ''];
        $file = $this->xlsx([$headers, [
            $expected[0], $expected[1], ['rich' => ["  A\u{0301}", "李 👨‍👩‍👧‍👦\nZ  "]], $expected[3],
            ['boolean' => false], ['boolean' => true], ['number' => '0'], null, 'end',
        ]]);
        $decoded = $this->readCsv(app(ResponseFileReader::class)->contents($file));
        $this->assertSame([$headers, $expected], $decoded);
        $import = app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file);
        $this->commit($import);
        $submission = $this->form()->submissions()->sole();
        $this->assertSame($expected, array_column($submission->imported_data['original'], 'value'));
        $this->assertSame(array_slice($expected, 2), array_slice($this->exportRows()[1], 6, 8));
    }

    public function test_csv_quotes_backslashes_and_original_json_roundtrip_through_standard_csv(): void
    {
        $headers = ['Email', 'Name', 'Quoted "label", C:\\work\\"folder"'];
        $values = ['quotes@example.test', 'Quote Person', 'He said "hello", then C:\\work\\"draft"; trailing slash \\'];
        $this->commit(app(CsvImportService::class)->storePending($this->webinar, $this->form(), $this->csv([$headers, $values])));
        $submission = $this->form()->submissions()->sole();
        $this->assertSame($values, array_column($submission->imported_data['original'], 'value'));
        $export = $this->exportRows();
        $this->assertCount(8, $export[0]);
        $this->assertCount(8, $export[1]);
        $this->assertSame($headers[2], $export[0][6]);
        $this->assertSame($values[2], $export[1][6]);
        $this->assertSame($values, array_column(json_decode($export[1][7], true, flags: JSON_THROW_ON_ERROR), 'value'));
    }

    public function test_xlsx_numeric_display_uses_original_decimal_tokens_and_declared_formats(): void
    {
        $file = $this->xlsx([
            ['Email', 'Name', 'Padded ID', 'Percentage', 'Decimal', 'Large identifier', 'Small number', 'Grouped number'],
            ['numbers@example.test', 'Number Person', ['number' => '123', 'style' => 1], ['number' => '0.875', 'style' => 2],
                ['number' => '12.300', 'style' => 3], ['number' => '123456789012345678901'], ['number' => '1.25E-7'], ['number' => '1234567', 'style' => 4]],
        ], ['00000000', '0.00%', '0.00', '#,##0']);
        $expected = ['numbers@example.test', 'Number Person', '00000123', '87.50%', '12.30', '123456789012345678901', '0.000000125', '1,234,567'];
        $this->assertSame($expected, $this->readCsv(app(ResponseFileReader::class)->contents($file))[1]);
        $import = app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file);
        $this->commit($import);
        $this->assertSame($expected, array_column($this->form()->submissions()->sole()->imported_data['original'], 'value'));
    }

    #[DataProvider('schemaVariationProfiles')]
    public function test_deterministic_workbook_schemas_preserve_every_source_cell_at_supported_widths(int $width, int $emailIndex, int $nameIndex, bool $recognizedIdentity): void
    {
        $labelSamples = ['自由回答', 'السؤال', 'पसंदीदा विषय', 'คำถาม', "Café / Cafe\u{0301}", "  Multiple\nlines\t  "];
        $valueSamples = [str_repeat('Ax_19漢', 180), "  first line\n\tsecond line  ", '0', '', " \t  ", 'FALSE', '0000123', "e\u{0301} ≠ é; 李 👩🏽‍🔬", 'Literal _x000D_ / _x005F_', 'He said "hello", path C:\\work\\"draft"'];
        $headers = [];
        $expectedRows = [];
        $questionIndexes = [];
        for ($index = 0; $index < $width; $index++) {
            $headers[] = '欄 '.$index.' / '.$labelSamples[$index % count($labelSamples)];
            if (! in_array($index, [$emailIndex, $nameIndex], true)) {
                $questionIndexes[] = $index;
            }
        }
        // Deliberately exceed ordinary table-label lengths while staying below the source limit.
        $headers[$questionIndexes[array_key_last($questionIndexes)]] = 'Long label '.$width.' '.str_repeat('問', 1450);
        $headers[$emailIndex] = $recognizedIdentity ? 'Email' : '聯絡資料代碼';
        $headers[$nameIndex] = $recognizedIdentity ? 'Name' : '參加者資料代碼';
        for ($person = 0; $person < 2; $person++) {
            $row = [];
            for ($index = 0; $index < $width; $index++) {
                $row[] = $valueSamples[($index + $person) % count($valueSamples)];
            }
            $row[$emailIndex] = '  Schema.'.$width.'.'.$person.'@Example.test  ';
            $row[$nameIndex] = '  Schema Person '.$width.' Row '.$person.'  ';
            $expectedRows[] = $row;
        }
        $file = $this->xlsx([$headers, ...$expectedRows]);
        $this->assertSame([$headers, ...$expectedRows], $this->readCsv(app(ResponseFileReader::class)->contents($file)));
        $service = app(CsvImportService::class);
        $form = $this->form();
        $import = $service->storePending($this->webinar, $form, $file);
        $this->assertSame($headers, $import->column_map['headers']);
        $this->assertSame($width, $import->column_map['analysis']['column_count']);
        $this->assertSame(2, $import->column_map['analysis']['rows']);
        if ($recognizedIdentity) {
            $this->assertSame($emailIndex, $import->column_map['map']['email']);
            $this->assertSame($nameIndex, $import->column_map['map']['full_name']);
        } else {
            $this->assertSame([], $import->column_map['map'], 'Custom labels must never cause an identity guess.');
        }
        $reviewedMap = ['email' => $emailIndex, 'full_name' => $nameIndex];
        $this->assertSame(2, $service->process($import, $reviewedMap, options: $this->importOptions(), dryRun: true)['valid']);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
        $this->assertSame(Import::STATUS_PENDING, $import->fresh()->status);
        $counts = $service->process($import->fresh(), $reviewedMap, options: $this->importOptions());
        $this->assertSame(['total' => 2, 'valid' => 2, 'invalid' => 0, 'duplicate' => 0, 'unmatched' => 0], $counts);
        $responses = app(FormResponseService::class);
        $columns = $responses->columns($form->fresh());
        $expectedQuestionHeaders = array_map(fn ($index) => $headers[$index], $questionIndexes);
        $this->assertSame($expectedQuestionHeaders, array_column($columns, 'label'));
        $export = $this->exportRows();
        $this->assertCount($width + 5, $export[0]);
        $this->assertSame($expectedQuestionHeaders, array_slice($export[0], 6, $width - 2));
        $exportedByEmail = array_column(array_slice($export, 1), null, 1);
        $stored = $form->submissions()->with('participant', 'answers')->orderBy('id')->get();
        $this->assertCount(2, $stored);
        foreach ($expectedRows as $person => $expected) {
            $submission = $stored[$person];
            $original = $submission->imported_data['original'];
            $this->assertCount($width, $original);
            $display = $responses->displayValues($submission, $columns);
            $expectedAnswers = array_map(fn ($index) => [$expected[$index]], $questionIndexes);
            $this->assertSame($expectedAnswers, array_values($display));
            $identity = $responses->sourceIdentity($submission);
            $this->assertSame($expected[$emailIndex], $identity['email']);
            $this->assertSame($expected[$nameIndex], $identity['name']);
            $this->assertSame(strtolower(trim($expected[$emailIndex])), $submission->participant->email_normalized);
            $this->assertSame(trim($expected[$nameIndex]), $submission->participant->full_name);
            $exported = $exportedByEmail[$expected[$emailIndex]];
            $this->assertCount($width + 5, $exported);
            $this->assertSame($expected[$nameIndex], $exported[0]);
            $this->assertSame($expected[$emailIndex], $exported[1]);
            $this->assertSame(array_map(fn ($index) => $expected[$index], $questionIndexes), array_slice($exported, 6, $width - 2));
            $exportedOriginal = json_decode($exported[$width + 4], true, flags: JSON_THROW_ON_ERROR);
            foreach ($headers as $index => $label) {
                $this->assertSame(['label' => $label, 'value' => $expected[$index]], $original[$index], 'Stored source cell '.$index.' / person '.$person);
                $this->assertSame(['label' => $label, 'value' => $expected[$index]], $exportedOriginal[$index], 'Exported source cell '.$index.' / person '.$person);
            }
        }
        $this->assertSame(0, $form->fields()->count());
        $this->assertSame(0, $form->questions()->count());
        $this->assertDatabaseCount('participants', 2);
    }

    public static function schemaVariationProfiles(): array
    {
        return [
            '3 columns - identity at edges' => [3, 0, 2, true],
            '13 columns - custom identity' => [13, 11, 0, false],
            '30 columns - separated identity' => [30, 7, 23, true],
            '80 columns - custom identity at end' => [80, 79, 39, false],
            '200 columns - custom identity in middle' => [200, 98, 1, false],
        ];
    }

    public function test_xlsx_fraction_score_and_excel_local_date_remain_auditable_after_import(): void
    {
        $form = $this->form('pretest');
        $this->webinar->participants()->create(['email' => 'quiz@example.test', 'full_name' => 'Quiz Person', 'verified_at' => now()]);
        $file = $this->xlsx([
            ['Timestamp', 'Score', 'Email', 'Question'],
            [['number' => '45000.5', 'style' => 1], ['number' => '8', 'style' => 2], 'quiz@example.test', '0'],
        ], ['yyyy-mm-dd hh:mm:ss', '0" / 10"']);
        $expected = ['2023-03-15 12:00:00', '8 / 10', 'quiz@example.test', '0'];
        $this->assertSame($expected, $this->readCsv(app(ResponseFileReader::class)->contents($file))[1]);
        $import = app(CsvImportService::class)->storePending($this->webinar, $form, $file);
        $this->commit($import);
        $submission = $form->submissions()->sole();
        $this->assertSame('8.00', $submission->score);
        $this->assertSame('10.00', $submission->maximum_score);
        $this->assertSame('2023-03-15 04:00:00', $submission->submitted_at->format('Y-m-d H:i:s'));
        $this->assertSame($expected, array_column($submission->imported_data['original'], 'value'));
        $this->get(route('admin.responses.index', [$this->webinar, 'type' => 'pretest']))->assertOk()->assertSee('Source score: 8 / 10')->assertSee('Source time: 2023-03-15 12:00:00');
    }

    public function test_excel_1904_calendar_is_respected(): void
    {
        $file = $this->xlsx([['Email', 'Name', 'Local date'], ['calendar@example.test', 'Calendar Person', ['number' => '1.5', 'style' => 1]]], ['yyyy-mm-dd hh:mm:ss'], date1904: true);
        $this->assertSame('1904-01-02 12:00:00', $this->readCsv(app(ResponseFileReader::class)->contents($file))[1][2]);
    }

    public function test_ooxml_string_value_cells_keep_their_surrounding_whitespace(): void
    {
        $expected = "  exact\ntext  ";
        $file = $this->xlsx([['Email', 'Name', 'String value'], ['string@example.test', 'String Person', ['string_value' => $expected]]]);
        $this->assertSame($expected, $this->readCsv(app(ResponseFileReader::class)->contents($file))[1][2]);
    }

    #[DataProvider('stringValueEdgeCases')]
    public function test_ooxml_string_value_padding_and_literal_escape_edges_remain_exact(string $expected): void
    {
        $file = $this->xlsx([['Email', 'Name', 'Edge text'], ['edge@example.test', 'Edge Person', ['string_value' => $expected]]]);
        $this->assertSame($expected, $this->readCsv(app(ResponseFileReader::class)->contents($file))[1][2]);
        $this->commit(app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file));
        $this->assertSame($expected, $this->form()->submissions()->sole()->imported_data['original'][2]['value']);
        $this->assertSame($expected, $this->exportRows()[1][6]);
    }

    public static function stringValueEdgeCases(): array
    {
        return [
            'all whitespace' => [" \t\n  \t "],
            'literal escape padding' => ['  _x000D_ / _x005F_ / _x0020_  '],
            'Unicode padding' => ["\u{00A0}  李\u{0301}  \u{2003}"],
            'empty string' => [''],
        ];
    }

    public function test_numeric_subsecond_excel_dates_are_rejected_before_storage_without_discarding_precision(): void
    {
        $file = $this->xlsx([['Email', 'Name', 'Precise numeric timestamp'],
            ['numeric-date@example.test', 'Numeric Date Person', ['number' => '45000.50000144676', 'style' => 1]]], ['yyyy-mm-dd hh:mm:ss.000']);
        $rejected = false;
        try {
            app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file);
        } catch (RuntimeException $exception) {
            $rejected = preg_match('/fraction|precision|subsecond/i', $exception->getMessage()) === 1;
        }
        $this->assertTrue($rejected, 'Numeric Excel date milliseconds must be preserved or explicitly refused before persistence.');
        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_subsecond_excel_dates_are_preserved_or_explicitly_rejected_before_storage(): void
    {
        $file = $this->xlsx([['Email', 'Name', 'Precise timestamp'], ['precise@example.test', 'Precise Person', ['date' => '2026-10-20T14:30:00.125']]]);
        $decoded = null;
        $rejection = null;
        try {
            $decoded = app(ResponseFileReader::class)->contents($file);
        } catch (RuntimeException $exception) {
            $rejection = $exception;
        }
        if ($rejection !== null) {
            $this->assertMatchesRegularExpression('/precision|fraction|subsecond|unsupported/i', $rejection->getMessage());

            return;
        }
        $this->assertSame('2026-10-20 14:30:00.125', $this->readCsv($decoded)[1][2], 'A supported precise timestamp must not silently lose its fractional seconds.');
    }

    #[DataProvider('unsupportedNumericFormats')]
    public function test_unsupported_or_lossy_numeric_formats_are_rejected_before_persistence(string $number, string $format): void
    {
        $file = $this->xlsx([['Email', 'Name', 'Value'], ['unsupported@example.test', 'Unsupported Person', ['number' => $number, 'style' => 1]]], [$format]);
        $rejection = null;
        try {
            app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file);
        } catch (RuntimeException $exception) {
            $rejection = $exception;
        }
        $this->assertNotNull($rejection, 'A numeric display was accepted despite an unsupported or precision-losing format.');
        $this->assertMatchesRegularExpression('/unsupported Excel display format|lose precision/', $rejection->getMessage());
        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public static function unsupportedNumericFormats(): array
    {
        return ['rounded integer' => ['8.25', '0'], 'rounded decimal' => ['8.251', '0.00'], 'currency' => ['8', '$0.00'], 'conditional' => ['8', '[Red]0'], 'fraction' => ['0.5', '# ?/?']];
    }

    public function test_reordered_and_evolving_schemas_do_not_move_values_between_people_or_columns(): void
    {
        $firstHeaders = ['Email', 'Name', 'Café', 'Code', 'Old question'];
        $firstValues = ['first@example.test', 'First Person', 'composed answer', '001', 'old value'];
        $secondHeaders = ['New question', 'Code', 'Name', "Cafe\u{0301}", 'Email', 'Café'];
        $secondValues = ['new value', '0002', 'Second Person', 'decomposed answer', 'second@example.test', 'second composed'];
        foreach ([[$firstHeaders, $firstValues], [$secondHeaders, $secondValues]] as $rows) {
            $this->commit(app(CsvImportService::class)->storePending($this->webinar, $this->form(), $this->csv($rows)));
        }
        $responses = app(FormResponseService::class);
        $columns = $responses->columns($this->form());
        $this->assertSame(['Café', 'Code', 'Old question', 'New question', "Cafe\u{0301}"], array_column($columns, 'label'));
        $submissions = $this->form()->submissions()->with('answers')->orderBy('id')->get();
        $first = $responses->displayValues($submissions[0], $columns);
        $second = $responses->displayValues($submissions[1], $columns);
        $this->assertSame(['composed answer'], $first[ImportedResponseSchema::extraKey('Café')]);
        $this->assertArrayNotHasKey(ImportedResponseSchema::extraKey("Cafe\u{0301}"), $first);
        $this->assertSame(['decomposed answer'], $second[ImportedResponseSchema::extraKey("Cafe\u{0301}")]);
        $this->assertArrayNotHasKey(ImportedResponseSchema::extraKey('Old question'), $second);
        $export = $this->exportRows();
        $byEmail = array_column(array_slice($export, 1), null, 1);
        $this->assertSame(['composed answer', '001', 'old value', '', ''], array_slice($byEmail['first@example.test'], 6, 5));
        $this->assertSame(['second composed', '0002', '', 'new value', 'decomposed answer'], array_slice($byEmail['second@example.test'], 6, 5));
        $this->assertSame($firstValues, array_column(json_decode($byEmail['first@example.test'][11], true, flags: JSON_THROW_ON_ERROR), 'value'));
        $this->assertSame($secondValues, array_column(json_decode($byEmail['second@example.test'][11], true, flags: JSON_THROW_ON_ERROR), 'value'));
        $this->assertSame(0, $this->form()->fields()->count());
        $this->assertSame(0, $this->form()->questions()->count());
    }

    public function test_source_and_native_schema_snapshots_survive_a_later_question_rename(): void
    {
        $form = $this->form();
        $field = $form->fields()->create(['key' => 'comment', 'label' => 'Original question', 'field_type' => 'text']);
        $import = app(CsvImportService::class)->storePending($this->webinar, $form->fresh(), $this->csv([['Email', 'Name', 'Original question'], ['snapshot@example.test', 'Snapshot Person', "  exact\nanswer  "]]));
        $this->commit($import, ['answer_map' => ['field_'.$field->id => 2]]);
        $field->update(['label' => 'Later wording']);
        $submission = $form->submissions()->with('answers')->sole();
        $this->assertSame('Original question', $submission->imported_data['schema']['field_'.$field->id]['label']);
        $this->assertSame(["  exact\nanswer  "], app(FormResponseService::class)->displayValues($submission, app(FormResponseService::class)->columns($form->fresh()))['field_'.$field->id]);
        $this->get(route('admin.responses.show', [$this->webinar, $submission]))->assertOk()->assertSee('Original question')->assertSee("  exact\nanswer  ");
    }

    #[DataProvider('unidentifiableHeaders')]
    public function test_duplicate_or_blank_labels_are_rejected_without_persisting_an_import(array $headers): void
    {
        foreach ([$this->csv([$headers, array_fill(0, count($headers), 'value')]), $this->xlsx([$headers, array_fill(0, count($headers), 'value')])] as $file) {
            $rejection = null;
            try {
                app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file);
            } catch (RuntimeException $exception) {
                $rejection = $exception;
            }
            $this->assertNotNull($rejection, 'A duplicate or blank label was accepted: '.json_encode($headers));
            $this->assertMatchesRegularExpression('/no header|repeats the header/', $rejection->getMessage());
            $this->assertDatabaseCount('imports', 0);
            $this->assertDatabaseCount('participants', 0);
        }
    }

    public static function unidentifiableHeaders(): array
    {
        return [
            'empty' => [['Email', 'Name', '']],
            'ASCII whitespace' => [['Email', 'Name', " \t\n "]],
            'Unicode whitespace' => [['Email', 'Name', "\u{00A0}\u{2003}"]],
            'exact duplicate' => [['Email', 'Name', 'Question', 'Question']],
            'case and surrounding whitespace' => [['Email', 'Name', 'Question', ' question ']],
            'internal whitespace' => [['Email', 'Name', 'Question title', "Question\n\t title"]],
        ];
    }

    public function test_ambiguous_identity_and_native_labels_require_explicit_choices(): void
    {
        $form = $this->form();
        $a = $form->fields()->create(['key' => 'rating_a', 'label' => 'Rating', 'field_type' => 'text']);
        $b = $form->fields()->create(['key' => 'rating_b', 'label' => 'Rating', 'field_type' => 'text']);
        $headers = ['Personal email', 'Work email', 'Name', 'Rating', 'Content rating'];
        $values = ['personal@example.test', 'work@example.test', 'Explicit Person', 'Excellent', 'Good'];
        $import = app(CsvImportService::class)->storePending($this->webinar, $form->fresh(), $this->csv([$headers, $values]));
        $this->assertArrayNotHasKey('email', $import->column_map['map']);
        $this->assertSame([], $import->column_map['suggested_answers']);
        try {
            app(CsvImportService::class)->process($import, $import->column_map['map'], options: $this->importOptions());
            $this->fail('An ambiguous email was selected automatically.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Map the email', $exception->getMessage());
        }
        try {
            app(CsvImportService::class)->process($import, ['email' => 0, 'full_name' => 2], options: $this->importOptions(['answer_map' => ['field_'.$a->id => 3, 'field_'.$b->id => 3]]));
            $this->fail('A source answer was assigned to two different native fields.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Map each answer column once', $exception->getMessage());
        }
        $this->assertDatabaseCount('submissions', 0);
        $this->commit($import, ['answer_map' => ['field_'.$a->id => 3]], ['email' => 1, 'full_name' => 2]);
        $submission = $form->submissions()->with('participant')->sole();
        $this->assertSame('work@example.test', $submission->participant->email);
        $this->assertSame($values, array_column($submission->imported_data['original'], 'value'));
    }

    public function test_anonymous_evaluations_cannot_be_assigned_to_a_person_by_position_or_name(): void
    {
        $this->webinar->participants()->create(['email' => 'registered@example.test', 'full_name' => 'Registered Person', 'verified_at' => now()]);
        $form = $this->form('evaluation');
        $import = app(CsvImportService::class)->storePending($this->webinar, $form, $this->csv([['Rating', 'Comment'], ['5', 'Useful session']]));
        try {
            app(CsvImportService::class)->process($import, [], options: $this->importOptions(['required_columns' => [0]]));
            $this->fail('An anonymous evaluation was attributed to a participant.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Map the email', $exception->getMessage());
        }
        $this->assertDatabaseCount('participants', 1);
        $this->assertDatabaseCount('submissions', 0);
        $this->assertSame(Import::STATUS_PENDING, $import->fresh()->status);
    }

    public function test_short_and_long_csv_rows_are_held_with_original_cells_accounted_for(): void
    {
        $rows = [['Email', 'Name', 'Answer'], ['short@example.test', 'Short Person'], ['long@example.test', 'Long Person', 'answer', 'extra'], ['valid@example.test', 'Valid Person', '0']];
        $import = app(CsvImportService::class)->storePending($this->webinar, $this->form(), $this->csv($rows));
        $counts = app(CsvImportService::class)->process($import, $import->column_map['map'], options: $this->importOptions());
        $this->assertSame(['total' => 3, 'valid' => 1, 'invalid' => 2, 'duplicate' => 0, 'unmatched' => 0], $counts);
        $this->assertSame(2, $import->column_map['analysis']['irregular_rows']);
        $issues = $import->issues()->orderBy('row_number')->get();
        $this->assertSame([2, 3], $issues->pluck('row_number')->all());
        $this->assertSame('Short Person', $issues[0]->raw_data['Name']);
        $this->assertContains('extra', $issues[1]->raw_data);
        $this->assertSame('0', $this->form()->submissions()->sole()->imported_data['original'][2]['value']);
    }

    public function test_formula_cells_remain_literal_and_export_protects_them_without_changing_original_json(): void
    {
        $headers = ['Email', 'Name', '=Question', 'Literal trigger'];
        $values = ['formula@example.test', 'Formula Person', '=SUM(1,2)', " \t@literal"];
        $file = $this->xlsx([$headers, [$values[0], $values[1], ['formula' => 'SUM(1,2)', 'cached' => '999'], $values[3]]]);
        $this->assertSame($values, $this->readCsv(app(ResponseFileReader::class)->contents($file))[1]);
        $this->commit(app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file));
        $export = $this->exportRows();
        $this->assertSame("'=Question", $export[0][6]);
        $this->assertSame("'=SUM(1,2)", $export[1][6]);
        $this->assertSame("' \t@literal", $export[1][7]);
        $this->assertSame($values, array_column(json_decode($export[1][8], true, flags: JSON_THROW_ON_ERROR), 'value'));
    }

    public function test_overflow_cell_cannot_overwrite_a_real_header_in_a_held_malformed_row(): void
    {
        $headers = ['Email', 'Name', 'column 4'];
        $row = ['overflow@example.test', 'Overflow Person', 'first-value', 'overflow-value'];
        $import = app(CsvImportService::class)->storePending($this->webinar, $this->form(), $this->csv([$headers, $row]));
        $counts = app(CsvImportService::class)->process($import, $import->column_map['map'], options: $this->importOptions());
        $this->assertSame(['total' => 1, 'valid' => 0, 'invalid' => 1, 'duplicate' => 0, 'unmatched' => 0], $counts);
        $raw = $import->issues()->sole()->raw_data;
        $this->assertSame('overflow@example.test', $raw['Email']);
        $this->assertSame('Overflow Person', $raw['Name']);
        $this->assertSame('first-value', $raw['column 4']);
        $this->assertCount(4, $raw);
        $this->assertSame($row, array_values($raw));
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_overflow_fallback_avoids_declared_headers_and_existing_fallback_suffixes(): void
    {
        $headers = ['Email', 'Name', 'column 6', 'column 6 (extra 1)', 'Question'];
        $row = ['collision@example.test', 'Collision Person', 'declared value', 'declared suffix value', 'answer', 'overflow first', 'overflow second'];
        $import = app(CsvImportService::class)->storePending($this->webinar, $this->form(), $this->csv([$headers, $row]));
        $counts = app(CsvImportService::class)->process($import, $import->column_map['map'], options: $this->importOptions());
        $this->assertSame(1, $counts['invalid']);
        $raw = $import->issues()->sole()->raw_data;
        $this->assertCount(7, $raw);
        $this->assertSame($row, array_values($raw));
        $this->assertSame('declared value', $raw['column 6']);
        $this->assertSame('declared suffix value', $raw['column 6 (extra 1)']);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    #[DataProvider('workbookBudgetCases')]
    public function test_workbook_exceeding_the_exact_column_or_row_budget_is_rejected_before_storage(int $columns, int $responses): void
    {
        $headers = ['Email', 'Name', ...array_map(fn ($index) => 'Column '.$index, range(3, $columns))];
        $row = ['budget@example.test', 'Budget Person', ...array_fill(0, $columns - 2, '0')];
        $file = $this->xlsx([$headers, ...array_fill(0, $responses, $row)]);
        $rejection = null;
        try {
            app(CsvImportService::class)->storePending($this->webinar, $this->form(), $file);
        } catch (RuntimeException $exception) {
            $rejection = $exception;
        }
        $this->assertNotNull($rejection, 'A workbook beyond the supported intake budget was accepted.');
        $this->assertMatchesRegularExpression('/200 columns|5,000 (?:response )?rows/', $rejection->getMessage());
        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('participants', 0);
        $this->assertDatabaseCount('submissions', 0);
    }

    public static function workbookBudgetCases(): array
    {
        return ['201 columns' => [201, 1], '5001 response rows' => [3, 5001]];
    }

    private function form(string $type = 'registration'): Form
    {
        return $this->webinar->forms()->where('type', $type)->firstOrFail();
    }

    private function importOptions(array $extra = []): array
    {
        return $extra + ['allow_import_time' => true, 'source_description' => 'Synthetic independent fidelity matrix', 'source_confirmed' => true];
    }

    private function commit(Import $import, array $extra = [], ?array $map = null): void
    {
        $counts = app(CsvImportService::class)->process($import, $map ?? $import->column_map['map'], options: $this->importOptions($extra));
        $this->assertSame(1, $counts['valid'], json_encode($import->issues()->pluck('issue_message')));
        $this->assertSame(0, $counts['invalid']);
        $this->assertSame(0, $counts['duplicate']);
        $this->assertSame(0, $counts['unmatched']);
    }

    private function csv(array $rows): UploadedFile
    {
        $stream = fopen('php://temp', 'w+b');
        foreach ($rows as $row) {
            fputcsv($stream, $row, ',', '"', '');
        }
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        return UploadedFile::fake()->createWithContent('synthetic.csv', $contents);
    }

    private function readCsv(string $contents): array
    {
        $stream = fopen('php://temp', 'w+b');
        fwrite($stream, str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents);
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function exportRows(): array
    {
        return $this->readCsv($this->get(route('admin.responses.export', $this->webinar))->assertOk()->streamedContent());
    }

    /** Native OOXML fixtures avoid depending on a spreadsheet writer's own conversions. */
    private function xlsx(array $rows, array $formats = [], bool $date1904 = false): UploadedFile
    {
        $xml = fn (string $value): string => htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $text = fn (string $value): string => $xml(preg_replace('/_(x[0-9a-fA-F]{4}_)/', '_x005F_$1', $value));
        $data = '';
        foreach ($rows as $rowIndex => $row) {
            $rowNumber = $rowIndex + 1;
            $data .= '<row r="'.$rowNumber.'">';
            foreach ($row as $columnIndex => $cell) {
                if ($cell === null) {
                    continue;
                }
                $number = $columnIndex + 1;
                $letters = '';
                while ($number > 0) {
                    $number--;
                    $letters = chr(65 + $number % 26).$letters;
                    $number = intdiv($number, 26);
                }
                $reference = $letters.$rowNumber;
                if (is_array($cell) && array_key_exists('number', $cell)) {
                    $data .= '<c r="'.$reference.'" s="'.($cell['style'] ?? 0).'"><v>'.$xml($cell['number']).'</v></c>';
                } elseif (is_array($cell) && array_key_exists('boolean', $cell)) {
                    $data .= '<c r="'.$reference.'" t="b"><v>'.($cell['boolean'] ? '1' : '0').'</v></c>';
                } elseif (is_array($cell) && array_key_exists('formula', $cell)) {
                    $data .= '<c r="'.$reference.'"><f>'.$xml($cell['formula']).'</f><v>'.$xml($cell['cached']).'</v></c>';
                } elseif (is_array($cell) && array_key_exists('string_value', $cell)) {
                    $data .= '<c r="'.$reference.'" t="str"><v xml:space="preserve">'.$text($cell['string_value']).'</v></c>';
                } elseif (is_array($cell) && array_key_exists('date', $cell)) {
                    $data .= '<c r="'.$reference.'" t="d"><v>'.$xml($cell['date']).'</v></c>';
                } else {
                    $runs = is_array($cell) ? $cell['rich'] : [$cell];
                    $data .= '<c r="'.$reference.'" t="inlineStr"><is>';
                    foreach ($runs as $run) {
                        $data .= '<r><t xml:space="preserve">'.$text((string) $run).'</t></r>';
                    }
                    $data .= '</is></c>';
                }
            }
            $data .= '</row>';
        }
        $numberFormats = '';
        $styles = '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>';
        foreach ($formats as $index => $format) {
            $id = 164 + $index;
            $numberFormats .= '<numFmt numFmtId="'.$id.'" formatCode="'.$xml($format).'"/>';
            $styles .= '<xf numFmtId="'.$id.'" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>';
        }
        $parts = [
            '[Content_Types].xml' => '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
            '_rels/.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><workbookPr date1904="'.($date1904 ? '1' : '0').'"/><sheets><sheet name="Synthetic responses" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
            'xl/styles.xml' => '<?xml version="1.0"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="'.count($formats).'">'.$numberFormats.'</numFmts><fonts count="1"><font/></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellXfs count="'.(count($formats) + 1).'">'.$styles.'</cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'.$data.'</sheetData></worksheet>',
        ];
        $path = tempnam(sys_get_temp_dir(), 'adaptive-native-');
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
            foreach ($parts as $name => $contents) {
                $this->assertTrue($zip->addFromString($name, $contents));
            }
            $this->assertTrue($zip->close());

            return UploadedFile::fake()->createWithContent('synthetic.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }
}
