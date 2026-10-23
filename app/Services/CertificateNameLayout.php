<?php

namespace App\Services;

use Dompdf\Dompdf;
use RuntimeException;

final class CertificateNameLayout
{
    /** Fit the complete name using the same font metrics as the PDF renderer. */
    public function fit(string $name, array $layout, float $ratio, Dompdf $pdf): array
    {
        if (trim($name) === '' || mb_strlen($name) > 180 || preg_match('/[\p{L}]/u', $name) !== 1 || preg_match('/[\x00-\x1F\x7F@]/', $name) === 1) {
            throw new RuntimeException('Confirm a complete certificate name before issuing.');
        }
        $fonts = app(CertificateFonts::class);
        $definition = $fonts->definition($layout);
        $family = $definition['family'];
        $variant = $definition['variant'];
        $metrics = $pdf->getFontMetrics();
        $font = $fonts->prepare($pdf, $definition);
        foreach (mb_str_split($name) as $character) {
            if (! $pdf->getCanvas()->font_supports_char($font, $character)) {
                $codepoint = 'U+'.strtoupper(dechex(mb_ord($character)));
                throw new RuntimeException("The certificate font ({$family} {$variant}) cannot print '{$character}' ({$codepoint}) in this name. Use a supported spelling and check the Preview PDF before sending.");
            }
        }
        $left = (float) ($layout['name_left'] ?? 50);
        $top = (float) ($layout['name_top'] ?? 62);
        $widthPercent = min((float) ($layout['name_max_width'] ?? 94), 2 * min($left, 100 - $left));
        $requested = (float) ($layout['name_font_size'] ?? 42);
        $availableWidth = 595 * $ratio * $widthPercent / 100 * 0.98;
        $bottom = min(100, (float) ($layout['name_bottom'] ?? 100));
        $availableHeight = 595 * ($bottom - $top) / 100 - 4;
        if ($requested <= 0 || $top < 0 || $widthPercent <= 0 || $availableHeight <= 0) {
            throw new RuntimeException('Move the certificate name inside the page before issuing.');
        }
        $textWidth = $metrics->getTextWidth($name, $font, $requested);
        // A one-em line box has half its extra leading above and below the
        // font's ascender/descender. Share this baseline with the browser,
        // rather than relying on Dompdf's different CSS line-box algorithm.
        $baselineFactor = (1 + $definition['ascent'] - $definition['descent']) / 2;
        $textHeight = ($baselineFactor + $definition['descent']) * $requested;
        $fitted = floor(min($requested, $requested * $availableWidth / max(1, $textWidth), $requested * $availableHeight / max(1, $textHeight)) * 100) / 100;
        if ($fitted < 12) {
            throw new RuntimeException('The full name cannot fit legibly at this position. Move the name or adjust the certificate design; no name was shortened.');
        }

        $boxWidth = 595 * $ratio * $widthPercent / 100;
        $width = $metrics->getTextWidth($name, $font, $fitted);
        $offset = match ($layout['name_text_align'] ?? 'center') {
            'left' => 0,
            'right' => $boxWidth - $width,
            default => ($boxWidth - $width) / 2,
        };

        return [
            'font_size' => $fitted, 'width_percent' => $widthPercent, 'font' => $font,
            'x' => 595 * $ratio * $left / 100 - $boxWidth / 2 + $offset,
            'baseline' => 595 * $top / 100 + $baselineFactor * $fitted,
        ];
    }
}
