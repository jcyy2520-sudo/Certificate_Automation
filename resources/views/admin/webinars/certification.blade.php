@extends('layouts.webinar')
@section('title', 'Certificate Setup — '.$webinar->title)
@section('content')
@php
    $layout = $template->layout ?? [];
    $fontKey = $layout['name_font_family'] ?? 'sans';
    $sizeVal = (float) ($layout['name_font_size'] ?? 42);
    $accentVal = $layout['accent'] ?? '#1d4ed8';
    $topVal = (float) ($layout['name_top'] ?? 62);
    $leftVal = (float) ($layout['name_left'] ?? 50);
    $weightVal = $layout['name_font_weight'] ?? 'bold';
    $styleVal = $layout['name_font_style'] ?? 'regular';
    $alignVal = $layout['name_text_align'] ?? 'center';
@endphp

<x-page-header title="Certificate Setup"
               subtitle="Prepare the certificate design and the email delivered with it.">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500 tabular-nums">
            <span><strong class="font-semibold text-slate-900">{{ $issuedCount }}</strong> issued</span>
            <span class="text-slate-300">·</span>
            <span><strong class="font-semibold text-slate-900">{{ $pendingCount }}</strong> without a certificate</span>
        </div>
    </x-slot:meta>
    <x-slot:actions>
        <a class="button-secondary" target="_blank" rel="noopener" href="{{ route('admin.certification.preview', $webinar) }}"><x-icon name="external" class="size-4" />Preview PDF</a>
        <a class="button-success" href="{{ route('admin.certificates.studio', $webinar) }}"><x-icon name="send" class="size-4" />Open Certificate Studio</a>
    </x-slot:actions>
</x-page-header>

<div>
    <div id="certificate-artwork" class="panel scroll-mt-6 p-5">
        <form method="POST" action="{{ route('admin.certification.template', $webinar) }}" enctype="multipart/form-data" data-certificate-artwork-form>@csrf @method('PUT')
            <h2 class="section-title">Certificate Design</h2>
            <p class="mt-1 text-[13px] text-slate-500">
                Upload the finished certificate exactly as you want it to look. The system only places the participant's name on top &mdash; nothing else is added, cropped, or resized.
            </p>

            <div class="mt-4 grid gap-4">
                <div><p class="field-label">Certificate name</p><p class="mt-1 break-words text-sm font-medium">{{ $template->automaticName() }}</p><p class="mt-1 text-xs text-slate-500">Automatically generated from this webinar’s title and certificate type.</p></div>

                <div>
                    <p class="field-label">Certificate artwork <span class="font-normal text-slate-400">PNG, JPG, or one-page PDF &mdash; up to 8&nbsp;MB</span></p>
                    <label class="mt-1.5 flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3 transition hover:border-accent-400 hover:bg-accent-50/40 {{ $errors->has('background') ? 'border-red-300' : '' }}">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-white text-accent-600 ring-1 ring-slate-200"><x-icon name="download" class="size-5" /></span>
                        <span class="min-w-0">
                            <span class="block text-[13px] font-medium text-slate-800">{{ $template->background_path ? 'Replace the certificate artwork' : 'Choose certificate artwork' }}</span>
                            <span class="block truncate text-[12px] text-slate-500" data-file-name>{{ $template->background_path ? 'A design is already uploaded' : 'Choose a PNG, JPG, or single-page PDF' }}</span>
                        </span>
                        <input class="sr-only" type="file" name="background" accept="image/png,image/jpeg,application/pdf,.pdf" data-file-input @required(! $template->background_path)>
                    </label>
                    <x-field-error :error="$errors->first('background')" />
                    <p class="mt-1.5 text-[12px] text-slate-500">Leave a blank space for the participant name. A PDF must contain exactly one page; it is converted privately in this browser before upload.</p>
                    <p class="mt-1.5 text-[12px] text-slate-500" data-file-status aria-live="polite"></p>
                </div>

                <button class="button-primary mt-1 justify-self-start" data-file-submit>Save certificate artwork</button>
            </div>
        </form>

        @if($template->background_path)
            <p class="mt-5 border-t border-slate-100 pt-4 text-[12px] text-emerald-700"><strong>Artwork uploaded.</strong> Configure the participant name below.</p>
        @endif
    </div>
</div>

