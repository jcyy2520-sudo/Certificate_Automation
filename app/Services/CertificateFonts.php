<?php

namespace App\Services;

use App\Models\CertificateTemplate;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\File;
use RuntimeException;

final class CertificateFonts
{
    public function definition(array $layout): array
    {
        $key = $layout['name_font_family'] ?? 'sans';
        $family = explode(',', (CertificateTemplate::FONTS[$key] ?? CertificateTemplate::FONTS['sans'])['pdf'])[0];
        $bold = ($layout['name_font_weight'] ?? 'bold') !== 'regular';
        $italic = ($layout['name_font_style'] ?? 'regular') === 'italic';
        $variant = $bold ? ($italic ? 'bold_italic' : 'bold') : ($italic ? 'italic' : 'normal');

        return $this->metrics()[$family][$variant] + ['family' => $family, 'variant' => $variant];
    }

    public function prepare(Dompdf $pdf, array $definition): string
    {
        if (str_starts_with($definition['path'], 'resources/')) {
            File::ensureDirectoryExists($pdf->getOptions()->getFontDir());
            $registered = $pdf->getFontMetrics()->registerFont([
                'family' => $definition['family'],
                'weight' => str_starts_with($definition['variant'], 'bold') ? 'bold' : 'normal',
                'style' => str_contains($definition['variant'], 'italic') ? 'italic' : 'normal',
            ], base_path($definition['path']));
            if (! $registered) {
                throw new RuntimeException('The selected certificate font could not be loaded.');
            }
        }
        $font = $pdf->getFontMetrics()->getFont($definition['family'], $definition['variant']);
        if (! $font) {
            throw new RuntimeException('The selected certificate font is unavailable.');
        }

        return $font;
    }

    public function browserMetrics(): array
    {
        $families = $this->metrics();
        $result = [];
        foreach (CertificateTemplate::FONTS as $key => $font) {
            $family = explode(',', $font['pdf'])[0];
            foreach ($families[$family] as $variant => $metrics) {
                $result[$key][$variant] = array_intersect_key($metrics, array_flip(['ascent', 'descent']));
            }
        }

        return $result;
    }

    private function metrics(): array
    {
        static $metrics;

        return $metrics ??= json_decode(file_get_contents(resource_path('fonts/certificates/metrics.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
