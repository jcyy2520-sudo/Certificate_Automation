<?php

namespace App\Services;

use OpenSpout\Common\Helper\Escaper\XLSX;
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
        $sharedStringsPath = 'xl/sharedStrings.xml';
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
            if (str_ends_with((string) $relationship['Type'], '/sharedStrings')) {
                $sharedStringsPath = $target;
            }
        }
        $sharedStringCount = $this->sharedStringCount($zip->getFromName($sharedStringsPath));
        $formats = [0 => 'General', 1 => '0', 2 => '0.00', 3 => '#,##0', 4 => '#,##0.00', 9 => '0%', 10 => '0.00%'];
        $styles = [0 => 'General'];
        if (($xml = $zip->getFromName($stylesPath)) !== false) {
            $document = $this->xml($xml);
            foreach ($document->xpath('//*[local-name()="numFmts"]/*[local-name()="numFmt"]') as $format) {
                $formats[(int) $format['numFmtId']] = (string) $format['formatCode'];
            }
            $baseFormats = [];
            foreach ($document->xpath('//*[local-name()="cellStyleXfs"]/*[local-name()="xf"]') as $index => $style) {
                $baseFormats[$index] = (int) $style['numFmtId'];
            }
            foreach ($document->xpath('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $index => $style) {
                $formatId = (int) $style['numFmtId'];
                $baseFormat = $baseFormats[(int) $style['xfId']] ?? null;
                $applyFormat = (string) $style['applyNumberFormat'];
                $inherits = in_array($applyFormat, ['0', 'false'], true)
                    || ($applyFormat === '' && $formatId === 0 && $baseFormat !== null && $baseFormat !== 0);
                $styles[$index] = $inherits && $baseFormat !== $formatId ? 'inherited' : ($formats[$formatId] ?? 'unsupported');
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
            $rowDepth = null;
            $rowStyle = null;
            $columnStyles = [];
            try {
                if (! $reader->XML($xml, null, LIBXML_NONET)) {
                    throw new RuntimeException('The workbook contains an invalid worksheet. Export it as UTF-8 CSV.');
                }
                while ($reader->read()) {
                    if ($reader->nodeType === \XMLReader::END_ELEMENT && $reader->localName === 'row') {
                        $rowDepth = null;
                    }
                    if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'col' && $reader->getAttribute('style') !== null) {
                        $first = $reader->getAttribute('min');
                        $last = $reader->getAttribute('max');
                        if (! ctype_digit((string) $first) || ! ctype_digit((string) $last) || (int) $first < 1 || (int) $last < (int) $first || (int) $last > 16384) {
                            throw new RuntimeException('The workbook contains invalid column styles. Export the response sheet as UTF-8 CSV.');
                        }
                        $style = $this->styleIndex($reader->getAttribute('style'), $styles);
                        for ($column = (int) $first; $column <= min((int) $last, ResponseFileReader::MAX_COLUMNS); $column++) {
                            if (isset($columnStyles[$column]) && $columnStyles[$column] !== $style) {
                                throw new RuntimeException('The workbook contains overlapping column styles. Export the response sheet as UTF-8 CSV.');
                            }
                            $columnStyles[$column] = $style;
                        }
                    }
                    if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'row') {
                        $declaredRow = $reader->getAttribute('r');
                        $nextRow = $declaredRow === null ? $rowNumber + 1 : (int) $declaredRow;
                        if (($declaredRow !== null && ! ctype_digit($declaredRow)) || $nextRow <= $rowNumber) {
                            throw new RuntimeException('The workbook contains ambiguous or invalid row positions. Export the response sheet as UTF-8 CSV.');
                        }
                        $rowNumber = $nextRow;
                        $columnNumber = 0;
                        $rowDepth = $reader->isEmptyElement ? null : $reader->depth;
                        $rowStyle = in_array($reader->getAttribute('customFormat'), ['1', 'true'], true)
                            ? $this->styleIndex($reader->getAttribute('s') ?? '0', $styles) : null;
                        if (++$rowsRead > CsvImportService::MAX_ROWS + 1 || $rowNumber > CsvImportService::MAX_ROWS + 1) {
                            throw new RuntimeException('The workbook exceeds 5,000 response rows. Split the file.');
                        }
                    }
                    if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'f') {
                        $formula = $this->xml($reader->readOuterXml());
                        if (! in_array($reader->getAttribute('t'), [null, '', 'normal'], true) || trim((string) $formula) === '') {
                            throw new RuntimeException('Shared, array, table or empty Excel formulas cannot be preserved literally. Export the displayed values as UTF-8 CSV.');
                        }
                    }
                    if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'c') {
                        continue;
                    }
                    if ($rowDepth === null || $reader->depth !== $rowDepth + 1) {
                        throw new RuntimeException('A workbook cell does not belong to a row. Export the response sheet as UTF-8 CSV.');
                    }
                    if (++$cellsRead > ResponseFileReader::MAX_CELLS) {
                        throw new RuntimeException('The workbook exceeds 300,000 cells. Split the file.');
                    }
                    $reference = $reader->getAttribute('r');
                    $type = $reader->getAttribute('t');
                    if (! in_array($type, [null, '', 'n', 'str', 'inlineStr', 's', 'b', 'd', 'e'], true)) {
                        throw new RuntimeException('The workbook contains an unsupported cell type. Export the response sheet as UTF-8 CSV.');
                    }
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
                    $explicitStyle = $reader->getAttribute('s');
                    $style = $explicitStyle === null ? ($rowStyle ?? $columnStyles[$column] ?? 0) : $this->styleIndex($explicitStyle, $styles);
                    if (in_array($type, ['s', 'b'], true)) {
                        $cell = $this->xml($reader->readOuterXml());
                        if ($cell->xpath('./*[local-name()="f"]') === []) {
                            $value = (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                            if ($type === 's' && (! ctype_digit($value) || (int) $value >= $sharedStringCount)) {
                                throw new RuntimeException('A workbook shared-string reference is invalid or missing. Export the response sheet as UTF-8 CSV.');
                            }
                            if ($type === 'b' && ! in_array($value, ['0', '1'], true)) {
                                throw new RuntimeException('A workbook boolean must use the Excel values 0 or 1. Export the response sheet as UTF-8 CSV.');
                            }
                        }

                        continue;
                    }
                    if ($type === 'inlineStr') {
                        $cellXml = $reader->readOuterXml();
                        if (str_contains($cellXml, 'rPh')) {
                            $cell = $this->xml($cellXml);
                            $text = '';
                            $escaper = new XLSX;
                            // Pronunciation annotations are not displayed cell text.
                            foreach ($cell->xpath('./*[local-name()="is"]/*[local-name()="t"] | ./*[local-name()="is"]/*[local-name()="r"]/*[local-name()="t"]') as $part) {
                                $text .= $escaper->unescape((string) $part);
                            }
                            $cells[$rowNumber][$column - 1] = ['literal_text' => $text];
                        }

                        continue;
                    }
                    if ($type === 'e') {
                        $cell = $this->xml($reader->readOuterXml());
                        if ($cell->xpath('./*[local-name()="f"]') === []) {
                            $cells[$rowNumber][$column - 1] = ['literal_text' => (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '')];
                        }

                        continue;
                    }
                    if ($type === 'd') {
                        $cell = $this->xml($reader->readOuterXml());
                        $date = (string) ($cell->xpath('./*[local-name()="v"]')[0] ?? '');
                        if ($date !== '' && $cell->xpath('./*[local-name()="f"]') === []) {
                            if (preg_match('/(?:Z|[+-]\d{2}:\d{2})$/D', $date)) {
                                throw new RuntimeException('Excel dates with explicit timezones are unsupported. Export the source timestamp as UTF-8 CSV and declare its timestamp format when mapping.');
                            }
                            // PHP otherwise normalizes invalid calendar dates and
                            // truncates fractions longer than six decimal places.
                            if (! preg_match('/^\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})?)?$/D', $date)) {
                                throw new RuntimeException('An Excel date is invalid or has unsupported precision. Export the displayed values as UTF-8 CSV.');
                            }
                            try {
                                new \DateTimeImmutable($date);
                                $errors = \DateTimeImmutable::getLastErrors();
                            } catch (\Exception) {
                                throw new RuntimeException('An Excel date is invalid. Export the displayed values as UTF-8 CSV.');
                            }
                            if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
                                throw new RuntimeException('An Excel date is invalid. Export the displayed values as UTF-8 CSV.');
                            }
                        }

                        continue;
                    }
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
                    $format = $styles[$style];
                    $cell = $this->xml($reader->readOuterXml());
                    if ($cell->xpath('./*[local-name()="f"]') !== []) {
                        continue;
                    }
                    if ($format === 'inherited') {
                        throw new RuntimeException('An Excel number inherits an unsupported named-style format. Export the displayed values as UTF-8 CSV.');
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

    /** Count actual entries, never trusting the optional declared shared-string count. */
    private function sharedStringCount(string|false $xml): int
    {
        if ($xml === false) {
            return 0;
        }
        $reader = new \XMLReader;
        $previous = libxml_use_internal_errors(true);
        $count = 0;
        try {
            if (! $reader->XML($xml, null, LIBXML_NONET)) {
                throw new RuntimeException('The workbook shared-string table is invalid. Export the response sheet as UTF-8 CSV.');
            }
            while ($reader->read()) {
                if ($reader->nodeType === \XMLReader::ELEMENT && $reader->localName === 'si' && $reader->depth === 1) {
                    $count++;
                }
            }
            if (libxml_get_errors() !== []) {
                throw new RuntimeException('The workbook shared-string table is invalid. Export the response sheet as UTF-8 CSV.');
            }

            return $count;
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function styleIndex(string $value, array $styles): int
    {
        if (! ctype_digit($value) || ! isset($styles[(int) $value])) {
            throw new RuntimeException('A workbook number format is missing or invalid. Export the response sheet as UTF-8 CSV.');
        }

        return (int) $value;
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