@if($template->background_path)
<section id="participant-name-style" class="panel mt-6 scroll-mt-6 overflow-hidden" data-template-designer>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
        <div>
            <h2 class="section-title">Participant name style</h2>
            <p class="mt-1 text-[12px] text-slate-500">Every new certificate starts with this style. Long names shrink to fit; names that cannot fit legibly are held. Check the actual Preview PDF, including the event wording, date and signatures in your artwork.</p>
        </div>
        <button type="submit" form="template-design-form" class="button-primary"><x-icon name="save" class="size-4" />Save certificate style</button>
    </div>
    <form id="template-design-form" method="POST" action="{{ route('admin.certification.design', $webinar) }}" class="grid xl:grid-cols-[minmax(0,1fr)_300px]" data-template-design-form>
        @csrf @method('PUT')
        <div class="bg-slate-900 p-3">
            <div class="relative w-full overflow-hidden rounded-md bg-white shadow-2xl" data-template-canvas-wrap>
                <x-certificate-preview name="Sample Participant" :layout="$layout" :background-url="route('admin.certification.background', $webinar)" />
                <div class="pointer-events-none absolute inset-y-0 left-1/2 hidden w-px -translate-x-1/2 bg-fuchsia-500" data-template-guide-v></div>
                <div class="pointer-events-none absolute inset-x-0 top-1/2 hidden h-px -translate-y-1/2 bg-fuchsia-500" data-template-guide-h></div>
            </div>
            <p class="mt-2 text-center text-[11px] text-slate-400">Drag the sample name, or use the arrow buttons for small movements.</p>
        </div>
        <div class="space-y-4 border-t border-slate-100 p-5 xl:border-t-0 xl:border-l">
            <label class="field-label">Font
                <select name="name_font_family" class="field" data-template-font>
                    @foreach($fonts as $key => $font)
                        <option value="{{ $key }}" data-css="{{ $font['css'] }}" @selected($fontKey === $key)>{{ $font['label'] }}</option>
                    @endforeach
                </select>
            </label>
            <label class="field-label">Size: <span class="font-semibold tabular-nums" data-template-size-readout>{{ (int) $sizeVal }}</span>
                <input type="range" name="name_font_size" min="12" max="160" step="1" value="{{ (int) $sizeVal }}" class="mt-2 w-full accent-accent-600" data-template-size>
            </label>
            <label class="field-label">Color
                <span class="mt-1 flex h-10 items-center gap-2 rounded-lg border border-slate-200 px-2">
                    <input type="color" name="accent" value="{{ $accentVal }}" class="size-7 cursor-pointer border-0 bg-transparent p-0" data-template-color>
                    <input value="{{ Str::upper($accentVal) }}" class="min-w-0 flex-1 font-mono text-[12px] outline-none" data-template-hex maxlength="7" aria-label="Name color hex value">
                </span>
            </label>
            <div>
                <p class="field-label">Style and alignment</p>
                <div class="mt-1 flex flex-wrap gap-1">
                    <button type="button" class="studio-tool-button {{ $weightVal === 'bold' ? 'is-active' : '' }}" data-template-bold aria-label="Bold"><strong>B</strong></button>
                    <button type="button" class="studio-tool-button {{ $styleVal === 'italic' ? 'is-active' : '' }}" data-template-italic aria-label="Italic"><em>I</em></button>
                    @foreach(['left', 'center', 'right'] as $alignment)
                        <button type="button" class="studio-tool-button {{ $alignVal === $alignment ? 'is-active' : '' }}" data-template-align="{{ $alignment }}" aria-label="Align {{ $alignment }}"><x-icon name="align-{{ $alignment }}" class="size-4" /></button>
                    @endforeach
                </div>
            </div>
            <div>
                <p class="field-label">Fine position</p>
                <div class="mt-1 grid w-36 grid-cols-3 gap-1">
                    <span></span><button type="button" class="studio-nudge h-8" data-template-nudge="up">↑</button><span></span>
                    <button type="button" class="studio-nudge h-8" data-template-nudge="left">←</button><button type="button" class="studio-nudge h-8 text-[9px]" data-template-reset>Reset</button><button type="button" class="studio-nudge h-8" data-template-nudge="right">→</button>
                    <span></span><button type="button" class="studio-nudge h-8" data-template-nudge="down">↓</button><span></span>
                </div>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <label class="field-label">Top (%)<input class="field" type="number" name="name_top" min="0" max="100" step="0.1" value="{{ $topVal }}" data-template-top></label>
                <label class="field-label">Left (%)<input class="field" type="number" name="name_left" min="0" max="100" step="0.1" value="{{ $leftVal }}" data-template-left></label>
            </div>
            <input type="hidden" name="name_font_weight" value="{{ $weightVal }}" data-template-weight>
            <input type="hidden" name="name_font_style" value="{{ $styleVal }}" data-template-style>
            <input type="hidden" name="name_text_align" value="{{ $alignVal }}" data-template-align-store>
            <button class="button-primary w-full"><x-icon name="save" class="size-4" />Save certificate style</button>
        </div>
    </form>
</section>
@endif

@include('admin.webinars.partials.certificate-email')
@endsection
