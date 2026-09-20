@php
    use App\Models\CertificateTemplate;

    // Issued certificates keep a layout snapshot so a one-off adjustment in
    // the studio remains exact even if the shared template changes later.
    $layout = $certificate->layout ?? $certificate->template?->layout ?? [];
    $nameTop = (float) ($layout['name_top'] ?? 62);
    $nameLeft = (float) ($layout['name_left'] ?? 50);
    $fontSize = (float) ($layout['name_font_size'] ?? 42);
    $fontKey = $layout['name_font_family'] ?? 'sans';
    $fontPdf = (CertificateTemplate::FONTS[$fontKey] ?? CertificateTemplate::FONTS['sans'])['pdf'];
    $color = $layout['accent'] ?? '#1d4ed8';
    $weight = ($layout['name_font_weight'] ?? 'bold') === 'regular' ? 'normal' : 'bold';
    $style = ($layout['name_font_style'] ?? 'regular') === 'italic' ? 'italic' : 'normal';
    $requestedAlign = $layout['name_text_align'] ?? 'center';
    $align = in_array($requestedAlign, ['left', 'center', 'right'], true) ? $requestedAlign : 'center';
    // The page is sized to the image's aspect ratio with this reference height,
    // so the name's font size and vertical position stay calibrated regardless
    // of the uploaded image's dimensions.
    $sheetHeight = 595;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->verification_code }}</title>
    <style>
        @page { margin: 0; }
        html, body { width: 100%; height: 100%; margin: 0; padding: 0; overflow: hidden; }
        /* setPaper() uses PDF points. Keep the reference canvas and every
           reference-sized overlay in points too: 595px is only 446.25pt in
           dompdf and would squash the design into the top 75% of the page. */
        .sheet { position: relative; width: 100%; height: {{ $sheetHeight }}pt; overflow: hidden; }
        .background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        .recipient-name {
            position: absolute; top: {{ $nameTop }}%; left: {{ $nameLeft }}%;
            width: 94%; margin-left: -47%;
            text-align: {{ $align }}; font-weight: {{ $weight }}; font-style: {{ $style }}; font-size: {{ $fontSize }}pt;
            font-family: {{ $fontPdf }};
            color: {{ $color }};
        }
    </style>
</head>
<body>
    <div class="sheet">
        @if($backgroundDataUri)
            <img class="background" src="{{ $backgroundDataUri }}" alt="">
        @endif
        <div class="recipient-name">{{ $certificate->recipient_name ?: 'Participant' }}</div>
    </div>
</body>
</html>
