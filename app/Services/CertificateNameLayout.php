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
        $family = match ($layout['name_font_family'] ?? 'sans') {
            'serif', 'times' => 'DejaVu Serif',
            'mono' => 'DejaVu Sans Mono',
            default => 'DejaVu Sans',
        };
        $bold = ($layout['name_font_weight'] ?? 'bold') !== 'regular';
        $italic = ($layout['name_font_style'] ?? 'regular') === 'italic';
        $variant = $bold ? ($italic ? 'bold_italic' : 'bold') : ($italic ? 'italic' : 'normal');
        $metrics = $pdf->getFontMetrics();
        $font = $metrics->getFont($family, $variant);
        if (! $font) {
            throw new RuntimeException('The selected certificate font is unavailable.');
        }
        foreach (mb_str_split($name) as $character) {
            if (! $pdf->getCanvas()->font_supports_char($font, $character)) {
                $codepoint = 'U+'.strtoupper(dechex(mb_ord($character)));
                throw new RuntimeException("The certificate font ({$family} {$variant}) cannot print '{$character}' ({$codepoint}) in this name. Use a supported spelling and check the Preview PDF before sending.");
            }
        }
        $left = (float) ($layout['name_left'] ?? 50);
        $top = (float) ($layout['name_top'] ?? 62);
        $widthPercent = min(94, 2 * min($left, 100 - $left));
        $requested = (float) ($layout['name_font_size'] ?? 42);
        $availableWidth = 595 * $ratio * $widthPercent / 100 * 0.98;
        $availableHeight = 595 * (1 - $top / 100) - 4;
        if ($requested <= 0 || $top < 0 || $widthPercent <= 0 || $availableHeight <= 0) {
            throw new RuntimeException('Move the certificate name inside the page before issuing.');
        }
        $textWidth = $metrics->getTextWidth($name, $font, $requested);
        $textHeight = $metrics->getFontHeight($font, $requested);
        $fitted = floor(min($requested, $requested * $availableWidth / max(1, $textWidth), $requested * $availableHeight / max(1, $textHeight)) * 100) / 100;
        if ($fitted < 12) {
            throw new RuntimeException('The full name cannot fit legibly at this position. Move the name or adjust the certificate design; no name was shortened.');
        }

        return ['font_size' => $fitted, 'width_percent' => $widthPercent];
    }
}
