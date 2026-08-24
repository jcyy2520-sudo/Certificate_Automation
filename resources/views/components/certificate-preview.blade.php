@props([
    'name' => 'Participant',
    'layout' => [],
    'backgroundUrl' => null,
])
@php
    use App\Models\CertificateTemplate;

    // Mirror certificates/pdf-custom so the on-screen preview matches the issued
    // file exactly. Sizes use container-query height units so one card renders
    // identically at any width.
    $nameTop = (float) ($layout['name_top'] ?? 62);
    $nameLeft = (float) ($layout['name_left'] ?? 50);
    $fontSize = (float) ($layout['name_font_size'] ?? 42);
    $fontKey = $layout['name_font_family'] ?? 'sans';
    $fontCss = (CertificateTemplate::FONTS[$fontKey] ?? CertificateTemplate::FONTS['sans'])['css'];
    $accent = $layout['accent'] ?? '#1d4ed8';
    $weight = ($layout['name_font_weight'] ?? 'bold') === 'regular' ? '400' : '700';
    $style = ($layout['name_font_style'] ?? 'regular') === 'italic' ? 'italic' : 'normal';
    $requestedAlign = $layout['name_text_align'] ?? 'center';
    $align = in_array($requestedAlign, ['left', 'center', 'right'], true) ? $requestedAlign : 'center';
    $bgW = (float) ($layout['bg_w'] ?? 0);
    $bgH = (float) ($layout['bg_h'] ?? 0);
    $ratio = $bgW > 0 && $bgH > 0 ? $bgW.' / '.$bgH : '297 / 210';
    $displayName = filled($name) ? $name : 'Participant';
@endphp
{{-- container-type:size lets children size from the card's own height. --}}
<div class="relative w-full overflow-hidden rounded-lg bg-white" style="aspect-ratio: {{ $ratio }}; container-type: size;" data-cert-canvas>
    @if($backgroundUrl)
        <img src="{{ $backgroundUrl }}" alt="" class="pointer-events-none absolute inset-0 h-full w-full object-contain">
        {{-- Top-anchored vertically and centred horizontally on the point, to
             match certificates/pdf-custom exactly (dompdf cannot transform). --}}
        <div data-cert-name
             data-name-top="{{ $nameTop }}" data-name-left="{{ $nameLeft }}"
             style="position:absolute; left:{{ $nameLeft }}%; top:{{ $nameTop }}%; transform:translateX(-50%); width:94%; text-align:{{ $align }}; white-space:nowrap; font-weight:{{ $weight }}; font-style:{{ $style }}; line-height:1; color:{{ $accent }}; font-family:{{ $fontCss }}; font-size: calc({{ $fontSize }} / 595 * 100cqh);">
            {{ $displayName }}
        </div>
    @else
        <div class="absolute inset-0 flex flex-col items-center justify-center gap-2 p-6 text-center text-slate-400">
            <x-icon name="image" class="size-8" />
            <p class="text-[12px] font-medium">No certificate design uploaded yet</p>
            <p class="text-[11px]">Upload your finished certificate on the template page.</p>
        </div>
    @endif
</div>
