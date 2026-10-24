@extends('layouts.webinar')
@section('title', 'Unattributed evaluations — '.$webinar->title)
@section('content')
<x-page-header title="Unattributed evaluations" subtitle="Review saved source answers without assigning them to a participant.">
    <x-slot:actions>
        <a class="button-secondary" href="{{ route('admin.responses.index', [$webinar, 'type' => 'evaluation']) }}">Individual responses</a>
        <a class="button-primary" href="{{ route('admin.webinars.imports.create', [$webinar, 'type' => 'evaluation']) }}">Import evaluations</a>
    </x-slot:actions>
</x-page-header>
<p class="mb-5 text-sm text-slate-600">Each saved row is a separate source response, including identical answers. These evaluations do not establish anyone’s completion or certificate eligibility.</p>
@if($selectedImport)
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
        <nav class="flex flex-wrap gap-2" aria-label="Unattributed evaluation views">
            @foreach(['responses' => 'Responses', 'summary' => 'Literal answer summary'] as $key => $label)
                <a class="{{ $view === $key ? 'button-primary' : 'button-secondary' }}" href="{{ route('admin.unattributed.index', [$webinar, 'import' => $selectedImport->id, 'view' => $key]) }}" @if($view === $key) aria-current="page" @endif>{{ $label }}</a>
            @endforeach
        </nav>
        <a class="button-secondary" href="{{ route('admin.unattributed.export', [$webinar, 'import' => $selectedImport->id]) }}"><x-icon name="download" class="size-4" />Export original cells</a>
    </div>
    <section class="panel overflow-hidden" data-response-workspace>
        <div class="border-b border-slate-200 p-4"><p class="text-sm font-semibold">Import #{{ $selectedImport->id }} · {{ $selectedImport->valid_rows }} saved responses</p></div>
        @if($view === 'summary')
            <form method="GET" action="{{ route('admin.unattributed.index', $webinar) }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
                <input type="hidden" name="import" value="{{ $selectedImport->id }}"><input type="hidden" name="view" value="summary">
                <label class="field-label min-w-0 flex-1">Source column<select class="field" name="column">@foreach($columns as $key => $column)<option value="{{ $key }}" @selected($columnKey === $key)>{{ $column['label'] }}</option>@endforeach</select></label>
                <button class="button-primary">Show counts</button>
            </form>
            <p class="p-4 text-sm text-slate-600">{{ $summary['total'] ?? 0 }} responses · {{ $summary['distinct'] ?? 0 }} distinct literal values. Empty cells and whitespace are counted separately. Values are not split or interpreted as ratings or scores.</p>
            <div class="response-table-scroll" tabindex="0" role="region" aria-label="Literal answer counts">
                <table class="response-table response-data-table">
                    <caption class="sr-only">Every distinct literal value for the selected source column.</caption>
                    <thead><tr><th scope="col"><span class="block whitespace-pre-wrap break-words">{{ $columns[$columnKey]['label'] ?? 'Value' }}</span></th><th scope="col">Responses</th></tr></thead>
                    <tbody>@forelse($summaryRows ?? [] as $item)<tr><td data-label="Literal value"><x-response-value :value="$item['value']" />@if($item['value'] === '')<span class="text-sm text-slate-500">Empty cell</span>@elseif(trim($item['value']) === '')<span class="text-sm text-slate-500">Whitespace only · {{ mb_strlen($item['value']) }} characters</span>@endif</td><td data-label="Responses">{{ $item['count'] }}</td></tr>@empty<tr><td colspan="2" class="p-8 text-center">No saved values to summarize.</td></tr>@endforelse</tbody>
                </table>
            </div>
            @if($summaryRows)<div class="border-t border-slate-200 p-4">{{ $summaryRows->links() }}</div>@endif
        @else
            <x-response-table-controls :columns="$columns" />
            <div class="response-table-scroll" tabindex="0" role="region" aria-label="Unattributed evaluation responses">
                <table class="response-table response-data-table">
                    <caption class="sr-only">Complete original cells from import #{{ $selectedImport->id }}. Each source row remains distinct.</caption>
                    <thead><tr><th scope="col">Source row</th>@foreach($columns as $key => $column)<th scope="col" data-question-column="{{ $key }}"><span class="block whitespace-pre-wrap break-words">{{ $column['label'] }}</span></th>@endforeach<th scope="col">Details</th></tr></thead>
                    <tbody>
                    @forelse($rows as $row)
                        @php($original = $row->original_data)
                        <tr>
                            <th scope="row">Row {{ $row->row_number }}<span class="mt-1 block font-normal text-slate-500">Unattributed</span></th>
                            @foreach($columns as $key => $column)<td class="response-answer-cell" data-label="{{ $column['label'] }}" data-question-column="{{ $key }}"><x-response-value :value="$original[$column['source_index']] ?? ''" /></td>@endforeach
                            <td data-label="Details"><a class="text-accent-700 underline" href="{{ route('admin.unattributed.show', [$webinar, $row]) }}">Inspect original row</a></td>
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($columns) + 2 }}" class="py-12 text-center">No saved responses in this import. Review held rows in import history.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="border-t border-slate-200 p-4">@if($rows->perPage() < 25)<p class="mb-3 text-sm text-slate-500">{{ $rows->perPage() }} {{ Str::plural('response', $rows->perPage()) }} per page for this wide table. Use Next to review the remaining responses.</p>@endif{{ $rows->links() }}</div>
        @endif
    </section>
@else
    <section class="panel p-8"><p class="font-semibold">No unattributed evaluations saved yet</p><p class="mt-2 text-sm text-slate-600">Import an evaluation export and choose Unattributed evaluation to keep its original answers here.</p></section>
@endif
<section class="panel mt-6 p-4">
    <h2 class="text-base font-semibold">Saved evaluation imports</h2>
    <ul class="mt-3 space-y-2">@forelse($imports as $import)<li><a class="text-accent-700 underline" href="{{ route('admin.unattributed.index', [$webinar, 'import' => $import->id]) }}" @if($selectedImport?->id === $import->id) aria-current="page" @endif>Import #{{ $import->id }} · {{ $import->valid_rows }} saved responses · {{ $import->committed_at?->timezone($webinar->timezone)->format('M j, Y H:i') }}</a></li>@empty<li class="text-sm text-slate-500">No saved imports.</li>@endforelse</ul>
    <div class="mt-4">{{ $imports->links() }}</div>
</section>
@endsection
