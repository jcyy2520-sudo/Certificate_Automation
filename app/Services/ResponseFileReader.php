<?php

namespace App\Services;

use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use RuntimeException;
use ZipArchive;

/** Bounded XLSX decoding into the existing private, transactional CSV intake. */
final class ResponseFileReader
{
    public const MAX_COLUMNS = 200;

    public const MAX_CELLS = 300000;

    public const MAX_TEXT = 32768;

    public function contents(UploadedFile $file): string
    {
        if ($file->getSize() > 4 * 1024 * 1024) {
            throw new RuntimeException('Response files must be at most 4 MB.');
        }
        $extension = strtolower($file->getClientOriginalExtension());
        if ($extension === 'csv') {
            return (string) file_get_contents($file->getRealPath());
        }
        if ($extension !== 'xlsx') {
            throw new RuntimeException('Upload a UTF-8 CSV or Excel .xlsx response file.');
        }
        $numericCells = $this->inspectArchive($file->getRealPath());
        $options = new Options;
        $options->SHOULD_PRESERVE_EMPTY_ROWS = true;
        $reader = new Reader($options);
        $out = fopen('php://temp/maxmemory:2097152', 'w+b');
        $populatedSheets = 0;
        $cellsRead = 0;
        try {
            $reader->open($file->getRealPath());
            foreach ($reader->getSheetIterator() as $sheet) {
                $rows = 0;
                $width = 0;
                foreach ($sheet->getRowIterator() as $rowNumber => $row) {
                    // ErrorCell::getValue() is null. Keep the object until its
                    // source error text is restored, or reject an invalid value.
                    $values = array_map(fn (Cell $cell) => $cell instanceof ErrorCell ? $cell : $cell->getValue(), $row->getCells());
                    if (count($values) > self::MAX_COLUMNS || ++$rows > CsvImportService::MAX_ROWS + 1
                        || ($cellsRead += count($values)) > self::MAX_CELLS) {
                        throw new RuntimeException('The workbook exceeds 5,000 rows, 200 columns or 300,000 cells. Split the response sheet into smaller files.');
                    }
                    if ($rows === 1) {
                        if (++$populatedSheets > 1) {
                            throw new RuntimeException('This workbook has multiple populated sheets. Upload a workbook containing only the intended response sheet.');
                        }
                        $width = count($values);
                    }
                    // XLSX omits trailing empty cells; retain their header positions.
                    $values = array_pad($values, $width, '');
                    $values = array_map(function ($value, $index) use ($numericCells, $sheet, $rowNumber): string {
                        $numeric = $numericCells[$sheet->getName()][$rowNumber][$index] ?? null;
                        if (isset($numeric['literal_text'])) {
                            $value = $numeric['literal_text'];
                            $numeric = null;
                        }
                        if ($value instanceof DateTimeInterface) {
                            // OpenSpout rounds numeric date serials to whole seconds.
                            // A format showing fractional seconds cannot use that result.
                            $dateFormat = preg_replace('/"[^"]*"/', '', $numeric['format'] ?? '');
                            if (preg_match('/s[.,][0#?]/i', $dateFormat) === 1) {
                                throw new RuntimeException('Fractional-second numeric Excel date formats are unsupported. Export the displayed values as UTF-8 CSV to preserve their precision.');
                            }
                            // Excel dates are local wall times, not UTC instants.
                            $fraction = rtrim($value->format('u'), '0');

                            return $value->format('Y-m-d H:i:s').($fraction === '' ? '' : '.'.$fraction);
                        }
                        if (! is_scalar($value) && $value !== null) {
                            throw new RuntimeException('The workbook contains an unsupported cell value. Export the response sheet as CSV.');
                        }
                        $text = isset($numeric['text_padding'])
                            ? $numeric['text_padding'][0].(string) $value.$numeric['text_padding'][1]
                            : ($numeric !== null && is_numeric($value)
                                ? app(XlsxNumberFormats::class)->display($numeric)
                                : (is_bool($value) ? ($value ? 'TRUE' : 'FALSE') : (string) $value));
                        if (strlen($text) > self::MAX_TEXT || str_contains($text, "\0")) {
                            throw new RuntimeException('A cell exceeds 32 KB or contains invalid text.');
                        }

                        return $text;
                    }, $values, array_keys($values));
                    // Formula cells remain literal formulas. Never calculate or trust cached results.
                    if ($rows === 1 && count($values) === 1) {
                        // XLSX already establishes the column count. Quote this
                        // heading so literal separators cannot imply another CSV dialect.
                        fwrite($out, '"'.str_replace('"', '""', $values[0]).'"'."\n");
                    } else {
                        fputcsv($out, $values, ',', '"', '');
                    }
                    if (ftell($out) > 16 * 1024 * 1024) {
                        throw new RuntimeException('The decoded response sheet exceeds 16 MB. Split the file.');
                    }
                }
            }
            rewind($out);

            return stream_get_contents($out);
        } catch (RuntimeException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            throw new RuntimeException('The Excel workbook could not be read. Upload a valid .xlsx response sheet or export it as UTF-8 CSV.', previous: $exception);
        } finally {
            $reader->close();
            fclose($out);
        }
    }

