@extends('layouts.webinar')
@section('title', 'Inspect submission')
@section('content')
<x-page-header title="Inspect submission" :subtitle="$submission->form->title"><x-slot:actions><a class="button-secondary" href="{{ route('admin.responses.index', [$webinar, 'type' => $submission->form->type]) }}">Back to responses</a></x-slot:actions></x-page-header>
<section class="panel max-w-4xl p-5">
    <h2 class="section-title">{{ $submission->participant->full_name }}</h2><p class="mt-2 text-sm">{{ $submission->participant->email }}</p>
    <p class="mt-2 text-sm text-slate-500">{{ $submission->submitted_at?->timezone($webinar->timezone)->format('M j, Y H:i:s') }} · {{ ($submission->metadata['source'] ?? '') === 'csv_import' ? 'Imported' : 'Native' }} · {{ $submission->status }} · Attempt {{ $submission->attempt_number }}</p>
    @if($submission->score !== null)<p class="mt-2 text-sm">Score: {{ $submission->score }} / {{ $submission->maximum_score ?? '—' }} points</p>@endif
    <dl class="mt-5 break-words divide-y divide-slate-200">@foreach($columns as $key => $column)<div class="py-4"><dt class="text-sm font-semibold">{{ $column['label'] }}</dt><dd class="mt-2 whitespace-pre-wrap break-words text-sm">{{ implode(', ', $values[$key] ?? []) === '' ? 'No available answer' : implode(', ', $values[$key] ?? []) }}</dd></div>@endforeach</dl>
    @if(($submission->metadata['source'] ?? '') === 'csv_import')
        <h3 class="mt-6 text-sm font-semibold">Original imported cells</h3><p class="mt-2 text-sm text-slate-500">{{ $submission->metadata['source_description'] ?? 'Historical import' }} · Import {{ $submission->metadata['import_id'] ?? '—' }}, row {{ $submission->metadata['import_row_number'] ?? '—' }}</p>
        <dl class="mt-4 break-words divide-y divide-slate-200">@forelse($submission->imported_data['original'] ?? [] as $cell)<div class="py-3"><dt class="text-sm font-medium">{{ $cell['label'] }}</dt><dd class="mt-1 whitespace-pre-wrap break-words text-sm">{{ $cell['value'] }}</dd></div>@empty<p class="text-sm text-amber-700">This historical import retained completion and score only. Original answer cells are unavailable; use the original source export for review.</p>@endforelse</dl>
    @endif
    <a class="button-secondary mt-5" href="{{ route('admin.participants.show', [$webinar, $submission->participant]) }}">Participant history and audited overrides</a>
</section>
@endsection
