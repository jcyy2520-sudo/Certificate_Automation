@extends('layouts.webinar')
@section('title', 'CSV imports — '.$webinar->title)
@section('content')
@php
    $typeLabels = [
        \App\Models\ImportIssue::TYPE_UNMATCHED_EMAIL => 'Needs review',
        \App\Models\ImportIssue::TYPE_DUPLICATE_EXISTING => 'Already recorded',
        \App\Models\ImportIssue::TYPE_DUPLICATE_IN_FILE => 'Duplicate in file',
        \App\Models\ImportIssue::TYPE_MISSING_EMAIL => 'Missing email',
        \App\Models\ImportIssue::TYPE_INVALID_EMAIL => 'Invalid email',
        \App\Models\ImportIssue::TYPE_INVALID_SCORE => 'Invalid score',
        \App\Models\ImportIssue::TYPE_MISSING_COLUMN => 'Missing column',
        \App\Models\ImportIssue::TYPE_MISSING_NAME => 'Missing name',
        \App\Models\ImportIssue::TYPE_EMPTY_ROW => 'Empty row',
    ];
    $typeBadges = [
        \App\Models\ImportIssue::TYPE_UNMATCHED_EMAIL => 'badge-amber',
        \App\Models\ImportIssue::TYPE_DUPLICATE_EXISTING => 'badge-slate',
        \App\Models\ImportIssue::TYPE_DUPLICATE_IN_FILE => 'badge-slate',
        \App\Models\ImportIssue::TYPE_MISSING_EMAIL => 'badge-red',
        \App\Models\ImportIssue::TYPE_INVALID_EMAIL => 'badge-red',
        \App\Models\ImportIssue::TYPE_INVALID_SCORE => 'badge-red',
        \App\Models\ImportIssue::TYPE_MISSING_NAME => 'badge-red',
    ];
@endphp
<x-page-header title="CSV imports"
               :subtitle="'Response files uploaded for '.$webinar->title.', and any rows waiting for a decision.'">
    <x-slot:actions>
        <a class="button-primary" href="{{ route('admin.webinars.imports.create', $webinar) }}"><x-icon name="plus" class="size-4" />Upload responses</a>
    </x-slot:actions>
</x-page-header>

@if(session('warning'))
    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-900">{{ session('warning') }}</div>
@elseif(session('error'))
    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[13px] leading-5 text-red-900">{{ session('error') }}</div>
@elseif(session('success'))
    <div class="mb-5 rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-[13px] leading-5 text-emerald-900">{{ session('success') }}</div>
@endif

<section class="panel mb-6 overflow-hidden">
    <div class="border-b border-slate-100 px-5 py-4">
        <p class="text-[14px] font-semibold text-slate-950">Uploaded files</p>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-[11px] uppercase tracking-[0.06em] text-slate-500">
                <tr>
                    <th class="px-5 py-3 font-semibold">File</th>
                    <th class="px-5 py-3 font-semibold">Form</th>
                    <th class="px-5 py-3 font-semibold">Status</th>
                    <th class="px-5 py-3 font-semibold">Rows</th>
                    <th class="px-5 py-3 font-semibold">Held for review</th>
                    <th class="px-5 py-3 font-semibold">When</th>
                    <th class="px-5 py-3 font-semibold"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($imports as $import)
                    @php
                        $statusBadge = match ($import->status) {
                            \App\Models\Import::STATUS_COMMITTED => 'badge-green',
                            \App\Models\Import::STATUS_FAILED => 'badge-red',
                            default => 'badge-slate',
                        };
                    @endphp
                    <tr class="transition hover:bg-slate-50/60">
                        <td class="max-w-[24ch] truncate px-5 py-3.5 font-medium text-slate-900" title="{{ $import->original_filename }}">{{ $import->original_filename }}</td>
                        <td class="px-5 py-3.5 text-slate-600">{{ $import->form?->title ?? '—' }}</td>
                        <td class="px-5 py-3.5"><span class="badge {{ $statusBadge }}">{{ ucfirst($import->status) }}</span></td>
                        <td class="px-5 py-3.5 tabular-nums text-slate-600">{{ $import->valid_rows }} of {{ $import->total_rows }}</td>
                        <td class="px-5 py-3.5 tabular-nums text-slate-600">{{ $import->unmatched_rows + $import->invalid_rows + $import->duplicate_rows }}</td>
                        <td class="px-5 py-3.5 whitespace-nowrap text-slate-500" title="{{ $import->created_at?->format('M j, Y g:i A') }}">{{ $import->created_at?->diffForHumans() }}<span class="block text-[11px]">{{ $import->importedBy?->name }}</span></td>
                        <td class="px-5 py-3.5 text-right">
                            @if($import->status === \App\Models\Import::STATUS_COMMITTED)
                                <a class="button-secondary !px-2.5 !py-1.5 text-[12px]" href="{{ route('admin.webinars.imports.reconciliation', [$webinar, $import]) }}">Reconciliation CSV</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="px-5 py-12 text-center text-sm text-slate-500">No response files uploaded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<section class="panel overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-5 py-4">
        <div>
            <p class="text-[14px] font-semibold text-slate-950">Waiting for your decision</p>
            <p class="mt-0.5 text-[12px] text-slate-500">Rows the import could not attach on its own. Promoting adds the participant and attaches their response; dismissing leaves everything untouched.</p>
        </div>
        <div class="flex flex-wrap gap-1.5">
            @foreach($counts as $type => $total)
                <span class="badge {{ $typeBadges[$type] ?? 'badge-slate' }}">{{ $typeLabels[$type] ?? $type }} · {{ $total }}</span>
            @endforeach
        </div>
    </div>
    <ul class="divide-y divide-slate-100">
        @forelse($issues as $issue)
            <li class="flex flex-wrap items-start gap-x-4 gap-y-2 px-5 py-4 transition hover:bg-slate-50/60">
                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="badge {{ $typeBadges[$issue->issue_type] ?? 'badge-slate' }}">{{ $typeLabels[$issue->issue_type] ?? $issue->issue_type }}</span>
                        <span class="text-[13px] font-medium tabular-nums text-slate-700">Row {{ $issue->row_number }}</span>
                        @if(filled($issue->normalized_email))
                            <span class="text-[13px] text-slate-900">{{ $issue->normalized_email }}</span>
                        @endif
                        <span class="text-[11px] text-slate-400">{{ $issue->import?->original_filename }}</span>
                    </div>
                    <p class="mt-1 text-[12px] leading-5 text-slate-500">{{ $issue->issue_message }}</p>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if($issue->issue_type === \App\Models\ImportIssue::TYPE_UNMATCHED_EMAIL)
                        <form method="POST" action="{{ route('admin.webinars.import-issues.promote', [$webinar, $issue]) }}">
                            @csrf
                            <button class="button-success !py-1.5 text-[12px]"><x-icon name="check" class="size-3.5" />Add participant &amp; attach</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.webinars.import-issues.dismiss', [$webinar, $issue]) }}"
                          data-confirm="Dismiss row {{ $issue->row_number }} without importing it?"
                          data-confirm-tone="neutral" data-confirm-action="Dismiss" data-confirm-title="Dismiss row">
                        @csrf
                        <button class="button-secondary !py-1.5 text-[12px]">Dismiss</button>
                    </form>
                </div>
            </li>
        @empty
            <li class="px-5 py-10 text-center text-sm text-slate-500">Nothing is waiting — every imported row was attached cleanly.</li>
        @endforelse
    </ul>
</section>

<div class="mt-6">{{ $imports->links() }}</div>
@endsection
