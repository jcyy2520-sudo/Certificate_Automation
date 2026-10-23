<?php

namespace Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/** Independent, read-only decoder for the explicitly opted-in Google response exports.
 *
 * This deliberately uses neither OpenSpout nor application import/parser helpers.
 * Unknown numeric formats fail closed rather than copying the production reader.
 */
final class OpenXmlResponseOracle
{
    public static function read(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('Oracle could not open workbook.');
        }
        try {
            $workbook = self::xml($zip->getFromName('xl/workbook.xml'));
            $properties = $workbook->query('//*[local-name()="workbookPr"]')->item(0);
            $date1904 = $properties instanceof DOMElement && in_array($properties->getAttribute('date1904'), ['1', 'true'], true);
            $relationships = self::xml($zip->getFromName('xl/_rels/workbook.xml.rels'));
            $targets = [];
            foreach ($relationships->query('//*[local-name()="Relationship"]') as $relationship) {
                $targets[$relationship->getAttribute('Id')] = $relationship->getAttribute('Target');
            }
            $shared = [];
            if ($zip->locateName('xl/sharedStrings.xml') !== false) {
                $strings = self::xml($zip->getFromName('xl/sharedStrings.xml'));
                foreach ($strings->query('//*[local-name()="sst"]/*[local-name()="si"]') as $item) {
                    $shared[] = self::text($strings, $item);
                }
            }
            $styles = self::xml($zip->getFromName('xl/styles.xml'));
            $formats = [0 => 'General'];
            foreach ($styles->query('//*[local-name()="numFmt"]') as $format) {
                $formats[(int) $format->getAttribute('numFmtId')] = $format->getAttribute('formatCode');
            }
            $styleFormats = [];
            foreach ($styles->query('//*[local-name()="cellXfs"]/*[local-name()="xf"]') as $style) {
                $styleFormats[] = (int) $style->getAttribute('numFmtId');
            }
            $sheets = [];
            $stats = ['date_cells' => 0, 'formatted_score_cells' => 0, 'whitespace_cells' => 0, 'blank_cells' => 0, 'formula_cells' => 0];
            foreach ($workbook->query('//*[local-name()="sheets"]/*[local-name()="sheet"]') as $sheet) {
                $target = $targets[$sheet->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'id')] ?? '';
                $target = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/'.$target;
                $source = self::xml($zip->getFromName($target));
                $rows = [];
                $width = 0;
                foreach ($source->query('//*[local-name()="sheetData"]/*[local-name()="row"]') as $row) {
                    $number = (int) $row->getAttribute('r');
                    $values = [];
                    foreach ($source->query('./*[local-name()="c"]', $row) as $cell) {
                        $reference = $cell->getAttribute('r');
                        if (preg_match('/^([A-Z]+)[0-9]+$/D', $reference, $match) !== 1) {
                            throw new RuntimeException('Oracle encountered an invalid cell reference.');
                        }
                        $index = 0;
                        foreach (str_split($match[1]) as $letter) {
                            $index = $index * 26 + ord($letter) - 64;
                        }
                        $formula = $source->query('./*[local-name()="f"]', $cell)->item(0);
                        $raw = $source->query('./*[local-name()="v"]', $cell)->item(0)?->textContent ?? '';
                        $format = $formats[$styleFormats[(int) $cell->getAttribute('s')] ?? 0] ?? null;
                        $value = match (true) {
                            $formula !== null => '='.$formula->textContent,
                            $cell->getAttribute('t') === 's' => $shared[(int) $raw] ?? throw new RuntimeException('Oracle shared string is missing.'),
                            $cell->getAttribute('t') === 'inlineStr' => self::text($source, $cell),
                            $cell->getAttribute('t') === 'str' => $raw,
                            $cell->getAttribute('t') === 'b' => $raw === '1' ? 'TRUE' : 'FALSE',
                            $raw === '' => '',
                            default => self::number($raw, $format, $date1904),
                        };
                        $values[$index - 1] = $value;
                        if ($number > 1) {
                            $stats['date_cells'] += $format === 'm/d/yyyy h:mm:ss' ? 1 : 0;
                            $stats['formatted_score_cells'] += $format === '0" / 10"' ? 1 : 0;
                            $stats['whitespace_cells'] += trim($value) !== $value ? 1 : 0;
                            $stats['formula_cells'] += $formula !== null ? 1 : 0;
                        }
                    }
                    if ($number === 1) {
                        $width = $values === [] ? 0 : max(array_keys($values)) + 1;
                    }
                    $rows[$number] = array_replace(array_fill(0, $width, ''), $values);
                    if ($number > 1) {
                        $stats['blank_cells'] += count(array_filter($rows[$number], fn ($value) => $value === ''));
                    }
                }
                if ($rows !== []) {
                    $sheets[] = $rows;
                }
            }
            if (count($sheets) !== 1) {
                throw new RuntimeException('Oracle requires exactly one response sheet.');
            }

