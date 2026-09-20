<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CertificateTemplate extends Model
{
    protected $guarded = ['id'];

    /** @var array<string, array{label: string, css: string, pdf: string}> */
    public const FONTS = [
        'sans' => ['label' => 'Sans-serif', 'css' => "'DejaVu Sans', Verdana, Geneva, sans-serif", 'pdf' => "'DejaVu Sans', sans-serif"],
        'serif' => ['label' => 'Serif', 'css' => "'DejaVu Serif', Georgia, 'Times New Roman', serif", 'pdf' => "'DejaVu Serif', serif"],
        'mono' => ['label' => 'Monospace', 'css' => "'DejaVu Sans Mono', 'Courier New', monospace", 'pdf' => "'DejaVu Sans Mono', monospace"],
        'arial' => ['label' => 'Arial equivalent', 'css' => "Arial, 'DejaVu Sans', sans-serif", 'pdf' => "'DejaVu Sans', sans-serif"],
        'times' => ['label' => 'Times New Roman equivalent', 'css' => "'Times New Roman', 'DejaVu Serif', serif", 'pdf' => "'DejaVu Serif', serif"],
    ];

    protected function casts(): array
    {
        return ['layout' => 'array', 'is_active' => 'boolean'];
    }

    /** @return array{top: float, left: float, font_size: float, font_family: string, accent: string, weight: string, style: string, align: string} */
    public function nameLayout(): array
    {
        $layout = $this->layout ?? [];
        $family = $layout['name_font_family'] ?? 'sans';

        return [
            'top' => (float) ($layout['name_top'] ?? 62),
            'left' => (float) ($layout['name_left'] ?? 50),
            'font_size' => (float) ($layout['name_font_size'] ?? 42),
            'font_family' => isset(self::FONTS[$family]) ? $family : 'sans',
            'accent' => $layout['accent'] ?? '#1d4ed8',
            'weight' => ($layout['name_font_weight'] ?? 'bold') === 'regular' ? 'regular' : 'bold',
            'style' => ($layout['name_font_style'] ?? 'regular') === 'italic' ? 'italic' : 'regular',
            'align' => in_array($layout['name_text_align'] ?? 'center', ['left', 'center', 'right'], true) ? ($layout['name_text_align'] ?? 'center') : 'center',
        ];
    }

    public function aspectRatio(): float
    {
        $layout = $this->layout ?? [];
        $width = (float) ($layout['bg_w'] ?? 0);
        $height = (float) ($layout['bg_h'] ?? 0);

        return $width > 0 && $height > 0 ? $width / $height : 297 / 210;
    }

    public function webinar()
    {
        return $this->belongsTo(Webinar::class);
    }

    public function batches()
    {
        return $this->hasMany(CertificateBatch::class);
    }

    public function certificates()
    {
        return $this->hasMany(Certificate::class);
    }
}
