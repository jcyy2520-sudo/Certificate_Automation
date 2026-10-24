@extends('layouts.webinar')
@section('title', ($step === 'review' ? 'Review results' : 'Confirm columns').' — '.$webinar->title)
@section('content')
@php
    $isRegistration = $import->form->type === 'registration';
    $options = $import->column_map['options'] ?? [];
    $analysis = $import->column_map['analysis'] ?? [];
    $responseMode = old('response_mode', $options['response_mode'] ?? 'individual');
    $unattributed = $responseMode === 'unattributed';
    $answerColumns = app(\App\Services\FormResponseService::class)->nativeColumns($import->form);
    $answerMap = old('answer_map', $options['answer_map'] ?? $import->column_map['suggested_answers'] ?? []);
    $selectedMap = array_filter(old('map', $map), fn ($value) => $value !== '' && $value !== null);
    $defaultRequired = collect($answerColumns)->filter(fn ($column, $key) => $column['required'] && isset($answerMap[$key]))->keys()->map(fn ($key) => $answerMap[$key])->all();
    $timestampFormat = old('timestamp_format', $import->column_map['timestamp_format'] ?? 'auto');
@endphp
<x-page-header :title="$step === 'review' ? 'Review results' : 'Confirm columns'" :subtitle="$import->form->title.' · '.$webinar->title">
    <x-slot:actions><form method="POST" action="{{ route('admin.webinars.imports.cancel', [$webinar, $import]) }}" data-confirm="Discard this upload? The temporary copy will be deleted." data-confirm-tone="neutral" data-confirm-action="Discard upload" data-confirm-title="Cancel import">@csrf<button class="button-secondary">Discard upload</button></form></x-slot:actions>
</x-page-header>
<x-import-steps :active="$step === 'review' ? 3 : 2" />
@if($errors->any() || session('error'))<div role="alert" class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $errors->first() ?: session('error') }}</div>@endif
@if(session('warning'))<p role="status" class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">{{ session('warning') }}</p>@endif
@if(!empty($import->column_map['last_error']))<p class="mb-5 text-sm text-amber-800">Last attempt: {{ $import->column_map['last_error'] }} Your upload is available to correct and retry.</p>@endif

