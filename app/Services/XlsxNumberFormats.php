<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/** Preserve simple Excel numeric displays; never silently discard an unknown format. */
final class XlsxNumberFormats
{
    /** The archive has already passed ResponseFileReader's XML and expansion checks. */
    public function cells(ZipArchive $zip): array
    {
        $relationships = $this->xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
        $targets = [];
        $stylesPath = 'xl/styles.xml';
        foreach ($relationships->xpath('//*[local-name()="Relationship"]') as $relationship) {
            if ((string) $relationship['TargetMode'] === 'External') {
                continue;
            }
            $target = $this->target((string) $relationship['Target']);
            $targets[(string) $relationship['Id']] = $target;
            if (str_ends_with((string) $relationship['Type'], '/styles')) {
                $stylesPath = $target;
                if ($zip->locateName($target) === false) {
                    throw new RuntimeException('Workbook number formats are missing. Export the response sheet as UTF-8 CSV.');
                }
            }
        }
        $formats = [0 => 'General', 1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00', 9 => '0%', 10 => '0.00%'];
        $styles = [0 => 'General'];
        if (($xml = $zip->getFromName($stylesPath)) !== false) {
            $document = $this->xml($xml);
            foreach ($document->xpath('//*[local-name()="numFmts"]/*[local-name()="numFmt"]') as $format) {
                $formats[(int) $format['numFmtId']] = (string) $format['formatCode'];
            }
            foreach ($document->xpath('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $index => $style) {
                $styles[$index] = $formats[(int) $style['numFmtId']] ?? 'unsupported';
            }
        }
        $workbook = $this->xml($zip->getFromName('xl/workbook.xml'));
        $sheets = [];
        $cellsRead = 0;
        foreach ($workbook->xpath('//*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheet) {
            $id = (string) ($sheet->xpath('@*[local-name()="id"]')[0] ?? '');
            if (! isset($targets[$id]) || ($xml = $zip->getFromName($targets[$id])) === false) {
                throw new RuntimeException('A workbook sheet could not be identified. Export the response sheet as UTF-8 CSV.');
            }
            $reader = new \XMLReader;
            $previousErrors = libxml_use_internal_errors(true);
            $cells = [];
            $rowsRead = 0;
            $rowNumber = 0;
            $columnNumber = 0;
            try {
                if (! $reader->XML($xml, null, LIBXML_NONET)) {
                    throw new RuntimeException('The workbook contains an invalid worksheet. Export it as UTF-8 CSV.');
                }
                while ($reader->read()) {
                    if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'row') {
                        $declaredRow = $reader->getAttribute('r');
                        $rowNumber = $declaredRow === null ? $rowNumber + 1 : (int) $declaredRow;
                        $columnNumber = 0;
                        if (++$rowsRead > CsvImportService::MAX_ROWS + 1 || $rowNumber < 1 || $rowNumber > CsvImportService::MAX_ROWS + 1) {
                            throw new RuntimeException('The workbook exceeds 5,000 response rows. Split the file.');
                        }
                    }
                    if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'c') {
                        continue;
                    }
                    if (++$cellsRead > ResponseFileReader::MAX_CELLS) {
                        throw new RuntimeException('The workbook exceeds 300,000 cells. Split the file.');
                    }
                    $reference = $reader->getAttribute('r');
                    $type = $reader->getAttribute('t');
                    if ($reference === null) {
                        // OOXML allows omitted coordinates: cells continue sequentially.
                        $column = $columnNumber + 1;
                    } elseif (preg_match('/^([A-Z]+)([0-9]+)$/D', $reference, $position)) {
                        $column = 0;
                        foreach (str_split($position[1]) as $letter) {
                            $column = $column * 26 + ord($letter) - 64;
                            if ($column > ResponseFileReader::MAX_COLUMNS) {
                                break;
                            }
                        }
                        if ((int) $position[2] !== $rowNumber) {
                            throw new RuntimeException('A workbook cell does not belong to its declared row. Export the response sheet as UTF-8 CSV.');
                        }
                    } else {
                        throw new RuntimeException('The workbook contains an invalid cell position. Export the response sheet as UTF-8 CSV.');
                    }
                    if ($column > ResponseFileReader::MAX_COLUMNS) {
                        throw new RuntimeException('The workbook extends beyond 200 columns. Remove unused columns or export as CSV.');
                    }
                    if ($column <= $columnNumber || $rowNumber < 1) {
                        throw new RuntimeException('The workbook contains ambiguous cell positions. Export the response sheet as UTF-8 CSV.');
                    }
                    $columnNumber = $column;
                    if ($type === 'str') {
                        $cell = $this->xml($reader->readOuterXml());
                        if ($cell->xpath('./*[local-name()="f"]') === []) {
                            $raw = (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                            // OpenSpout trims t="str" values before decoding escapes.
                            // Restore only that padding; retain its decoded literal core.
                            $leading = strlen($raw) - strlen(ltrim($raw));
                            $rest = substr($raw, $leading);
                            $trailing = strlen($rest) - strlen(rtrim($rest));
                            if ($leading > 0 || $trailing > 0) {
                                $cells[$rowNumber][$column - 1] = ['text_padding' => [
                                    substr($raw, 0, $leading), $trailing === 0 ? '' : substr($rest, -$trailing),
                                ]];
                            }
                        }

                        continue;
                    }
                    if (! in_array($type, [null, '', 'n'], true)) {
                        continue;
                    }
                    $style = (int) $reader->getAttribute('s');
                    if (! isset($styles[$style])) {
                        throw new RuntimeException('A workbook number format is missing. Export the response sheet as UTF-8 CSV.');
                    }
                    $format = $styles[$style];
                    $cell = $this->xml($reader->readOuterXml());
                    if ($cell->xpath('./*[local-name()="f"]') !== []) {
                        continue;
                    }
                    $value = $cell->xpath('./*[local-name()="v"]')[0] ?? null;
                    if ($value === null || (string) $value === '') {
                        continue;
                    }
                    $cells[$rowNumber][$column - 1] = ['value' => (string) $value, 'format' => $format];
                }
                if (libxml_get_errors() !== []) {
                    throw new RuntimeException('The workbook contains invalid worksheet XML. Export it as UTF-8 CSV.');
                }
            } finally {
                $reader->close();
                libxml_clear_errors();
                libxml_use_internal_errors($previousErrors);
            }
            $sheets[(string) $sheet['name']] = $cells;
        }

        return $sheets;
    }

    private function xml(string|false $xml): \SimpleXMLElement
    {
        if ($xml === false) {
            throw new RuntimeException('The workbook is missing a required XML part. Export the response sheet as UTF-8 CSV.');
        }
        $previous = libxml_use_internal_errors(true);
        try {
            $document = simplexml_load_string($xml, options: LIBXML_NONET);
            if ($document === false) {
                throw new RuntimeException('The workbook contains invalid XML. Export the response sheet as UTF-8 CSV.');
            }

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function target(string $target): string
    {
        $segments = [];
        foreach (explode('/', str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target) as $segment) {
            if ($segment === '..') {
                if ($segments === []) {
                    throw new RuntimeException('A workbook relationship leaves the archive. Export the response sheet as UTF-8 CSV.');
                }
                array_pop($segments);
            } elseif ($segment !== '' && $segment !== '.') {
                $segments[] = $segment;
            }
        }

        return implode('/', $segments);
    }

    public function display(array $cell): string
    {
        $value = $cell['value'];
        $format = $cell['format'];
        if ($format === 'General') {
            return $this->decimal($value);
        }
        if (! preg_match('/^(0+|#,##0)(?:\.(0+))?(%|"[^"\r\n]*")?$/D', $format, $parts)
            || strlen($parts[1]) > 50 || strlen($parts[2] ?? '') > 15 || ! is_numeric($value)) {
            throw new RuntimeException('A numeric cell uses an unsupported Excel display format. Export this sheet as UTF-8 CSV to preserve its displayed values.');
        }
        $decimals = strlen($parts[2] ?? '');
        $suffix = $parts[3] ?? '';
        $number = $this->decimal($value, $suffix === '%' ? 2 : 0);
        $negative = str_starts_with($number, '-');
        [$integer, $fraction] = array_pad(explode('.', ltrim($number, '-'), 2), 2, '');
        $fraction = rtrim($fraction, '0');
        // Compare decimal strings: even a tiny nonzero fraction must not disappear.
        if (strlen($fraction) > $decimals) {
            throw new RuntimeException('An Excel number would lose precision in its display format. Export the original values as UTF-8 CSV.');
        }
        $integer = ltrim($integer, '0') ?: '0';
        $integer = $parts[1] === '#,##0' ? preg_replace('/\B(?=(\d{3})+(?!\d))/', ',', $integer)
            : str_pad($integer, strlen($parts[1]), '0', STR_PAD_LEFT);
        $text = ($negative ? '-' : '').$integer.($decimals > 0 ? '.'.str_pad($fraction, $decimals, '0') : '');

        return $text.($suffix === '%' ? '%' : trim($suffix, '"'));
    }

    private function decimal(string $value, int $shift = 0): string
    {
        if (! preg_match('/^(-?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D', $value, $parts)) {
            throw new RuntimeException('The workbook contains an invalid numeric cell. Export the response sheet as UTF-8 CSV.');
        }
        $digits = $parts[2].($parts[3] ?? '');
        $point = strlen($parts[2]) + (int) ($parts[4] ?? 0) + $shift;
        if (abs($point) > ResponseFileReader::MAX_TEXT - strlen($digits)) {
            throw new RuntimeException('An Excel number is too large to preserve. Export the response sheet as UTF-8 CSV.');
        }

        $text = $parts[1].($point <= 0 ? '0.'.str_repeat('0', -$point).$digits
            : ($point >= strlen($digits) ? $digits.str_repeat('0', $point - strlen($digits))
                : substr($digits, 0, $point).'.'.substr($digits, $point)));

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
