@php
    $layout = $certificate->template?->layout ?? [];
    $nameTop = (float) ($layout['name_top'] ?? 62);
    $fontSize = (float) ($layout['name_font_size'] ?? 42);
    $color = $layout['accent'] ?? '#1d4ed8';
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>{{ $certificate->verification_code }}</title>
    <style>
        @page { margin: 0; }
        body { margin: 0; padding: 0; font-family: DejaVu Sans, sans-serif; }
        .sheet { position: relative; width: 100%; height: 595px; }
        .background { position: absolute; top: 0; left: 0; width: 100%; height: 100%; }
        .recipient-name {
            position: absolute; left: 6%; right: 6%; top: {{ $nameTop }}%;
            text-align: center; font-weight: bold; font-size: {{ $fontSize }}px;
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
