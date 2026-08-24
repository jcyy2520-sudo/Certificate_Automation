@extends('layouts.webinar')
@section('title', 'Certificate template — '.$webinar->title)
@section('content')
@php
    $layout = $template->layout ?? [];
    $formTitles = $webinar->forms->keyBy('type');
    $labels = [
        'registration' => 'Registration submitted',
        'attendance' => 'Attendance recorded',
        'pretest' => 'Pre-assessment submitted',
        'posttest' => 'Post-assessment submitted',
        'evaluation' => 'Event evaluation submitted',
    ];
    $fontKey = $layout['name_font_family'] ?? 'sans';
    $sizeVal = (float) ($layout['name_font_size'] ?? 42);
    $accentVal = $layout['accent'] ?? '#1d4ed8';
    $topVal = (float) ($layout['name_top'] ?? 62);
    $leftVal = (float) ($layout['name_left'] ?? 50);
    $weightVal = $layout['name_font_weight'] ?? 'bold';
    $styleVal = $layout['name_font_style'] ?? 'regular';
    $alignVal = $layout['name_text_align'] ?? 'center';
@endphp

<x-page-header title="Certificate template & requirements"
               subtitle="Set who qualifies and configure the one certificate design used for every participant.">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500 tabular-nums">
            <span><strong class="font-semibold text-slate-900">{{ $issuedCount }}</strong> issued</span>
            <span class="text-slate-300">·</span>
            <span><strong class="font-semibold text-slate-900">{{ $pendingCount }}</strong> without a certificate</span>
        </div>
    </x-slot:meta>
    <x-slot:actions>
        <a class="button-secondary" target="_blank" rel="noopener" href="{{ route('admin.certification.preview', $webinar) }}"><x-icon name="external" class="size-4" />Preview PDF</a>
        <a class="button-primary" href="{{ route('admin.certificates.studio', $webinar) }}"><x-icon name="send" class="size-4" />Send certificates</a>
    </x-slot:actions>
</x-page-header>

