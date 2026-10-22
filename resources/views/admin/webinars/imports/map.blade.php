@extends('layouts.webinar')
@section('title', 'Map columns — '.$webinar->title)
@section('content')
@php
    $isRegistration = $import->form->type === 'registration';
    $options = $import->column_map['options'] ?? [];
    $analysis = $import->column_map['analysis'] ?? [];
    $answerColumns = app(\App\Services\FormResponseService::class)->nativeColumns($import->form);
    $suggestedAnswers = $import->column_map['suggested_answers'] ?? [];
    $answerMap = old('answer_map', $options['answer_map'] ?? $suggestedAnswers);
    $selectedMap = array_filter(old('map', $map), fn ($value) => $value !== '' && $value !== null);
    $defaultRequired = collect($answerColumns)->filter(fn ($column, $key) => $column['required'] && isset($answerMap[$key]))->keys()->map(fn ($key) => $answerMap[$key])->all();
@endphp
<x-page-header title="Map columns"
               :crumbs="['Webinars' => route('admin.webinars.index'), $webinar->title => route('admin.webinars.show', $webinar), 'CSV imports' => route('admin.webinars.imports.index', $webinar)]"
               :subtitle="'Confirm what each column of “'.$import->original_filename.'” holds before it is imported into '.$import->form->title.'.'">
    <x-slot:actions>
        <form method="POST" action="{{ route('admin.webinars.imports.cancel', [$webinar, $import]) }}"
              data-confirm="Discard this upload? The temporary copy of the file will be deleted."
              data-confirm-tone="neutral" data-confirm-action="Discard upload" data-confirm-title="Cancel import">
            @csrf
            <button class="button-secondary"><x-icon name="x" class="size-4" />Discard upload</button>
        </form>
    </x-slot:actions>
</x-page-header>

<section class="panel mb-5 p-5" aria-label="Detected file structure">
    <h2 class="section-title">Detected file structure</h2>
    <p class="mt-2 break-words text-sm"><strong>{{ $import->original_filename }}</strong> · {{ strtoupper($import->column_map['format'] ?? 'csv') }} · {{ $analysis['rows'] ?? 'Unknown' }} response rows · {{ count($headers) }} columns</p>
    <p class="mt-2 text-sm text-slate-500">Unique exact question labels are suggested automatically. Additional fields appear in the response table as imported text; the published form stays unchanged. Detected types describe values, not their meaning.</p>
    @if(! isset($selectedMap['email']))<p class="mt-3 text-sm text-amber-700">Email mapping needs review. Choose the participant email column below.</p>@endif
    @if($analysis['irregular_rows'] ?? 0)<p class="mt-3 text-sm text-amber-700">{{ $analysis['irregular_rows'] }} rows have a different cell count. The dry check identifies those rows.</p>@endif
    <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">Review detected columns and mapping status</summary>
        <div class="mt-3 overflow-x-auto"><table class="w-full min-w-[600px] text-left text-sm"><thead><tr><th class="p-2">Column</th><th class="p-2">Detected values</th><th class="p-2">Blank cells</th><th class="p-2">Mapping</th></tr></thead><tbody>
        @foreach($headers as $index => $header)
            @php $logical = array_search((string) $index, array_map('strval', $selectedMap), true); $answerKey = array_search((string) $index, array_map('strval', array_filter($answerMap, fn ($value) => $value !== '' && $value !== null)), true); @endphp
            <tr class="border-t border-slate-200"><td class="max-w-80 break-words p-2">{{ $header }}</td><td class="p-2">{{ $analysis['columns'][$index]['type'] ?? 'text' }}@if($analysis['columns'][$index]['mixed'] ?? false) (mixed) @endif</td><td class="p-2">{{ $analysis['columns'][$index]['empty'] ?? '—' }}</td><td class="p-2">{{ $logical !== false ? ($fields[$logical] ?? $logical) : ($answerKey !== false ? 'Form question: '.($answerColumns[$answerKey]['label'] ?? '') : 'Additional imported field') }}</td></tr>
        @endforeach
        </tbody></table></div>
    </details>
    @foreach($answerColumns as $key => $column)
        @if($column['required'] && ! isset($answerMap[$key]))<p class="mt-2 text-sm text-amber-700">Required native question not matched: {{ $column['label'] }}. Map it if this export uses that question; otherwise verify the external completion columns below.</p>@endif
    @endforeach
</section>

@if($errors->any())
    <div class="mb-5 max-w-2xl rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[13px] leading-5 text-red-900">
        {{ $errors->first() }}
    </div>
@endif

@if(session('error'))
    <div role="alert" class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-900">{{ session('error') }}</div>
@endif