@if($step === 'review')
    <section class="panel mb-5 p-5" aria-label="Dry check results">
        <h2 class="section-title">{{ $reviewResults['valid'] }} ready to import</h2>
        <p class="mt-2 text-sm">Dry run complete — no participant or response was saved. {{ $reviewResults['total'] }} rows checked.</p>
        <dl class="mt-4 grid grid-cols-2 gap-4 sm:grid-cols-4">
            @foreach(['valid' => 'Ready to import', 'duplicate' => 'Duplicates held', 'unmatched' => 'Unmatched held', 'invalid' => 'Invalid rows held'] as $key => $label)<div><dt class="text-sm text-slate-500">{{ $label }}</dt><dd class="mt-1 text-2xl font-semibold tabular-nums">{{ $reviewResults[$key] }}</dd></div>@endforeach
        </dl>
        <p class="mt-4 text-sm text-slate-500">{{ $unattributed ? 'These evaluation answers will be saved without participant attribution. Identical answers from different rows remain separate. They do not establish individual completion or certificate eligibility.' : 'Only ready responses will be attached. Held rows keep their original cells and a reason in Import history & review.' }}</p>
        <p class="mt-3 break-words text-sm"><strong>Source:</strong> {{ $options['source_description'] ?? '' }}</p>
        @if(!empty(session('dry_run.details')))<details class="mt-4"><summary class="cursor-pointer font-medium">Why rows need review</summary><ul class="mt-3 space-y-2 text-sm">@foreach(session('dry_run.details') as $detail)<li><strong>Row {{ $detail['row_number'] }}:</strong> {{ $detail['issue_message'] }}</li>@endforeach</ul><p class="mt-3 text-sm text-slate-500">Up to 100 reasons are shown here. All held rows remain available after import.</p></details>@endif
    </section>
    @if(!$unattributed && !empty(session('dry_run.sample')))
        <section class="panel mb-5 overflow-hidden"><div class="p-4"><h2 class="section-title">Interpreted certificate names and results</h2><p class="mt-1 text-sm text-slate-500">A sample of ready rows from this check. Compare the name spelling, email and result with the original cells.</p></div><div class="overflow-x-auto"><table class="record-table w-full min-w-[700px] text-left text-sm"><thead><tr><th scope="col" class="p-3">Source row</th><th scope="col" class="p-3">Certificate name</th><th scope="col" class="p-3">Email</th><th scope="col" class="p-3">Points</th><th scope="col" class="p-3">Submitted at (UTC)</th></tr></thead><tbody>@foreach(session('dry_run.sample') as $sample)<tr class="border-t border-slate-200"><td class="p-3">{{ $sample['row'] }}</td><td class="whitespace-pre-wrap break-words p-3">{{ $sample['name'] ?: 'Name unavailable' }}</td><td class="whitespace-pre-wrap break-words p-3">{{ $sample['email'] }}</td><td class="p-3">{{ $sample['score'] ?? '—' }}@if($sample['maximum_score'] !== null) / {{ $sample['maximum_score'] }}@endif</td><td class="p-3">{{ $sample['submitted_at'] }}</td></tr>@endforeach</tbody></table></div></section>
    @endif
    @include('admin.webinars.imports._source-preview')
    <form method="POST" action="{{ route('admin.webinars.imports.store', [$webinar, $import]) }}" class="max-w-3xl">@csrf
        @foreach($map as $field => $index)<input type="hidden" name="map[{{ $field }}]" value="{{ $index }}">@endforeach
        <input type="hidden" name="timestamp_format" value="{{ $import->column_map['timestamp_format'] ?? 'auto' }}">
        @foreach($options as $name => $value)
            @if(is_array($value))@foreach($value as $key => $item)<input type="hidden" name="{{ $name }}[{{ $key }}]" value="{{ $item }}">@endforeach
            @elseif($value !== null)<input type="hidden" name="{{ $name }}" value="{{ is_bool($value) ? (int) $value : $value }}">@endif
        @endforeach
        <label class="flex items-start gap-3 text-sm"><input type="checkbox" name="review_confirmed" value="1" required class="mt-1"><span>I reviewed the original cells, the destination <strong>{{ $import->form->title }}</strong>, and the results above. {{ $unattributed ? 'Save these as unattributed evaluation feedback.' : 'Import ready responses and keep the remaining rows for review.' }}</span></label>
        <div class="mt-5 flex flex-wrap gap-3"><button class="button-primary">Import reviewed responses</button><a class="button-secondary" href="{{ route('admin.webinars.imports.map', [$webinar, $import]) }}">Back to columns</a></div>
    </form>
    @includeIf('admin.webinars.imports._profiles')