<div class="grid gap-6 xl:grid-cols-2">
    <form class="panel p-5" method="POST" action="{{ route('admin.certification.rules', $webinar) }}">@csrf @method('PUT')
        <h2 class="section-title">Requirements</h2>
        <p class="mt-1 text-[13px] text-slate-500">A participant becomes eligible only after every enabled requirement is met. An override always wins.</p>

        <div class="mt-4 divide-y divide-slate-100 border-y border-slate-100">
            @foreach(\App\Http\Controllers\Admin\CertificationController::REQUIREMENTS as $requirement)
                @php $rule = $rules[$requirement] ?? null; @endphp
                <div class="py-4">
                    <label class="flex items-start gap-3">
                        <input class="survey-check mt-0.5 size-5" type="checkbox" name="rules[{{ $requirement }}][enabled]" value="1" @checked($rule)>
                        <span class="min-w-0">
                            <span class="block text-[13px] font-medium text-slate-900">{{ $labels[$requirement] }}</span>
                            <span class="mt-0.5 block text-[12px] text-slate-500">
                                @if($requirement === 'registration')
                                    The participant submitted the registration form.
                                @elseif($requirement === 'attendance')
                                    An administrator marked the participant as present.
                                @elseif($form = $formTitles[$requirement] ?? null)
                                    Form “{{ $form->title }}” · {{ $form->isOpen() ? 'Open' : 'Closed' }}
                                @else
                                    No {{ $requirement }} form exists on this webinar yet.
                                @endif
                            </span>
                        </span>
                    </label>
                    @if(! in_array($requirement, ['registration', 'attendance'], true))
                        @php $scoreError = $errors->first("rules.$requirement.minimum_score"); @endphp
                        <label class="field-label ml-8 mt-3 block max-w-48 text-[12px]">Minimum score <span class="font-normal text-slate-400">(blank = any)</span>
                            <input class="field {{ $scoreError ? 'field-invalid' : '' }}" type="number" step="0.5" min="0" name="rules[{{ $requirement }}][minimum_score]"
                                   value="{{ old("rules.$requirement.minimum_score", $rule?->minimum_score !== null ? (float) $rule->minimum_score : '') }}">
                            <x-field-error :error="$scoreError" />
                        </label>
                    @endif
                </div>
            @endforeach
        </div>

        <button class="button-primary mt-5">Save requirements</button>
    </form>

    <div class="panel p-5">
        <form method="POST" action="{{ route('admin.certification.template', $webinar) }}" enctype="multipart/form-data">@csrf @method('PUT')
            <h2 class="section-title">Certificate design</h2>
            <p class="mt-1 text-[13px] text-slate-500">
                Upload the finished certificate exactly as you want it to look. The system only places the participant's name on top &mdash; nothing else is added, cropped, or resized.
            </p>

            <div class="mt-4 grid gap-4">
                <label class="field-label">Template name
                    <input class="field {{ $errors->has('name') ? 'field-invalid' : '' }}" name="name" value="{{ old('name', $template->name) }}" required>
                    <x-field-error :error="$errors->first('name')" />
                </label>

                <div>
                    <p class="field-label">Certificate image <span class="font-normal text-slate-400">PNG or JPG &mdash; up to 8&nbsp;MB</span></p>
                    <label class="mt-1.5 flex cursor-pointer items-center gap-3 rounded-lg border border-dashed border-slate-300 bg-slate-50 px-4 py-3 transition hover:border-accent-400 hover:bg-accent-50/40 {{ $errors->has('background') ? 'border-red-300' : '' }}">
                        <span class="inline-flex size-9 shrink-0 items-center justify-center rounded-lg bg-white text-accent-600 ring-1 ring-slate-200"><x-icon name="download" class="size-5" /></span>
                        <span class="min-w-0">
                            <span class="block text-[13px] font-medium text-slate-800">{{ $template->background_path ? 'Replace the certificate image' : 'Choose a certificate image' }}</span>
                            <span class="block truncate text-[12px] text-slate-500" data-file-name>{{ $template->background_path ? 'A design is already uploaded' : 'PNG or JPG, any size — the design is kept exactly as-is' }}</span>
                        </span>
                        <input class="sr-only" type="file" name="background" accept="image/png,image/jpeg" data-file-input @required(! $template->background_path)>
                    </label>
                    <x-field-error :error="$errors->first('background')" />
                    <p class="mt-1.5 text-[12px] text-slate-500">Design it in Canva (or anywhere), export it, and upload it here. Leave a blank space where the participant name should go.</p>
                </div>

                <button class="button-primary mt-1 justify-self-start">Save certificate image</button>
            </div>
        </form>

        @if($template->background_path)
            <p class="mt-5 border-t border-slate-100 pt-4 text-[12px] text-emerald-700"><strong>Artwork uploaded.</strong> Configure the participant name below.</p>
        @endif
    </div>
</div>

