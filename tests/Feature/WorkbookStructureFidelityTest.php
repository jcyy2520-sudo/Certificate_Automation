<?php

namespace Tests\Feature;

use App\Services\ResponseFileReader;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

/** Native, synthetic OOXML cases that spreadsheet writers often normalize away. */
class WorkbookStructureFidelityTest extends TestCase
{
    #[DataProvider('unsupportedFormulas')]
    public function test_formulas_that_cannot_be_preserved_literally_are_rejected(string $formula): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8 CSV');
        $this->decode('<row r="2"><c r="A2">'.$formula.'<v>7</v></c></row>');
    }

    public static function unsupportedFormulas(): array
    {
        return [
            'shared master' => ['<f t="shared" ref="A2:A3" si="0">B2+1</f>'],
            'shared follower' => ['<f t="shared" si="0"/>'],
            'array result range' => ['<f t="array" ref="A2:A3">B2:B3+1</f>'],
            'data table' => ['<f t="dataTable" ref="A2:A3" r1="B2"/>'],
            'empty formula' => ['<f/>'],
        ];
    }

    public function test_normal_formulas_remain_literal_even_when_the_cached_result_is_an_error(): void
    {
        $this->assertSame([['Value'], ['=B2+1'], ['=1/0']], $this->decode(
            '<row r="2"><c r="A2"><f t="normal">B2+1</f><v>999</v></c></row>'.
            '<row r="3"><c r="A3" t="e"><f>1/0</f><v>#DIV/0!</v></c></row>'
        ));
    }

    public function test_phonetic_annotations_are_excluded_from_inline_and_shared_display_text(): void
    {
        $expected = '  漢字 _x000D_  ';
        $text = '<r><t xml:space="preserve">  漢</t></r><r><t xml:space="preserve">字 _x005F_x000D_  </t></r>'.
            '<rPh sb="0" eb="2"><t>かんじ</t></rPh><phoneticPr fontId="0"/>';
        $this->assertSame([['Value'], [$expected], [$expected]], $this->decode(
            '<row r="2"><c r="A2" t="inlineStr"><is>'.$text.'</is></c></row>'.
            '<row r="3"><c r="A3" t="s"><v>0</v></c></row>',
            sharedStrings: '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1"><si>'.$text.'</si></sst>'
        ));
    }

    #[DataProvider('inheritedNumberFormats')]
    public function test_numeric_cells_use_their_effective_row_column_or_cell_format(string $rowStyle, string $cellStyle, string $expected): void
    {
        $this->assertSame([['Value'], [$expected]], $this->decode(
            '<row r="2"'.$rowStyle.'><c r="A2"'.$cellStyle.'><v>12</v></c></row>',
            columns: '<cols><col min="1" max="1" style="1" width="15" customWidth="1"/></cols>'
        ));
    }

    public static function inheritedNumberFormats(): array
    {
        return [
            'column default' => ['', '', '000012'],
            'row overrides column' => [' s="2" customFormat="1"', '', '12.00'],
            'cell overrides row' => [' s="2" customFormat="1"', ' s="1"', '000012'],
            'explicit general overrides row and column' => [' s="2" customFormat="1"', ' s="0"', '12'],
            'explicit general row overrides column' => [' s="0" customFormat="1"', '', '12'],
        ];
    }

    #[DataProvider('invalidPositions')]
    public function test_ambiguous_or_invalid_row_and_cell_positions_are_rejected(string $rows): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8 CSV');
        $this->decode($rows);
    }

    public static function invalidPositions(): array
    {
        return [
            'repeated row' => ['<row r="2"><c r="A2"><v>12</v></c></row><row r="2"><c r="A2"><v>13</v></c></row>'],
            'descending row' => ['<row r="3"><c r="A3"><v>12</v></c></row><row r="2"><c r="A2"><v>13</v></c></row>'],
            'malformed row' => ['<row r="2x"><c r="A2"><v>12</v></c></row>'],
            'zero row' => ['<row r="0"><c r="A0"><v>12</v></c></row>'],
            'repeated cell' => ['<row r="2"><c r="A2"><v>12</v></c><c r="A2"><v>13</v></c></row>'],
            'descending cell' => ['<row r="2"><c r="B2"><v>12</v></c><c r="A2"><v>13</v></c></row>'],
            'cell belongs to another row' => ['<row r="2"><c r="A3"><v>12</v></c></row>'],
            'malformed cell' => ['<row r="2"><c r="A-2"><v>12</v></c></row>'],
            'cell outside a row' => ['<c r="B1"><v>12</v></c>'],
        ];
    }

    public function test_omitted_coordinates_continue_sequentially_without_losing_numeric_formats(): void
    {
        $this->assertSame([['Value'], ['000012'], ['000013']], $this->decode(
            '<row><c s="1"><v>12</v></c></row><row><c s="1"><v>13</v></c></row>'
        ));
    }

    public function test_literal_excel_errors_are_preserved_instead_of_becoming_empty_cells(): void
    {
        $this->assertSame([['Value'], ['#N/A'], ['#DIV/0!']], $this->decode(
            '<row r="2"><c r="A2" t="e"><v>#N/A</v></c></row>'.
            '<row r="3"><c r="A3" t="e"><v>#DIV/0!</v></c></row>'
        ));
    }

    #[DataProvider('invalidCellTypes')]
    public function test_unsupported_or_invalid_cell_types_are_rejected_instead_of_becoming_blank(string $cell): void
    {
        $this->expectException(RuntimeException::class);
        $this->decode('<row r="2">'.$cell.'</row>');
    }

    public static function invalidCellTypes(): array
    {
        return [
            'unknown type' => ['<c r="A2" t="unknown"><v>answer</v></c>'],
            'invalid date' => ['<c r="A2" t="d"><v>not-a-date</v></c>'],
            'normalized invalid calendar date' => ['<c r="A2" t="d"><v>2026-02-30T12:00:00</v></c>'],
            'date precision beyond microseconds' => ['<c r="A2" t="d"><v>2026-10-26T12:00:00.1234567</v></c>'],
            'invalid style' => ['<c r="A2" s="bad"><v>12</v></c>'],
        ];
    }

    #[DataProvider('invalidSharedStringIndexes')]
    public function test_shared_string_indexes_cannot_be_cast_to_a_different_source_cell(string $index): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8 CSV');
        $this->decode('<row r="2"><c r="A2" t="s"><v>'.$index.'</v></c></row>', sharedStrings: '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="1" uniqueCount="1"><si><t>identity@example.test</t></si></sst>');
    }

    public static function invalidSharedStringIndexes(): array
    {
        return [
            'arbitrary text' => ['garbage'], 'numeric prefix' => ['0garbage'], 'negative' => ['-1'],
            'fraction' => ['0.5'], 'leading whitespace' => [' 0'], 'trailing whitespace' => ['0 '],
            'explicit positive sign' => ['+0'], 'out of range' => ['1'],
            'integer overflow' => ['999999999999999999999999999999999999'], 'empty' => [''],
        ];
    }

    public function test_valid_shared_string_indexes_preserve_the_selected_text(): void
    {
        $this->assertSame([['Value'], ['first'], ['second']], $this->decode(
            '<row r="2"><c r="A2" t="s"><v>0</v></c></row><row r="3"><c r="A3" t="s"><v>1</v></c></row>',
            sharedStrings: '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" count="2" uniqueCount="2"><si><t>first</t></si><si><t>second</t></si></sst>'
        ));
    }

    #[DataProvider('invalidBooleans')]
    public function test_boolean_cells_are_not_guessed_from_other_nonempty_values(string $value): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8 CSV');
        $this->decode('<row r="2"><c r="A2" t="b"><v>'.$value.'</v></c></row>');
    }

    public static function invalidBooleans(): array
    {
        return [
            'arbitrary text' => ['garbage'], 'literal false' => ['false'], 'literal true' => ['true'],
            'other integer' => ['2'], 'negative' => ['-1'], 'decimal' => ['0.0'],
            'leading whitespace' => [' 0'], 'trailing whitespace' => ['1 '], 'empty' => [''],
        ];
    }

    public function test_ooxml_numeric_booleans_preserve_true_and_false(): void
    {
        $this->assertSame([['Value'], ['FALSE'], ['TRUE']], $this->decode(
            '<row r="2"><c r="A2" t="b"><v>0</v></c></row><row r="3"><c r="A3" t="b"><v>1</v></c></row>'
        ));
    }

    #[DataProvider('offsetBearingDates')]
    public function test_timezone_bearing_date_cells_are_not_flattened_to_local_wall_time(string $date): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8 CSV');
        $this->decode('<row r="2"><c r="A2" t="d"><v>'.$date.'</v></c></row>');
    }

    public static function offsetBearingDates(): array
    {
        return [
            'UTC' => ['2026-10-26T12:00:00Z'],
            'positive offset' => ['2026-10-26T12:00:00.123456+08:00'],
            'negative offset' => ['2026-10-26T12:00:00-05:30'],
        ];
    }

    public function test_timezone_free_date_cells_retain_local_time_and_supported_precision(): void
    {
        $this->assertSame([['Value'], ['2026-10-26 12:00:00.123456']], $this->decode(
            '<row r="2"><c r="A2" t="d"><v>2026-10-26T12:00:00.123456</v></c></row>'
        ));
    }

    public function test_unresolved_named_style_number_formats_are_rejected_instead_of_discarded(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('UTF-8 CSV');
        $this->decode('<row r="2"><c r="A2" s="1"><v>12</v></c></row>', styles: '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="000000"/></numFmts><fonts count="1"><font/></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="2"><xf numFmtId="0"/><xf numFmtId="164" applyNumberFormat="1"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" xfId="0"/><xf numFmtId="0" xfId="1" applyNumberFormat="0"/></cellXfs></styleSheet>');
    }

    private function decode(string $rows, string $columns = '', string $sharedStrings = '', string $styles = ''): array
    {
        $headers = '<row r="1"><c r="A1" t="inlineStr"><is><t>Value</t></is></c></row>';
        $parts = [
            '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
            '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
            'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Responses" sheetId="1" r:id="rId1"/></sheets></workbook>',
            'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'.($sharedStrings === '' ? '' : '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>').'</Relationships>',
            'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="2"><numFmt numFmtId="164" formatCode="000000"/><numFmt numFmtId="165" formatCode="0.00"/></numFmts><fonts count="1"><font/></fonts><fills count="1"><fill><patternFill patternType="none"/></fill></fills><borders count="1"><border/></borders><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="164" fontId="0" fillId="0" borderId="0"/><xf numFmtId="165" fontId="0" fillId="0" borderId="0"/></cellXfs></styleSheet>',
            'xl/worksheets/sheet1.xml' => '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'.$columns.'<sheetData>'.$headers.$rows.'</sheetData></worksheet>',
        ];
        if ($sharedStrings !== '') {
            $parts['xl/sharedStrings.xml'] = $sharedStrings;
        }
        if ($styles !== '') {
            $parts['xl/styles.xml'] = $styles;
        }
        $path = tempnam(sys_get_temp_dir(), 'workbook-review-');
        try {
            $zip = new ZipArchive;
            $this->assertTrue($zip->open($path, ZipArchive::OVERWRITE) === true);
            foreach ($parts as $name => $xml) {
                $this->assertTrue($zip->addFromString($name, $xml));
            }
            $this->assertTrue($zip->close());
            $file = UploadedFile::fake()->createWithContent('synthetic.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
        $contents = app(ResponseFileReader::class)->contents($file);
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $decoded = [];
            while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                $decoded[] = $row;
            }

            return $decoded;
        } finally {
            fclose($stream);
        }
    }
}