    private function inspectArchive(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw new RuntimeException('This is not a valid Excel .xlsx workbook.');
        }
        try {
            $expanded = 0;
            if ($zip->numFiles > 1000 || $zip->locateName('xl/workbook.xml') === false) {
                throw new RuntimeException('The workbook is invalid or contains too many archive entries.');
            }
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = $zip->statIndex($i);
                $name = str_replace('\\', '/', $entry['name']);
                $expanded += $entry['size'];
                if ($expanded > 32 * 1024 * 1024 || str_contains($name, '../') || str_starts_with($name, '/')
                    || str_contains($name, ':') || preg_match('/vbaProject|externalLinks|embeddings/i', $name)) {
                    throw new RuntimeException('The workbook contains unsafe content or expands beyond 32 MB. Export a plain response sheet.');
                }
                if (preg_match('/\.(xml|rels)$/i', $name)) {
                    $xml = $zip->getFromIndex($i);
                    if ($xml === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $xml)) {
                        throw new RuntimeException('The workbook contains invalid or unsafe XML.');
                    }
                    if (preg_match('#^xl/worksheets/[^/]+\.xml$#', $name)) {
                        $this->inspectSheet($xml);
                    }
                }
            }

            return app(XlsxNumberFormats::class)->cells($zip);
        } finally {
            $zip->close();
        }
    }

    private function inspectSheet(string $xml): void
    {
        $reader = new \XMLReader;
        $reader->XML($xml, null, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
        try {
            $cells = 0;
            $rows = 0;
            while ($reader->read()) {
                if ($reader->nodeType !== \XMLReader::ELEMENT) {
                    continue;
                }
                if ($reader->localName === 'row' && ++$rows > CsvImportService::MAX_ROWS + 1) {
                    throw new RuntimeException('The workbook exceeds 5,000 response rows. Split the file.');
                }
                if ($reader->localName === 'c' && ++$cells > self::MAX_CELLS) {
                    throw new RuntimeException('The workbook exceeds 300,000 cells. Split the file.');
                }
                $reference = $reader->localName === 'dimension' ? $reader->getAttribute('ref')
                    : ($reader->localName === 'c' ? $reader->getAttribute('r') : null);
                if ($reference !== null && preg_match_all('/([A-Z]+)[0-9]+/', $reference, $matches)) {
                    foreach ($matches[1] as $letters) {
                        $column = 0;
                        foreach (str_split($letters) as $letter) {
                            $column = $column * 26 + ord($letter) - 64;
                            if ($column > self::MAX_COLUMNS) {
                                throw new RuntimeException('The workbook extends beyond 200 columns. Remove unused formatted columns or export as CSV.');
                            }
                        }
                    }
                }
            }
        } finally {
            $reader->close();
        }
    }
}