@if($template->background_path)
<section class="panel mt-6 overflow-hidden" data-template-designer>
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-100 px-5 py-4">
        <div>
            <h2 class="section-title">Participant name style</h2>
            <p class="mt-1 text-[12px] text-slate-500">Set this once. Every new certificate starts with this style and position.</p>
        </div>
        <button type="submit" form="template-design-form" class="button-primary"><x-icon name="save" class="size-4" />Save certificate style</button>
    </div>
    <form id="template-design-form" method="POST" action="{{ route('admin.certification.design', $webinar) }}" class="grid xl:grid-cols-[minmax(0,1fr)_300px]" data-template-design-form>
        @csrf @method('PUT')
        <div class="bg-slate-900 p-5 sm:p-8">
            <div class="relative mx-auto max-w-4xl overflow-hidden rounded-lg bg-white shadow-2xl" data-template-canvas-wrap>
                <x-certificate-preview name="Sample Participant" :layout="$layout" :background-url="route('admin.certification.background', $webinar)" />
                <div class="pointer-events-none absolute inset-y-0 left-1/2 hidden w-px -translate-x-1/2 bg-fuchsia-500" data-template-guide-v></div>
                <div class="pointer-events-none absolute inset-x-0 top-1/2 hidden h-px -translate-y-1/2 bg-fuchsia-500" data-template-guide-h></div>
            </div>
            <p class="mt-3 text-center text-[11px] text-slate-400">Drag the sample name, or use the arrow buttons for small movements.</p>
        </div>
        <div class="space-y-4 border-l border-slate-100 p-5">
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
                <div class="mt-1 flex gap-1">
                    <button type="button" class="studio-tool-button {{ $weightVal === 'bold' ? 'is-active' : '' }}" data-template-bold aria-label="Bold"><strong>B</strong></button>
                    <button type="button" class="studio-tool-button {{ $styleVal === 'italic' ? 'is-active' : '' }}" data-template-italic aria-label="Italic"><em>I</em></button>
                    @foreach(['left', 'center', 'right'] as $alignment)
                        <button type="button" class="studio-tool-button {{ $alignVal === $alignment ? 'is-active' : '' }}" data-template-align="{{ $alignment }}" aria-label="Align {{ $alignment }}"><x-icon name="align-{{ $alignment }}" class="size-4" /></button>
                    @endforeach
                </div>
            </div>
            <div>
                <p class="field-label">Fine position</p>
                <div class="mt-1 grid w-28 grid-cols-3 gap-1">
                    <span></span><button type="button" class="studio-nudge h-8" data-template-nudge="up">↑</button><span></span>
                    <button type="button" class="studio-nudge h-8" data-template-nudge="left">←</button><button type="button" class="studio-nudge h-8 text-[9px]" data-template-reset>Reset</button><button type="button" class="studio-nudge h-8" data-template-nudge="right">→</button>
                    <span></span><button type="button" class="studio-nudge h-8" data-template-nudge="down">↓</button><span></span>
                </div>
            </div>
            <input type="hidden" name="name_top" value="{{ $topVal }}" data-template-top>
            <input type="hidden" name="name_left" value="{{ $leftVal }}" data-template-left>
            <input type="hidden" name="name_font_weight" value="{{ $weightVal }}" data-template-weight>
            <input type="hidden" name="name_font_style" value="{{ $styleVal }}" data-template-style>
            <input type="hidden" name="name_text_align" value="{{ $alignVal }}" data-template-align-store>
            <button class="button-primary w-full"><x-icon name="save" class="size-4" />Save certificate style</button>
        </div>
    </form>
</section>
@endif

<script nonce="{{ $cspNonce }}">
    (function () {
        // Reflect the chosen filename in the styled upload control.
        var input = document.querySelector('[data-file-input]');
        if (!input) return;
        var nameEl = document.querySelector('[data-file-name]');
        input.addEventListener('change', function () {
            if (input.files && input.files.length && nameEl) nameEl.textContent = input.files[0].name;
        });
    })();
</script>

<div class="mt-6 flex items-center gap-3 rounded-xl border border-slate-200 bg-slate-50 px-5 py-4">
    <span class="grid size-9 shrink-0 place-items-center rounded-lg bg-white text-accent-600 ring-1 ring-slate-200"><x-icon name="send" class="size-5" /></span>
    <div class="min-w-0">
        <p class="text-[13px] font-medium text-slate-900">Ready to send certificates?</p>
        <p class="mt-0.5 text-[12px] text-slate-500">Go to <a class="font-medium text-accent-600 hover:underline" href="{{ route('admin.certificates.studio', $webinar) }}">Send certificates</a> to review participant names and send. Design changes stay on this Template page.</p>
    </div>
</div>
@endsection
