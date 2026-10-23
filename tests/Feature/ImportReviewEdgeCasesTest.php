<?php

namespace Tests\Feature;

use App\Services\ResponseFileReader;
use App\Services\XlsxNumberFormats;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Tests\TestCase;
use ZipArchive;

class ImportReviewEdgeCasesTest extends TestCase
{
    private function workbook(?callable $mutate = null, int $score = 8): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'import-review-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(Row::fromValues(['Email', 'Score']));
            $writer->addRow(Row::fromValuesWithStyles(['person@example.test', $score], null, [1 => (new Style)->setFormat('0" / 10"')]));
            $writer->close();
            if ($mutate !== null) {
                $archive = new ZipArchive;
                $archive->open($path);
                try {
                    $mutate($archive);
                } finally {
                    $archive->close();
                }
            }

            return UploadedFile::fake()->createWithContent('review.xlsx', file_get_contents($path));
        } finally {
            unlink($path);
        }
    }

    public function test_the_related_styles_part_preserves_a_formatted_score_denominator(): void
    {
        $file = $this->workbook(function (ZipArchive $archive): void {
            $archive->addFromString('xl/response-styles.xml', $archive->getFromName('xl/styles.xml'));
            $archive->deleteName('xl/styles.xml');
            foreach (['xl/_rels/workbook.xml.rels', '[Content_Types].xml'] as $entry) {
                $archive->addFromString($entry, str_replace('styles.xml', 'response-styles.xml', $archive->getFromName($entry)));
            }
        });
        $csv = app(ResponseFileReader::class)->contents($file);
        $this->assertStringContainsString('8 / 10', $csv);
    }

    public function test_a_strict_ooxml_sheet_relationship_namespace_keeps_the_score_format(): void
    {
        $file = $this->workbook(function (ZipArchive $archive): void {
            $archive->addFromString('xl/workbook.xml', str_replace(
                'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
                'http://purl.oclc.org/ooxml/officeDocument/relationships',
                $archive->getFromName('xl/workbook.xml'),
            ));
        });
        $this->assertStringContainsString('8 / 10', app(ResponseFileReader::class)->contents($file));
    }

    public function test_a_tiny_nonzero_number_is_never_silently_rounded_to_zero(): void
    {
        $this->expectException(RuntimeException::class);
        app(XlsxNumberFormats::class)->display(['value' => '0.00000001', 'format' => '0']);
    }

    public function test_a_zero_quiz_score_keeps_its_displayed_denominator(): void
    {
        $this->assertStringContainsString('0 / 10', app(ResponseFileReader::class)->contents($this->workbook(score: 0)));
    }

    public function test_an_implicit_cell_coordinate_keeps_its_score_format(): void
    {
        $file = $this->workbook(function (ZipArchive $archive): void {
            $archive->addFromString('xl/worksheets/sheet1.xml', str_replace(' r="B2"', '', $archive->getFromName('xl/worksheets/sheet1.xml')));
        });
        $this->assertStringContainsString('8 / 10', app(ResponseFileReader::class)->contents($file));
    }

    public function test_an_invalid_styles_document_is_rejected_with_a_safe_import_error(): void
    {
        $file = $this->workbook(function (ZipArchive $archive): void {
            $archive->addFromString('xl/styles.xml', '<styleSheet><broken>');
        });
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('workbook');
        app(ResponseFileReader::class)->contents($file);
    }

    public function test_the_numeric_prepass_rejects_out_of_bounds_columns_in_relocated_sheets(): void
    {
        $file = $this->workbook(function (ZipArchive $archive): void {
            $sheet = $archive->getFromName('xl/worksheets/sheet1.xml');
            $archive->addFromString('xl/response-data.xml', str_replace('r="B2"', 'r="GS2"', $sheet));
            $archive->deleteName('xl/worksheets/sheet1.xml');
            foreach (['xl/_rels/workbook.xml.rels', '[Content_Types].xml'] as $entry) {
                $archive->addFromString($entry, str_replace('worksheets/sheet1.xml', 'response-data.xml', $archive->getFromName($entry)));
            }
        });
        $archive = new ZipArchive;
        $archive->open($file->getRealPath());
        try {
            $this->expectException(RuntimeException::class);
            app(XlsxNumberFormats::class)->cells($archive);
        } finally {
            $archive->close();
        }
    }
}
