@props([
    'webinar',
    'name' => 'Participant',
    'hasBackground' => false,
    'layout' => [],
    'backgroundUrl' => null,
])
@php
    // These mirror the PDF templates (certificates/pdf-custom + certificates/pdf)
    // so the on-screen preview matches the issued file. Sizes are expressed in
    // container-query height units so one card renders identically at any width.
    $nameTop = (float) ($layout['name_top'] ?? 62);
    $fontSize = (float) ($layout['name_font_size'] ?? 42);
    $accent = $layout['accent'] ?? '#1d4ed8';
    $heading = $layout['heading'] ?? 'Certificate of Completion';
    $body = $layout['body'] ?? 'has successfully completed all requirements of';
    $sigName = $layout['signatory_name'] ?? null;
    $sigTitle = $layout['signatory_title'] ?? null;
    $displayName = filled($name) ? $name : 'Participant';
@endphp
{{-- A4 landscape aspect. container-type:size lets children size from card height. --}}
<div class="relative w-full overflow-hidden rounded-lg bg-white" style="aspect-ratio: 297 / 210; container-type: size;">
    @if($hasBackground && $backgroundUrl)
        <img src="{{ $backgroundUrl }}" alt="" class="absolute inset-0 h-full w-full object-contain">
        <div style="position:absolute; left:6%; right:6%; top:{{ $nameTop }}%; text-align:center; font-weight:700; line-height:1; color:{{ $accent }}; font-size: calc({{ $fontSize }} / 595 * 100cqh);">
            {{ $displayName }}
        </div>
    @else
        {{-- Generated design. 1em ≈ 10px on the reference 560px-tall sheet. --}}
        <div class="absolute inset-0 flex flex-col" style="font-size: calc(100cqh / 56); padding: 3.4em; color:#0f172a;">
            <div class="flex h-full flex-col items-center text-center" style="border: 0.2em solid {{ $accent }}; border-radius: 0.6em; padding: 3em 5.4em;">
                <p style="font-size:0.9em; letter-spacing:0.4em; text-transform:uppercase; color:{{ $accent }}; margin:0;">{{ Str::limit($webinar->title, 60) }}</p>
                <div style="width:9em; height:0.4em; background:{{ $accent }}; margin:1.4em auto;"></div>
                <h3 style="font-size:3em; font-weight:700; margin:0 0 0.7em; color:#0f172a;">{{ $heading }}</h3>
                <p style="font-size:1.1em; color:#64748b; margin:0 0 0.4em;">This certificate is proudly presented to</p>
                <p style="font-size:3.4em; font-weight:700; margin:0; color:{{ $accent }};">{{ $displayName }}</p>
                <div style="border-bottom:0.1em solid #cbd5e1; width:62%; margin:0.6em auto 1.4em;"></div>
                <p style="font-size:1.2em; color:#475569; margin:0 0 0.4em;">{{ $body }}</p>
                <p style="font-size:1.8em; font-weight:700; margin:0;">{{ Str::limit($webinar->title, 60) }}</p>
                @if($sigName)
                    <p style="margin-top:auto; font-size:1.1em; font-weight:700; color:#0f172a;">{{ $sigName }}<br><span style="font-weight:400; color:#64748b;">{{ $sigTitle }}</span></p>
                @endif
            </div>
        </div>
    @endif
</div>