@if(! empty($import->column_map['last_error']))
    <div class="mb-5 max-w-2xl rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-900">
        <p class="font-semibold">Last attempt could not be completed</p>
        <p class="mt-0.5">{{ $import->column_map['last_error'] }}</p>
        <p class="mt-1 text-[12px]">Correct the columns or options below and run the dry check again. The pending upload was kept for retry.</p>
    </div>
@endif

@if(session('dry_run'))
    @php
        $dryRun = session('dry_run');
    @endphp
    <div class="mb-5 max-w-2xl rounded-lg border border-cyan-200 bg-cyan-50 px-4 py-3 text-[13px] leading-5 text-cyan-950">
        <p class="font-semibold">Dry run complete — no participant or response was saved.</p>
        <p class="mt-0.5">{{ $dryRun['valid'] }} would import; {{ $dryRun['duplicate'] }} duplicate, {{ $dryRun['unmatched'] }} unmatched, and {{ $dryRun['invalid'] }} invalid row{{ $dryRun['invalid'] === 1 ? '' : 's' }} would be held for review.</p>
        <p class="mt-2">Total checked: {{ $dryRun['total'] }}. Changing columns or options requires another dry check. Results are checked again when you import.</p>
    </div>
    @if(! empty($dryRun['sample']))
        <section class="panel mb-5 overflow-hidden">
            <div class="px-5 py-4"><p class="font-semibold">Interpreted certificate names and results — sample</p><p class="text-sm text-slate-500">Check these values against the original responses. The source preview below shows the original cells.</p></div>
            <div class="overflow-x-auto"><table class="min-w-[700px] w-full text-left text-sm">
                <thead><tr><th class="p-3">Row</th><th class="p-3">Certificate name</th><th class="p-3">Email</th><th class="p-3">Points</th><th class="p-3">Timestamp (UTC)</th></tr></thead>
                <tbody>@foreach($dryRun['sample'] as $sample)<tr class="border-t border-slate-100"><td class="p-3">{{ $sample['row'] }}</td><td class="p-3">{{ $sample['name'] ?: 'Missing — correct before issuance' }}</td><td class="p-3">{{ $sample['email'] }}</td><td class="p-3">{{ $sample['score'] ?? '—' }}@if($sample['maximum_score'] !== null) / {{ $sample['maximum_score'] }}@endif</td><td class="p-3">{{ $sample['submitted_at'] }}</td></tr>@endforeach</tbody>
            </table></div>
        </section>
    @endif
    @if(! empty($dryRun['details']))
        <section class="panel mb-5 p-5"><p class="font-semibold">Rows needing attention (up to 100 shown)</p>
            <ul class="mt-3 space-y-2 text-sm">@foreach($dryRun['details'] as $detail)<li><strong>Row {{ $detail['row_number'] }}:</strong> {{ $detail['issue_message'] }}</li>@endforeach</ul>
            <p class="mt-3 text-sm text-slate-500">Correct the original CSV and upload it again, or import the valid rows and use the reconciliation report to account for every held row.</p>
        </section>
    @endif
@endif

<div class="mb-5 max-w-5xl rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[12px] leading-5 text-amber-900">
    <p class="font-semibold">Wrong-file check</p>
    <p class="mt-0.5">This file will import into <strong>{{ $import->form->title }} ({{ $import->form->type }})</strong> of webinar <strong>{{ $webinar->title }}</strong>.
    @if($isRegistration)
        Registration creates verified participants — a score column here usually means this is a quiz file uploaded to the wrong form.
    @else
        Non-registration files never create participants — unknown emails are held for review. A name-only file with no answers here usually means this is the registration file uploaded to the wrong form.
    @endif
    If the destination looks wrong, discard this upload and start again from the correct form.</p>
    @if($headerChangeNote ?? false)
        <p class="mt-2 border-t border-amber-200 pt-2"><span class="font-semibold">Header change detected:</span> {{ $headerChangeNote }}</p>
    @endif
</div>

