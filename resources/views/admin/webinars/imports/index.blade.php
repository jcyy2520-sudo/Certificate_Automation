@extends('layouts.webinar')
@section('title', 'Import history & review — '.$webinar->title)
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
        \App\Models\ImportIssue::TYPE_NAME_CONFLICT => 'Conflicting name',
        \App\Models\ImportIssue::TYPE_IDENTITY_CONFLICT => 'Check identity',
        \App\Models\ImportIssue::TYPE_INCOMPLETE_RESPONSE => 'Incomplete response',
        \App\Models\ImportIssue::TYPE_INVALID_TIMESTAMP => 'Invalid timestamp',
        \App\Models\ImportIssue::TYPE_INVALID_ROW_STRUCTURE => 'Malformed row',
        \App\Models\ImportIssue::TYPE_VALUE_TOO_LONG => 'Value too long',
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
<x-page-header title="Import history & review"
               :subtitle="'Response files uploaded for '.$webinar->title.', and any rows waiting for a decision.'">
    <x-slot:actions><a class="button-secondary" href="{{ route('admin.responses.index', [$webinar, 'type' => request('type', 'registration')]) }}">Back to responses</a>
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
        <table class="w-full min-w-[760px] text-left text-sm">
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
            <p class="mt-0.5 text-[12px] text-slate-500">Adding a participant attaches this response but does not bypass certificate requirements. Correct invalid or conflicting data in the original export and upload again. Dismissing records a decision to leave a row out.</p>
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
                    <details class="mt-2 text-sm">
                        <summary class="cursor-pointer">Inspect source values</summary>
                        <dl class="mt-2 space-y-1">
                            @foreach($issue->raw_data ?? [] as $header => $value)
                                @if($header !== '_resolved' && is_scalar($value))
                                    <div class="break-words"><dt class="font-medium">{{ $header }}</dt><dd>{{ $value }}</dd></div>
                                @endif
                            @endforeach
                        </dl>
                    </details>
                </div>
                <div class="flex shrink-0 items-center gap-2">
                    @if($issue->issue_type === \App\Models\ImportIssue::TYPE_DUPLICATE_EXISTING && isset($issue->raw_data['_resolved']['options']))
                        <details class="max-w-xs text-sm">
                            <summary class="cursor-pointer">Correct earlier import</summary>
                            <form method="POST" action="{{ route('admin.webinars.import-issues.replace', [$webinar, $issue]) }}" class="mt-2 space-y-2">
                                @csrf
                                <p>Replaces an earlier CSV response and keeps the original as history. Revoke any active certificate first. Live form responses are protected.</p>
                                <label class="field-label">Reason<input name="reason" class="field" required minlength="5" maxlength="500"></label>
                                <label class="flex items-start gap-2"><input type="checkbox" name="replacement_confirmed" value="1" required><span>I verified this corrected response against the source.</span></label>
                                <button class="button-secondary">Replace imported response</button>
                            </form>
                        </details>
                    @endif
                    @if($issue->issue_type === \App\Models\ImportIssue::TYPE_UNMATCHED_EMAIL)
                        <form method="POST" action="{{ route('admin.webinars.import-issues.promote', [$webinar, $issue]) }}">
                            @csrf
                            <label class="field-label">Confirmed certificate name<input name="full_name" class="field mb-2" value="{{ $issue->raw_data['_resolved']['name'] ?? '' }}" required maxlength="180"></label>
                            <label class="mb-2 flex max-w-xs items-start gap-2 text-xs"><input type="checkbox" name="response_verified" value="1" required><span>I verified the person's identity and this form's completion. Normal certificate requirements still apply.</span></label>
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
            <li class="px-5 py-10 text-center text-sm text-slate-500">No unresolved rows in this view. Use the reconciliation report to review imported and dismissed outcomes.</li>
        @endforelse
    </ul>
</section>

<div class="mt-6">{{ $imports->links() }}</div>
@endsection
