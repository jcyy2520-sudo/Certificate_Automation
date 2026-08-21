@extends('layouts.webinar')
@section('title', 'Certificate template — '.$webinar->title)
@section('content')
@php
    $layout = $template->layout ?? [];
    $formTitles = $webinar->forms->keyBy('type');
    $labels = [
        'registration' => 'Registration submitted',
        'pretest' => 'Pre-assessment submitted',
        'posttest' => 'Post-assessment submitted',
        'evaluation' => 'Event evaluation submitted',
    ];
@endphp

<x-page-header title="Certificate template & requirements"
               subtitle="Set who qualifies and upload the certificate design. Issue and preview from Selection & preview.">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500 tabular-nums">
            <span><strong class="font-semibold text-slate-900">{{ $issuedCount }}</strong> issued</span>
            <span class="text-slate-300">·</span>
            <span><strong class="font-semibold text-slate-900">{{ $pendingCount }}</strong> without a certificate</span>
        </div>
    </x-slot:meta>
    <x-slot:actions>
        <a class="button-secondary" target="_blank" rel="noopener" href="{{ route('admin.certification.preview', $webinar) }}"><x-icon name="external" class="size-4" />Preview PDF</a>
        <a class="button-primary" href="{{ route('admin.certificates.studio', $webinar) }}"><x-icon name="send" class="size-4" />Selection &amp; preview</a>
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
                                @elseif($form = $formTitles[$requirement] ?? null)
                                    Form “{{ $form->title }}” · {{ $form->status }}
                                @else
                                    No {{ $requirement }} form exists on this webinar yet.
                                @endif
                            </span>
                        </span>
                    </label>
                    @if($requirement !== 'registration')
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

    <form class="panel p-5" method="POST" action="{{ route('admin.certification.template', $webinar) }}" enctype="multipart/form-data">@csrf @method('PUT')
        <h2 class="section-title">Certificate design</h2>
        <p class="mt-1 text-[13px] text-slate-500">
            Upload the finished certificate exactly as you want it to look. The system only places the participant's name on top &mdash; nothing else is added or changed.
        </p>

        <div class="mt-4 grid gap-4">
            <label class="field-label">Template name
                <input class="field {{ $errors->has('name') ? 'field-invalid' : '' }}" name="name" value="{{ old('name', $template->name) }}" required>
                <x-field-error :error="$errors->first('name')" />
            </label>

            <div class="border-y border-slate-100 py-4">
                <p class="field-label">Certificate image <span class="font-normal text-slate-400">PNG or JPG &mdash; up to 8&nbsp;MB</span></p>
                @if($template->background_path)
                    <div class="mt-2 flex items-center gap-3">
                        <img src="{{ route('admin.certification.background', $webinar) }}" alt="Current certificate" class="h-16 w-auto rounded-md border border-slate-200 object-cover">
                        <span class="inline-flex items-center gap-1.5 text-[12px] font-medium text-emerald-700"><x-icon name="check-circle" class="size-4" />Uploaded. Choose a new file to replace it.</span>
                    </div>
                @endif
                <input class="field mt-2 {{ $errors->has('background') ? 'field-invalid' : '' }}" type="file" name="background" accept="image/png,image/jpeg" @required(! $template->background_path)>
                <x-field-error :error="$errors->first('background')" />
                <p class="mt-1.5 text-[12px] text-slate-500">Design it in Canva (or anywhere), export as PNG, and upload it here. Leave a blank space where the name should go.</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <label class="field-label">Name position <span class="font-normal text-slate-400">% from the top</span>
                    <input class="field {{ $errors->has('name_top') ? 'field-invalid' : '' }}" type="number" min="0" max="100" step="1" name="name_top" value="{{ old('name_top', $layout['name_top'] ?? 62) }}">
                    <x-field-error :error="$errors->first('name_top')" />
                </label>
                <label class="field-label">Name size <span class="font-normal text-slate-400">px</span>
                    <input class="field {{ $errors->has('name_font_size') ? 'field-invalid' : '' }}" type="number" min="12" max="160" step="1" name="name_font_size" value="{{ old('name_font_size', $layout['name_font_size'] ?? 42) }}">
                    <x-field-error :error="$errors->first('name_font_size')" />
                </label>
            </div>
            <label class="field-label">Name colour
                <input class="field h-10 cursor-pointer p-1 {{ $errors->has('accent') ? 'field-invalid' : '' }}" type="color" name="accent" value="{{ old('accent', $layout['accent'] ?? '#1d4ed8') }}" required>
                <x-field-error :error="$errors->first('accent')" />
            </label>
            <p class="text-[12px] text-slate-500">Save, then open <a class="font-medium text-accent-600 hover:underline" target="_blank" rel="noopener" href="{{ route('admin.certification.preview', $webinar) }}">Preview PDF</a> above to check the name lines up &mdash; nudge the position and re-save until it fits. You can also fine-tune each name when issuing on <a class="font-medium text-accent-600 hover:underline" href="{{ route('admin.certificates.studio', $webinar) }}">Selection &amp; preview</a>.</p>
        </div>

        <button class="button-primary mt-5">Save design</button>
    </form>
</div>

<section class="panel mt-6 overflow-hidden">
    <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <h2 class="section-title">Bulk issuance</h2>
            <p class="mt-1 text-[13px] text-slate-500">Issues to every eligible participant without a valid certificate. Participants still missing a requirement are skipped, not failed.</p>
        </div>
        <form method="POST" action="{{ route('admin.certificates.batch', $webinar) }}" data-confirm="Issue certificates to all eligible participants?">@csrf
            <button class="button-primary shrink-0" @disabled($pendingCount === 0)>Issue to {{ $pendingCount }}</button>
        </form>
    </div>

    <div class="divide-y divide-slate-100">
        @forelse($batches as $batch)
            <div class="flex flex-wrap items-center justify-between gap-4 px-5 py-3.5">
                <div>
                    <p class="text-[13px] font-medium">{{ $batch->created_at->format('M j, Y g:i A') }}</p>
                    <p class="mt-0.5 text-[12px] text-slate-500 tabular-nums">{{ $batch->completed_count }} issued · {{ $batch->failed_count }} skipped · {{ $batch->total_count }} considered · {{ $batch->creator?->name ?? 'unknown' }}</p>
                </div>
                <span class="badge {{ $batch->status === 'completed' ? 'badge-green' : ($batch->status === 'failed' ? 'badge-red' : 'badge-amber') }}">{{ $batch->status }}</span>
            </div>
        @empty
            <p class="px-5 py-12 text-center text-sm text-slate-500">No bulk run has been queued yet.</p>
        @endforelse
    </div>
</section>
@endsection
