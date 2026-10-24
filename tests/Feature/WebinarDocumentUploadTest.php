<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Webinar;
use App\Models\WebinarDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;
use ZipArchive;

class WebinarDocumentUploadTest extends TestCase
{
    use RefreshDatabase;

    private Webinar $webinar;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        config(['webinar.document_disk' => 'local']);
        $owner = User::factory()->create(['role' => User::USER_ROLE]);
        $this->actingAs($owner);
        $this->webinar = Webinar::factory()->create(['created_by' => $owner->id]);
    }

    public function test_multi_sheet_excel_document_with_a_chart_is_downloaded_as_the_original_private_workbook(): void
    {
        $contents = $this->workbook();
        $this->post(route('admin.webinars.documents.store', $this->webinar), [
            'title' => 'Attendance workbook',
            'file' => UploadedFile::fake()->createWithContent('attendance.XLSX', $contents),
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.webinars.documents.index', $this->webinar));

        $document = WebinarDocument::query()->sole();
        $this->assertSame('xlsx', $document->extension);
        $this->assertSame(strlen($contents), $document->size);
        $this->assertSame($contents, Storage::disk('local')->get($document->file_path));
        $this->assertSame('local', $document->storage_disk);
        $this->assertStringStartsWith('webinar-documents/'.$this->webinar->id.'/', $document->file_path);
        $this->get(route('admin.webinars.documents.download', [$this->webinar, $document]))
            ->assertOk()
            ->assertDownload('document-'.$document->public_id.'.xlsx')
            ->assertHeader('content-type', 'application/octet-stream')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertHeader('content-security-policy')
            ->assertStreamedContent($contents);
        $this->assertDatabaseCount('imports', 0);
        $this->assertDatabaseCount('participants', 0);
    }

    public function test_an_excel_replacement_keeps_the_previous_document_private_and_downloads_the_new_bytes(): void
    {
        $previousContents = "%PDF-1.4\nSynthetic previous document";
        $this->post(route('admin.webinars.documents.store', $this->webinar), [
            'title' => 'Original', 'file' => UploadedFile::fake()->createWithContent('original.pdf', $previousContents),
        ])->assertSessionHasNoErrors();
        $previous = WebinarDocument::query()->sole();
        $contents = $this->workbook();
        $this->put(route('admin.webinars.documents.update', [$this->webinar, $previous]), [
            'title' => 'Replacement workbook', 'version' => 1,
            'file' => UploadedFile::fake()->createWithContent('replacement.xlsx', $contents),
        ])->assertSessionHasNoErrors();

        $replacement = WebinarDocument::query()->sole();
        $this->assertSame(2, $replacement->version);
        $this->assertSoftDeleted($previous);
        $this->assertSame($previousContents, Storage::disk('local')->get($previous->file_path));
        $this->assertSame('local', $replacement->storage_disk);
        $this->get(route('admin.webinars.documents.download', [$this->webinar, $previous]))->assertNotFound();
        $this->get(route('admin.webinars.documents.download', [$this->webinar, $replacement]))->assertOk()->assertStreamedContent($contents);
    }

    public function test_a_valid_workbook_classified_as_zip_by_the_upload_mime_guesser_is_still_verified_and_accepted(): void
    {
        $contents = $this->workbook();
        $file = UploadedFile::fake()->createWithContent('workbook.xlsx', $contents)->mimeType('application/zip');
        $this->post(route('admin.webinars.documents.store', $this->webinar), [
            'title' => 'ZIP-classified workbook', 'file' => $file,
        ])->assertSessionHasNoErrors();
        $document = WebinarDocument::query()->sole();
        $this->assertSame($contents, Storage::disk('local')->get($document->file_path));
    }

    #[DataProvider('unsafeWorkbookParts')]
    public function test_unsafe_excel_packages_are_rejected_before_private_storage(string $name, string $contents): void
    {
        $this->post(route('admin.webinars.documents.store', $this->webinar), [
            'title' => 'Unsafe workbook',
            'file' => UploadedFile::fake()->createWithContent('unsafe.xlsx', $this->workbook([$name => $contents])),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('webinar_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function unsafeWorkbookParts(): array
    {
        return [
            'VBA macro' => ['xl/vbaProject.bin', 'Synthetic forbidden macro'],
            'Excel 4 macro sheet' => ['xl/macrosheets/sheet1.xml', '<macrosheet/>'],
            'embedded document' => ['xl/embeddings/oleObject1.bin', 'Synthetic embedded object'],
            'Windows embedded document' => ['xl\\embeddings\\oleObject1.bin', 'Synthetic embedded object'],
            'ActiveX control' => ['xl/activeX/activeX1.bin', 'Synthetic active control'],
            'external workbook link' => ['xl/externalLinks/externalLink1.xml', '<externalLink/>'],
            'parent path' => ['xl/../outside.xml', '<unsafe/>'],
            'Windows parent path' => ['xl\\..\\outside.xml', '<unsafe/>'],
            'absolute path' => ['/outside.xml', '<unsafe/>'],
            'unsafe XML entity' => ['xl/custom.xml', '<!DOCTYPE workbook [<!ENTITY unsafe SYSTEM "file:///private">]><workbook>&unsafe;</workbook>'],
            'empty content types' => ['[Content_Types].xml', ''],
            'invalid content types' => ['[Content_Types].xml', '<Types>'],
            'empty main workbook' => ['xl/workbook.xml', ''],
            'wrong main document' => ['xl/workbook.xml', '<document/>'],
        ];
    }

    public function test_a_macro_enabled_workbook_renamed_to_xlsx_is_rejected_even_without_a_vba_entry(): void
    {
        $contents = $this->workbook(transformTypes: fn (string $types): string => str_replace(
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml',
            'application/vnd.ms-excel.sheet.macroEnabled.main+xml',
            $types,
        ));
        $this->post(route('admin.webinars.documents.store', $this->webinar), [
            'title' => 'Renamed macro workbook', 'file' => UploadedFile::fake()->createWithContent('renamed.xlsx', $contents),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('webinar_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_non_workbook_zip_or_spoofed_excel_file_is_rejected(): void
    {
        $this->post(route('admin.webinars.documents.store', $this->webinar), [
            'title' => 'Renamed PDF', 'file' => UploadedFile::fake()->createWithContent('spoof.xlsx', "%PDF-1.4\nSynthetic"),
        ])->assertSessionHasErrors('file');
        $this->post(route('admin.webinars.documents.store', $this->webinar), [
            'title' => 'Wrong package', 'file' => UploadedFile::fake()->createWithContent('not-workbook.xlsx', $this->workbook(removeWorkbook: true)),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('webinar_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    #[DataProvider('unsupportedExtensions')]
    public function test_legacy_binary_macro_and_generic_archive_extensions_are_not_accepted(string $extension): void
    {
        $this->post(route('admin.webinars.documents.store', $this->webinar), [
            'title' => 'Unsupported workbook', 'file' => UploadedFile::fake()->createWithContent('workbook.'.$extension, $this->workbook()),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('webinar_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public static function unsupportedExtensions(): array
    {
        return [['xls'], ['xlsm'], ['xlsb'], ['zip']];
    }

    public function test_excel_packages_keep_the_existing_expanded_size_limit(): void
    {
        $contents = $this->workbook(['xl/large.xml' => str_repeat(' ', 32 * 1024 * 1024)]);
        $this->assertLessThan(8 * 1024 * 1024, strlen($contents));
        $this->post(route('admin.webinars.documents.store', $this->webinar), [
            'title' => 'Expanded workbook', 'file' => UploadedFile::fake()->createWithContent('expanded.xlsx', $contents),
        ])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('webinar_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    private function workbook(array $parts = [], ?callable $transformTypes = null, bool $removeWorkbook = false): string
    {
        $path = tempnam(sys_get_temp_dir(), 'document-workbook-');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName('Summary');
            $writer->addRow(Row::fromValues(['Status', 'Count']));
            $writer->addRow(Row::fromValues(['Registered', 10]));
            $writer->addRow(Row::fromValues(['Attended', 8]));
            $writer->addRow(Row::fromValues(['Total', '=SUM(B2:B3)']));
            $writer->addNewSheetAndMakeItCurrent()->setName('Details');
            $writer->addRow(Row::fromValues(['Synthetic detail', 'Kept on the second worksheet']));
            $writer->close();
            $zip = new ZipArchive;
            $zip->open($path);
            $types = $zip->getFromName('[Content_Types].xml');
            $types = str_replace('</Types>', '<Override PartName="/xl/charts/chart1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawingml.chart+xml"/><Override PartName="/xl/drawings/drawing1.xml" ContentType="application/vnd.openxmlformats-officedocument.drawing+xml"/></Types>', $types);
            $zip->addFromString('[Content_Types].xml', $transformTypes ? $transformTypes($types) : $types);
            $zip->addFromString('xl/charts/chart1.xml', '<?xml version="1.0"?><c:chartSpace xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart><c:plotArea><c:layout/><c:pieChart><c:ser><c:idx val="0"/><c:order val="0"/><c:cat><c:strRef><c:f>Summary!$A$2:$A$3</c:f></c:strRef></c:cat><c:val><c:numRef><c:f>Summary!$B$2:$B$3</c:f></c:numRef></c:val></c:ser></c:pieChart></c:plotArea></c:chart></c:chartSpace>');
            $zip->addFromString('xl/drawings/drawing1.xml', '<?xml version="1.0"?><xdr:wsDr xmlns:xdr="http://schemas.openxmlformats.org/drawingml/2006/spreadsheetDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"><xdr:twoCellAnchor><xdr:from><xdr:col>0</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>5</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:from><xdr:to><xdr:col>8</xdr:col><xdr:colOff>0</xdr:colOff><xdr:row>20</xdr:row><xdr:rowOff>0</xdr:rowOff></xdr:to><xdr:graphicFrame macro=""><xdr:nvGraphicFramePr><xdr:cNvPr id="2" name="Attendance chart"/><xdr:cNvGraphicFramePr/></xdr:nvGraphicFramePr><xdr:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/></xdr:xfrm><a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/chart"><c:chart xmlns:c="http://schemas.openxmlformats.org/drawingml/2006/chart" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rId1"/></a:graphicData></a:graphic></xdr:graphicFrame><xdr:clientData/></xdr:twoCellAnchor></xdr:wsDr>');
            $zip->addFromString('xl/drawings/_rels/drawing1.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/chart" Target="../charts/chart1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/_rels/sheet1.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdChart" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/drawing" Target="../drawings/drawing1.xml"/></Relationships>');
            $zip->addFromString('xl/worksheets/sheet1.xml', str_replace('</worksheet>', '<drawing xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" r:id="rIdChart"/></worksheet>', $zip->getFromName('xl/worksheets/sheet1.xml')));
            foreach ($parts as $name => $contents) {
                $zip->addFromString($name, $contents);
            }
            if ($removeWorkbook) {
                $zip->deleteName('xl/workbook.xml');
            }
            $zip->close();

            return file_get_contents($path);
        } finally {
            unlink($path);
        }
    }
}