<section class="panel mb-5 max-w-5xl overflow-hidden">
    <div class="border-b border-slate-100 px-5 py-4">
        <p class="text-[14px] font-semibold text-slate-950">Parser check — first {{ count($previewRows) }} response row{{ count($previewRows) === 1 ? '' : 's' }}</p>
        <p class="mt-0.5 text-[12px] leading-5 text-slate-500">These values are read directly from the temporary upload, not guessed or saved. Confirm that quoted values, commas, and column positions look right before importing.</p>
    </div>
    @if($previewRows === [])
        <p class="px-5 py-4 text-[13px] text-amber-800">The file has headers but no response rows. Nothing will be imported.</p>
    @else
        <div class="overflow-x-auto">
            <table class="min-w-full text-left text-[12px]">
                <thead class="border-b border-slate-200 bg-slate-50 text-[11px] uppercase tracking-[0.06em] text-slate-500">
                    <tr>
                        <th class="px-3 py-2.5 font-semibold">Row</th>
                        @foreach($headers as $header)
                            <th class="min-w-36 max-w-56 px-3 py-2.5 font-semibold">{{ Str::limit($header, 40) }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach($previewRows as $preview)
                        <tr @class(['bg-red-50/60' => $preview['column_count'] !== count($headers)])>
                            <td class="whitespace-nowrap px-3 py-2.5 font-medium tabular-nums text-slate-700">{{ $preview['row_number'] }}</td>
                            @foreach($headers as $index => $header)
                                <td class="max-w-56 truncate px-3 py-2.5 text-slate-600" title="{{ $preview['cells'][$index] ?? '' }}">{{ Str::limit($preview['cells'][$index] ?? '—', 50) }}</td>
                            @endforeach
                        </tr>
                        @if($preview['column_count'] !== count($headers))
                            <tr class="bg-red-50/60"><td colspan="{{ count($headers) + 1 }}" class="px-3 pb-2.5 text-red-800">This row has {{ $preview['column_count'] }} columns; the header has {{ count($headers) }}. It will be held for review, not interpreted.</td></tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</section>

<form method="POST" action="{{ route('admin.webinars.imports.store', [$webinar, $import]) }}" class="max-w-2xl" id="import-map-form">
    @csrf
    <section class="panel divide-y divide-slate-100">
        @foreach($fields as $field => $label)
            @php
                $required = $field === 'email' || ($isRegistration && in_array($field, ['full_name', 'first_name', 'last_name'], true));
                $current = old('map.'.$field, $map[$field] ?? null);
            @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-slate-900">{{ $label }}@if($required)<span class="ml-1 text-red-600">*</span>@endif</p>
                    <p class="mt-0.5 text-[12px] text-slate-500">
                        @if($field === 'email')
                            Required. Choose the participant email used to match this webinar's responses.
                        @elseif(in_array($field, ['first_name', 'last_name']))
                            Map both first and last name when using separate name columns instead of Full name.
                        @elseif($field === 'score')
                            Required when this form has a minimum-points rule. If mapped, every accepted row must have a valid score.
                        @elseif($field === 'maximum_score')
                            Optional maximum possible score.
                        @elseif($field === 'submitted_at')
                            Choose the source timestamp and its timezone. Missing dates are held unless you explicitly allow the import time below.
                        @elseif($field === 'full_name')
                            Choose this OR first, middle, last name and suffix. Spelling is preserved; names are never guessed.
                        @else
                            Optional.
                        @endif
                    </p>
                </div>
                <select name="map[{{ $field }}]" class="field w-full sm:w-64" aria-label="Column carrying {{ $label }}">
                    <option value="">— not present —</option>
                    @foreach($headers as $index => $header)
                        <option value="{{ $index }}" @selected((string) $current === (string) $index)>{{ Str::limit($header, 60) }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach

        <fieldset class="space-y-4 px-5 py-4">
            <legend class="pt-4 font-semibold">Map answers to form questions</legend>
            <p class="text-sm text-slate-500">Mapped answers join native answers in tables and summaries. Original cells are preserved encrypted, including unmapped columns. Checkbox exports use comma-separated choices; labels containing commas need a corrected source export.</p>
            @foreach($answerColumns as $answerKey => $column)
                <label class="field-label">{{ $column['label'] }}
                    <select class="field" name="answer_map[{{ $answerKey }}]"><option value="">Additional imported field (not a native question)</option>@foreach($headers as $index => $header)<option value="{{ $index }}" @selected((string) ($answerMap[$answerKey] ?? '') === (string) $index)>{{ $header }}</option>@endforeach</select>
                </label>
            @endforeach
        </fieldset>

        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div class="min-w-0">
                <p class="text-[13px] font-medium text-slate-900">Timestamp format</p>
                <p class="mt-0.5 text-[12px] text-slate-500">This is used only when Submitted at is mapped. Declare the order so values such as 08/09/2026 are never guessed.</p>
            </div>
            @php
                $timestampFormat = old('timestamp_format', $import->column_map['timestamp_format'] ?? 'auto');
            @endphp
            <select name="timestamp_format" class="field w-full sm:w-64" aria-label="Timestamp format">
                <option value="auto" @selected($timestampFormat === 'auto')>Automatic — reject ambiguous dates</option>
                <option value="month_day_year" @selected($timestampFormat === 'month_day_year')>Month / day / year</option>
                <option value="day_month_year" @selected($timestampFormat === 'day_month_year')>Day / month / year</option>
                <option value="iso_8601" @selected($timestampFormat === 'iso_8601')>ISO 8601 / year-month-day</option>
            </select>
        </div>

        <div class="space-y-4 px-5 py-4">
            <label class="field-label">Timezone used by the response export
                <select name="timestamp_timezone" class="field mt-1">
                    @foreach(\DateTimeZone::listIdentifiers() as $timezone)
                        <option value="{{ $timezone }}" @selected(old('timestamp_timezone', $options['timestamp_timezone'] ?? $webinar->timezone) === $timezone)>{{ $timezone }}</option>
                    @endforeach
                </select>
            </label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="allow_import_time" value="1" class="mt-1" @checked(old('allow_import_time', $options['allow_import_time'] ?? false))><span>If a timestamp is missing, record the import time and label it as such in the reconciliation report.</span></label>
            <label class="field-label">Score format
                <select name="score_mode" class="field mt-1">
                    <option value="points" @selected(old('score_mode', $options['score_mode'] ?? 'points') === 'points')>Points (8, 8.50, or 8/10)</option>
                    <option value="percentage" @selected(old('score_mode', $options['score_mode'] ?? 'points') === 'percentage')>Percentage — convert to points using the maximum</option>
                </select>
            </label>
            <label class="field-label">Maximum possible points, if not supplied in the CSV
                <input name="default_maximum_score" type="number" step="0.01" min="0.01" max="99999999.99" class="field mt-1" value="{{ old('default_maximum_score', $options['default_maximum_score'] ?? '') }}">
            </label>
            <p class="text-sm text-slate-500">Certificate minimum scores are points, not percentages. For example, 80% of 10 becomes 8 points. Mapped score cells must be filled. Conversions that would lose precision are held for review.</p>
        </div>

        @unless($isRegistration)
            <fieldset class="space-y-3 px-5 py-4">
                <legend class="pt-4 font-semibold">Which answers are required for completion?</legend>
                <p class="text-sm text-slate-500">Select one or more question columns that every accepted response must fill. Identity, score and timestamp columns cannot serve as required answers. The system checks for missing answers; it does not grade free text. Summary-only files cannot establish certificate completion.</p>
                @foreach($headers as $index => $header)
                    <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="required_columns[]" value="{{ $index }}" class="mt-1" @checked(in_array($index, old('required_columns', $options['required_columns'] ?? $defaultRequired)))><span>{{ $header }}</span></label>
                @endforeach
            </fieldset>
        @endunless

        <fieldset class="space-y-3 border-t border-slate-100 px-5 py-4">
            <legend class="pt-4 font-semibold">Source-form verification (required)</legend>
            <p class="text-sm text-slate-500">The system cannot prove this CSV is the claimed Google Form export. Identify the exact source and confirm the destination <strong>{{ $import->form->title }} ({{ $import->form->type }})</strong> before importing. Bulk sending is blocked while held rows exist.</p>
            <label class="field-label">Google Form source (title + export date/version)
                <input name="source_description" type="text" maxlength="200" required class="field mt-1" value="{{ old('source_description', $options['source_description'] ?? '') }}" placeholder="e.g. Post-Test Form v3, exported 2026-08-20">
            </label>
            <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="source_confirmed" value="1" class="mt-1" @checked(old('source_confirmed', $options['source_confirmed'] ?? false))><span>I confirm this file is the <strong>{{ $import->form->title }} ({{ $import->form->type }})</strong> export for <strong>{{ $webinar->title }}</strong>, not another form or event.</span></label>
        </fieldset>

        <div class="rounded-b-xl border-t border-slate-200 bg-slate-50 px-4 py-3 text-[12px] leading-5 text-slate-600">
            <span class="flex items-start gap-2">
                <x-icon name="shield" class="mt-px size-4 shrink-0 text-slate-400" />
                <span>Keep your original CSV for corrections. The temporary upload is deleted after import. Identical duplicates are counted once; conflicting valid responses for the same email are all held. Existing responses are never overwritten.</span>
            </span>
        </div>
    </section>

    <label class="mt-5 flex items-start gap-2 text-sm"><input type="checkbox" name="review_confirmed" value="1" class="mt-1"><span>I reviewed the dry-check results, certificate names and email column, and confirm this file belongs to <strong>{{ $webinar->title }} — {{ $import->form->title }} ({{ $import->form->type }})</strong>. Held rows will not count toward completion.</span></label>
    <div class="mt-6 flex flex-wrap items-center gap-3">
        <button class="button-secondary" type="submit" formmethod="POST" formaction="{{ route('admin.webinars.imports.dry-run', [$webinar, $import]) }}"><x-icon name="eye" class="size-4" />Run dry check</button>
        <button class="button-success"><x-icon name="check" class="size-4" />Import reviewed responses</button>
        <a class="button-secondary" href="{{ route('admin.webinars.imports.index', $webinar) }}">Not now</a>
    </div>
</form>
@endsection
