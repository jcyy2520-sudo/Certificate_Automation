@extends('layouts.webinar')
@section('title', 'Form Responses — '.$webinar->title)
@section('content')
<x-page-header title="Form Responses" subtitle="Review submissions, confirm eligibility, and approve certificate recipients.">
    <x-slot:actions>
        <a class="button-secondary" href="{{ route('admin.forms.edit', [$webinar, $form]) }}">Manage form</a>
        <a class="button-primary" href="{{ route('admin.webinars.imports.create', [$webinar, 'type' => $form->type]) }}"><x-icon name="files" class="size-4" />Import Responses</a>
    </x-slot:actions>
</x-page-header>
<x-form-tabs :webinar="$webinar" :active="$form->type" context="responses" />
<div class="mb-5 flex flex-wrap items-center justify-between gap-3">
    <nav class="flex flex-wrap gap-2" aria-label="Response views">
        @foreach(['responses' => 'Responses', 'summary' => 'Summary', 'eligibility' => 'Eligibility review', 'settings' => 'Eligibility Settings'] as $key => $label)
            <a class="{{ $view === $key ? 'button-primary' : 'button-secondary' }}" href="{{ route('admin.responses.index', [$webinar, 'type' => $form->type, 'view' => $key]) }}" @if($view === $key) aria-current="page" @endif>{{ $label }}</a>
        @endforeach
    </nav>
    <a class="text-sm font-medium text-accent-700 underline" href="{{ route('admin.webinars.imports.index', [$webinar, 'type' => $form->type]) }}">Import history &amp; review @if($reviewCount) ({{ $reviewCount }} held) @endif</a>
</div>
@if($view === 'settings')
    @include('admin.responses.settings')
@elseif($view === 'eligibility')
    @include('admin.responses.eligibility')
@elseif($view === 'summary')
    @include('admin.responses.summary')
@else
    <section class="panel overflow-hidden">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
            <p class="text-sm"><strong>{{ $responseCount }}</strong> current submissions · {{ $rows->total() }} matching filters</p>
            <a class="button-secondary" href="{{ route('admin.responses.export', [$webinar, 'type' => $form->type]) }}"><x-icon name="download" class="size-4" />Export CSV</a>
        </div>
        <form method="POST" action="{{ route('admin.responses.filter', [$webinar, 'type' => $form->type]) }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">@csrf
            <label class="field-label min-w-48 flex-1">Search name or email<input class="field" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="180"></label>
            <label class="field-label">Source<select class="field" name="source"><option value="">All sources</option>@foreach(['native' => 'Native', 'imported' => 'Imported'] as $key => $label)<option value="{{ $key }}" @selected(($filters['source'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label class="field-label">Order<select class="field" name="sort">@foreach(['newest' => 'Newest first', 'oldest' => 'Oldest first', 'score' => 'Highest score'] as $key => $label)<option value="{{ $key }}" @selected(($filters['sort'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
            <label class="field-label">History<select class="field" name="history"><option value="">Current submissions</option><option value="all" @selected(($filters['history'] ?? '') === 'all')>Include superseded</option></select></label>
            <button class="button-primary">Apply</button><button class="button-secondary" name="clear" value="1">Clear</button>
        </form>
        <div class="response-table-scroll" tabindex="0" role="region" aria-label="{{ $form->title }} response table">
            <table class="response-table response-data-table">
                <caption class="sr-only">{{ $form->title }} submissions. Question columns scroll horizontally.</caption>
                <thead><tr><th scope="col">Participant</th><th scope="col">Submitted / source</th><th scope="col">Status / score</th>@foreach($columns as $column)<th scope="col"><span class="response-answer-preview" title="{{ $column['label'] }}">{{ $column['label'] }}</span></th>@endforeach<th scope="col">Details</th></tr></thead>
                <tbody>
                @forelse($rows as $row)
                    @php $values = $responses->displayValues($row, $columns); $sourceIdentity = $responses->sourceIdentity($row); $missing = collect($columns)->filter(fn ($column, $key) => $column['required'] && empty(array_filter($values[$key] ?? [], fn ($v) => $v !== null && trim($v) !== '')))->count(); @endphp
                    <tr>
                        <th scope="row"><a class="whitespace-pre-wrap font-semibold text-accent-700 underline" href="{{ route('admin.participants.show', [$webinar, $row->participant]) }}">{{ $sourceIdentity['name'] }}</a><span class="mt-1 block whitespace-pre-wrap font-normal">{{ $sourceIdentity['email'] }}</span>@if($sourceIdentity['name'] !== $row->participant->full_name || $sourceIdentity['email'] !== $row->participant->email)<span class="mt-2 block font-normal text-slate-500">Source spelling shown. <a class="underline" href="{{ route('admin.participants.show', [$webinar, $row->participant]) }}">Review registered identity</a></span>@endif</th>
                        <td data-label="Submitted / source">{{ $row->submitted_at?->timezone($webinar->timezone)->format('M j, Y H:i:s') }}<span class="mt-1 block text-slate-500">{{ ($row->metadata['source'] ?? '') === 'csv_import' ? 'Imported' : 'Native' }} · Attempt {{ $row->attempt_number }}@if(($row->metadata['timestamp_source'] ?? '') === 'import_time') · Import time @endif</span>@if($sourceIdentity['submitted_at'] !== null)<span class="mt-1 block whitespace-pre-wrap text-slate-500">Source time: {{ $sourceIdentity['submitted_at'] }}</span>@endif @if($row->participant->form_response_count > 1)<span class="mt-1 block text-amber-700">{{ $row->participant->form_response_count }} responses for this participant; history retained</span>@endif</td>
                        <td data-label="Status / score"><span class="badge badge-slate">{{ ucfirst($row->status) }}</span>@if($row->score !== null)<span class="mt-1 block">{{ $row->score }} / {{ $row->maximum_score ?? '—' }} points</span>@endif @if($sourceIdentity['score'] !== null)<span class="mt-1 block whitespace-pre-wrap text-slate-500">Source score: {{ $sourceIdentity['score'] }}</span>@endif @if($missing)<span class="mt-1 block text-amber-700">{{ $missing }} required answer(s) unavailable</span>@endif</td>
                        @foreach($columns as $key => $column)<td class="response-answer-cell" data-label="{{ $column['label'] }}"><x-response-value :value="implode(', ', $values[$key] ?? [])" /></td>@endforeach
                        <td data-label="Details"><a class="text-accent-700 underline" href="{{ route('admin.responses.show', [$webinar, $row]) }}">Inspect submission</a></td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($columns) + 4 }}" class="py-12 text-center"><p class="font-semibold">No responses to show</p><p class="mt-1 text-slate-500">Share this form to collect responses automatically, import an export, or clear your filters.</p></td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
        <div class="border-t border-slate-200 p-4">{{ $rows->links() }}</div>
    </section>
    <p class="mt-3 text-sm text-slate-500">Every submitted native attempt is retained. Any current submitted attempt meeting the minimum points qualifies; superseded imports never qualify. Conflicting imported duplicates are held for review without overwriting responses.</p>
@endif
@endsection