            return ['rows' => $sheets[0], 'stats' => $stats];
        } finally {
            $zip->close();
        }
    }

    private static function number(string $raw, ?string $format, bool $date1904): string
    {
        if (! is_numeric($raw)) {
            throw new RuntimeException('Oracle encountered a nonnumeric numeric cell.');
        }
        if ($format === 'm/d/yyyy h:mm:ss') {
            $seconds = (int) round((float) $raw * 86400);

            return (new DateTimeImmutable($date1904 ? '1904-01-01' : '1899-12-30', new DateTimeZone('UTC')))
                ->modify('+'.$seconds.' seconds')->format('Y-m-d H:i:s');
        }
        if ($format === '0" / 10"') {
            if (floor((float) $raw) !== (float) $raw) {
                throw new RuntimeException('Oracle score is not an exact integer.');
            }

            return number_format((float) $raw, 0, '.', '').' / 10';
        }
        if ($format !== 'General') {
            throw new RuntimeException('Oracle encountered an unimplemented numeric format.');
        }

        // Expand XML scientific notation exactly, without floating-point casts.
        if (preg_match('/^([+-]?)(\d+)(?:\.(\d*))?(?:[eE]([+-]?\d+))?$/D', $raw, $parts) !== 1) {
            throw new RuntimeException('Oracle numeric syntax is unsupported.');
        }
        $digits = $parts[2].($parts[3] ?? '');
        $point = strlen($parts[2]) + (int) ($parts[4] ?? 0);
        if ($point <= 0) {
            $value = '0.'.str_repeat('0', -$point).$digits;
        } elseif ($point >= strlen($digits)) {
            $value = $digits.str_repeat('0', $point - strlen($digits));
        } else {
            $value = substr($digits, 0, $point).'.'.substr($digits, $point);
        }
        if (str_contains($value, '.')) {
            $value = rtrim(rtrim($value, '0'), '.');
        }

        return ($parts[1] === '-' ? '-' : '').$value;
    }

    private static function xml(string|false $source): DOMXPath
    {
        if ($source === false || preg_match('/<!DOCTYPE|<!ENTITY/i', $source)) {
            throw new RuntimeException('Oracle XML is missing or unsafe.');
        }
        $document = new DOMDocument;
        if (! $document->loadXML($source, LIBXML_NONET)) {
            throw new RuntimeException('Oracle XML is invalid.');
        }

        return new DOMXPath($document);
    }

    private static function text(DOMXPath $source, DOMElement $node): string
    {
        $value = '';
        foreach ($source->query('.//*[local-name()="t"]', $node) as $text) {
            $value .= $text->textContent;
        }

        return preg_replace_callback('/_x([0-9A-Fa-f]{4})_/', fn ($match) => mb_chr(hexdec($match[1]), 'UTF-8'), $value) ?? $value;
    }
}
