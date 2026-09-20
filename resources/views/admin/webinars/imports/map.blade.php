@extends('layouts.webinar')
@section('title', 'Map columns — '.$webinar->title)
@section('content')
@php
    $isRegistration = $import->form->type === 'registration';
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

@if($errors->any())
    <div class="mb-5 max-w-2xl rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[13px] leading-5 text-red-900">
        {{ $errors->first() }}
    </div>
@endif

@if(session('dry_run'))
    @php
        $dryRun = session('dry_run');
    @endphp
    <div class="mb-5 max-w-2xl rounded-lg border border-cyan-200 bg-cyan-50 px-4 py-3 text-[13px] leading-5 text-cyan-950">
        <p class="font-semibold">Dry run complete — nothing was saved.</p>
        <p class="mt-0.5">{{ $dryRun['valid'] }} would import; {{ $dryRun['duplicate'] }} duplicate, {{ $dryRun['unmatched'] }} unmatched, and {{ $dryRun['invalid'] }} invalid row{{ $dryRun['invalid'] === 1 ? '' : 's' }} would be held for review.</p>
    </div>
@endif

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
                        @if($field === 'score')
                            Optional numeric score for this response.
                        @elseif($field === 'maximum_score')
                            Optional maximum possible score.
                        @elseif($field === 'submitted_at')
                            Optional timestamp. Choose its order below; automatic mode refuses ambiguous slash dates. Left out, the import time is used.
                        @elseif($field === 'full_name')
                            Used on its own or combined from first and last name.
                        @else
                            Optional.
                        @endif
                    </p>
                </div>
                <select name="map[{{ $field }}]" class="field w-64" aria-label="Column carrying {{ $label }}">
                    <option value="">— not present —</option>
                    @foreach($headers as $index => $header)
                        <option value="{{ $index }}" @selected((string) $current === (string) $index)>{{ Str::limit($header, 60) }}</option>
                    @endforeach
                </select>
            </div>
        @endforeach

        <div class="flex flex-wrap items-center justify-between gap-3 px-5 py-4">
            <div class="min-w-0">
                <p class="text-[13px] font-medium text-slate-900">Timestamp format</p>
                <p class="mt-0.5 text-[12px] text-slate-500">This is used only when Submitted at is mapped. Declare the order so values such as 08/09/2026 are never guessed.</p>
            </div>
            @php
                $timestampFormat = old('timestamp_format', $import->column_map['timestamp_format'] ?? 'auto');
            @endphp
            <select name="timestamp_format" class="field w-64" aria-label="Timestamp format">
                <option value="auto" @selected($timestampFormat === 'auto')>Automatic — reject ambiguous dates</option>
                <option value="month_day_year" @selected($timestampFormat === 'month_day_year')>Month / day / year</option>
                <option value="day_month_year" @selected($timestampFormat === 'day_month_year')>Day / month / year</option>
                <option value="iso_8601" @selected($timestampFormat === 'iso_8601')>ISO 8601 / year-month-day</option>
            </select>
        </div>

        <div class="rounded-b-xl border-t border-slate-200 bg-slate-50 px-4 py-3 text-[12px] leading-5 text-slate-600">
            <span class="flex items-start gap-2">
                <x-icon name="shield" class="mt-px size-4 shrink-0 text-slate-400" />
                <span>The original file is deleted as soon as the import completes. Duplicate emails inside the file, and rows whose email already has a recorded response here, are skipped and listed for review — nothing is overwritten.</span>
            </span>
        </div>
    </section>

    <div class="mt-6 flex items-center gap-3">
        <button class="button-success"><x-icon name="check" class="size-4" />Import responses</button>
        <button class="button-secondary" type="submit" formmethod="POST" formaction="{{ route('admin.webinars.imports.dry-run', [$webinar, $import]) }}"><x-icon name="eye" class="size-4" />Run dry check</button>
        <a class="button-secondary" href="{{ route('admin.webinars.imports.index', $webinar) }}">Not now</a>
    </div>
</form>
@endsection