@else
    <section class="panel mb-5 p-5" aria-label="Detected file structure">
        <h2 class="section-title">Detected file structure</h2>
        <p class="mt-2 break-words text-sm"><strong>{{ $import->original_filename }}</strong> · {{ strtoupper($import->column_map['format'] ?? 'csv') }} · {{ $analysis['rows'] ?? 'Unknown' }} response rows · {{ count($headers) }} columns</p>
        <p class="mt-2 text-sm text-slate-500">Unique exact question labels are suggested automatically. Additional fields appear in the response table as imported text. Detected types describe the values; confirm what each column means below.</p>
        @if(!isset($selectedMap['email']) && !$unattributed)<p class="mt-3 text-sm text-amber-800">No clear email column was found. Choose the correct column. An evaluation without identities can be saved as unattributed feedback below.</p>@endif
        @if($analysis['irregular_rows'] ?? 0)<p class="mt-3 text-sm text-amber-800">{{ $analysis['irregular_rows'] }} rows have a different cell count. The dry check identifies those rows.</p>@endif
        @if($headerChangeNote)<p class="mt-3 text-sm text-amber-800"><strong>Header change detected.</strong> {{ $headerChangeNote }}</p>@endif
        <details class="mt-4"><summary class="cursor-pointer text-sm font-semibold">Review detected columns and mapping status</summary><div class="mt-3 overflow-x-auto"><table class="w-full min-w-[600px] text-left text-sm"><thead><tr><th class="p-2">Column</th><th class="p-2">Detected values</th><th class="p-2">Blank cells</th><th class="p-2">Mapping</th></tr></thead><tbody>
            @foreach($headers as $index => $header)
                @php $logical = array_search((string) $index, array_map('strval', $selectedMap), true); $answerKey = array_search((string) $index, array_map('strval', array_filter($answerMap, fn ($v) => $v !== '' && $v !== null)), true); @endphp
                <tr class="border-t border-slate-200"><td class="max-w-80 whitespace-pre-wrap break-words p-2">{{ $header }}</td><td class="p-2">{{ $analysis['columns'][$index]['type'] ?? 'text' }}@if($analysis['columns'][$index]['mixed'] ?? false) (mixed) @endif</td><td class="p-2">{{ $analysis['columns'][$index]['empty'] ?? '—' }}</td><td class="p-2">{{ $unattributed ? 'Original evaluation field' : ($logical !== false ? ($fields[$logical] ?? $logical) : ($answerKey !== false ? 'Form question: '.($answerColumns[$answerKey]['label'] ?? '') : 'Additional imported field')) }}</td></tr>
            @endforeach
        </tbody></table></div></details>
    </section>
    @if($import->form->type === 'evaluation')
        <form method="POST" action="{{ route('admin.webinars.imports.choices', [$webinar, $import]) }}" class="panel mb-5 flex flex-wrap items-end gap-4 p-5">@csrf<input type="hidden" name="mode_only" value="1">
            <label class="field-label min-w-0 flex-1">How should these evaluations be saved?<select name="response_mode" class="field"><option value="individual" @selected(!$unattributed)>Individual responses — match by email</option><option value="unattributed" @selected($unattributed)>Unattributed evaluation — feedback only</option></select><span class="mt-1 block font-normal text-slate-500">Unattributed feedback has its own table, summary and export. It never counts toward a person's completion.</span></label><button class="button-secondary">Use this response type</button>
        </form>
    @endif
    @includeIf('admin.webinars.imports._profiles')
    @include('admin.webinars.imports._source-preview')
    <form method="POST" action="{{ route('admin.webinars.imports.dry-run', [$webinar, $import]) }}" class="max-w-4xl space-y-5" id="import-map-form">@csrf<input type="hidden" name="response_mode" value="{{ $responseMode }}">
        @if(!$unattributed)
            <section class="panel p-5"><h2 class="section-title">Confirm the participant columns</h2><p class="mt-2 text-sm text-slate-500">Participants match by email only. Names are kept as written and checked for conflicts.</p>
                <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    @foreach($fields as $field => $label)
                        @continue(!in_array($field, ['email', 'full_name', 'submitted_at']) && !isset($selectedMap[$field]))
                        @include('admin.webinars.imports._column-field')
                    @endforeach
                </div>
                <details class="mt-4"><summary class="cursor-pointer text-sm font-medium">Other identity columns and separate name parts</summary><div class="mt-4 grid gap-4 sm:grid-cols-2">@foreach($fields as $field => $label)@continue(in_array($field, ['email', 'full_name', 'submitted_at']) || isset($selectedMap[$field]))@include('admin.webinars.imports._column-field')@endforeach</div><p class="mt-3 text-sm text-slate-500">Choose a full name or both first and last names. A column can have only one role.</p></details>
            </section>
            @if($answerColumns !== [])<section class="panel p-5"><h2 class="section-title">Match answers to existing form questions</h2><p class="mt-2 text-sm text-slate-500">Only an exact match is suggested. Leave a question unmapped to retain the column as an additional imported field.</p><div class="mt-4 space-y-4">@foreach($answerColumns as $answerKey => $column)<label class="field-label whitespace-pre-wrap break-words">{{ $column['label'] }}<select class="field" name="answer_map[{{ $answerKey }}]"><option value="">Additional imported field (not a native question)</option>@foreach($headers as $index => $header)<option value="{{ $index }}" @selected((string) ($answerMap[$answerKey] ?? '') === (string) $index)>{{ $header }}</option>@endforeach</select></label>@endforeach</div></section>@endif
            @unless($isRegistration)<section class="panel p-5"><h2 class="section-title">Which answers are required for completion?</h2><p class="mt-2 text-sm text-slate-500">Choose the question columns each ready response must fill. A mapped pre/post-test quiz score can also establish submission evidence. Identity and timestamp columns cannot serve as answers.</p><div class="mt-4 space-y-3">@foreach($headers as $index => $header)<label class="flex items-start gap-3 text-sm"><input type="checkbox" name="required_columns[]" value="{{ $index }}" class="mt-1" @checked(in_array($index, old('required_columns', $options['required_columns'] ?? $defaultRequired)))><span class="whitespace-pre-wrap break-words">{{ $header }}</span></label>@endforeach</div></section>@endunless
            <section class="panel p-5"><h2 class="section-title">Dates and scores</h2><p class="mt-2 text-sm text-slate-500">Use the source date where available. If it is missing, choose whether to hold the row or record a clearly labelled import time.</p>
                <label class="mt-4 flex items-start gap-3 text-sm"><input type="checkbox" name="allow_import_time" value="1" class="mt-1" @checked(old('allow_import_time', $options['allow_import_time'] ?? false))><span>Use the import time when a response has no timestamp.</span></label>
                <details class="mt-4" @if(isset($selectedMap['submitted_at']) || isset($selectedMap['score'])) open @endif><summary class="cursor-pointer text-sm font-medium">Date format, timezone and score settings</summary><div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <label class="field-label">Timestamp format<select name="timestamp_format" class="field"><option value="auto" @selected($timestampFormat === 'auto')>Automatic — reject ambiguous dates</option><option value="month_day_year" @selected($timestampFormat === 'month_day_year')>Month / day / year</option><option value="day_month_year" @selected($timestampFormat === 'day_month_year')>Day / month / year</option><option value="iso_8601" @selected($timestampFormat === 'iso_8601')>ISO 8601 / year-month-day</option></select></label>
                    <label class="field-label">Timezone used by the response export<select name="timestamp_timezone" class="field">@foreach(\DateTimeZone::listIdentifiers() as $timezone)<option value="{{ $timezone }}" @selected(old('timestamp_timezone', $options['timestamp_timezone'] ?? $webinar->timezone) === $timezone)>{{ $timezone }}</option>@endforeach</select></label>
                    <label class="field-label">Score format<select name="score_mode" class="field"><option value="points" @selected(old('score_mode', $options['score_mode'] ?? 'points') === 'points')>Points (8, 8.50, or 8/10)</option><option value="percentage" @selected(old('score_mode', $options['score_mode'] ?? 'points') === 'percentage')>Percentage — convert to points</option></select></label>
                    <label class="field-label">Maximum possible points, when not in the file<input name="default_maximum_score" type="number" step="0.01" min="0.01" max="99999999.99" class="field" value="{{ old('default_maximum_score', $options['default_maximum_score'] ?? '') }}"></label>
                </div><p class="mt-3 text-sm text-slate-500">Minimum scores use points. For example, 80% of 10 is 8 points. Ambiguous dates and conversions that lose precision are held for review.</p></details>
            </section>
        @else
            <section class="panel p-5"><h2 class="section-title">Unattributed evaluation</h2><p class="mt-2 text-sm text-slate-600">Every original column will be saved as feedback, including blank cells, original spelling and identical answers on different rows. No identity or completion mapping is needed.</p></section>
        @endif
        <section class="panel space-y-4 p-5"><h2 class="section-title">Confirm the source and destination</h2><p class="text-sm text-slate-500">This upload will be saved to <strong>{{ $webinar->title }} — {{ $import->form->title }} ({{ $import->form->type }})</strong>.</p><label class="field-label">Source form and export date or version<input name="source_description" type="text" maxlength="200" required class="field" value="{{ old('source_description', $options['source_description'] ?? '') }}" placeholder="For example: Evaluation v2, exported 2026-10-24"></label><label class="flex items-start gap-3 text-sm"><input type="checkbox" name="source_confirmed" value="1" class="mt-1" @checked(old('source_confirmed', $options['source_confirmed'] ?? false))><span>I checked that this export belongs to this form and webinar.</span></label></section>
        <div class="flex flex-wrap items-center gap-3"><button class="button-primary">Run dry check</button><button class="button-secondary" formaction="{{ route('admin.webinars.imports.choices', [$webinar, $import]) }}">Save choices</button></div><p class="text-sm text-slate-500">Next: review ready and held counts before anything is imported. Save choices before browsing another preview page.</p>
    </form>
@endif
@endsection
