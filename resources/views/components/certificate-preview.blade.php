@props([
    'name' => 'Participant',
    'layout' => [],
    'backgroundUrl' => null,
])
@php
    // These mirror certificates/pdf-custom so the on-screen preview matches the
    // issued file. Sizes use container-query height units so one card renders
    // identically at any width.
    $nameTop = (float) ($layout['name_top'] ?? 62);
    $fontSize = (float) ($layout['name_font_size'] ?? 42);
    $accent = $layout['accent'] ?? '#1d4ed8';
    $displayName = filled($name) ? $name : 'Participant';
@endphp
{{-- A4 landscape aspect. container-type:size lets children size from card height. --}}
<div class="relative w-full overflow-hidden rounded-lg bg-white" style="aspect-ratio: 297 / 210; container-type: size;">
    @if($backgroundUrl)
        <img src="{{ $backgroundUrl }}" alt="" class="absolute inset-0 h-full w-full object-contain">
        <div data-cert-name
             style="position:absolute; left:6%; right:6%; top:{{ $nameTop }}%; text-align:center; font-weight:700; line-height:1; color:{{ $accent }}; font-size: calc({{ $fontSize }} / 595 * 100cqh);">
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
