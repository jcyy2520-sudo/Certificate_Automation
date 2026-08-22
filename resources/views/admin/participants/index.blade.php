@extends('layouts.webinar')
@section('title', 'Participants — '.$webinar->title)
@section('content')
<x-page-header title="Participants"
               subtitle="Registration and form completion for every participant record in this webinar.">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500 tabular-nums">
            <span><strong class="font-semibold text-slate-900">{{ $participants->total() }}</strong> participant records</span>
            <span class="text-slate-300">·</span>
            <span><strong class="font-semibold text-emerald-700">{{ $completedCount }}</strong> met every requirement</span>
        </div>
    </x-slot:meta>
    <x-slot:actions>
        <a class="button-secondary" href="{{ route('admin.participants.export', $webinar) }}" download><x-icon name="download" class="size-4" />Download CSV</a>
    </x-slot:actions>
</x-page-header>

<form method="POST" action="{{ route('admin.participants.filter', $webinar) }}" class="mb-6 flex flex-wrap items-center gap-3">
    @csrf
    <label class="chip w-72">
        <x-icon name="search" class="size-[18px] text-slate-400" />
        <input name="search" value="{{ $participantFilters['search'] ?? '' }}" placeholder="Search name or email" autocomplete="off">
    </label>
    <label class="chip w-64">
        <x-icon name="filter" class="size-[18px] text-slate-400" />
        <select name="filter" data-auto-submit>
            <option value="">Everyone</option>
            <option value="complete" @selected(($participantFilters['filter'] ?? '') === 'complete')>Completed all requirements</option>
            <option value="incomplete" @selected(($participantFilters['filter'] ?? '') === 'incomplete')>Still missing something</option>
        </select>
    </label>
    <button class="button-secondary">Apply</button>
    @if($participantFilters !== [])
        <button class="text-[13px] font-medium text-slate-500 hover:text-slate-900" name="clear" value="1">Clear</button>
    @endif
</form>

@if(filled($participantFilters['filter'] ?? null))
    <p class="mb-4 text-[12px] text-slate-500">Filtering applies to every matching participant record before pagination.</p>
@endif

<div class="panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-[11px] uppercase tracking-[0.06em] text-slate-500">
                <tr>
                    <th class="px-5 py-3 font-semibold">Full name</th>
                    <th class="px-5 py-3 font-semibold">Email</th>
                    <th class="px-5 py-3 font-semibold">Organization</th>
                    @foreach($forms as $form)
                        <th class="px-5 py-3 font-semibold">{{ $form->title }}</th>
                    @endforeach
                    <th class="px-5 py-3 font-semibold">Meets all requirements</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($participants as $participant)
                    <tr class="transition hover:bg-slate-50">
                        <td class="px-5 py-3.5">
                            <a href="{{ route('admin.participants.show', [$webinar, $participant]) }}" class="font-medium text-slate-900 hover:text-accent-700">{{ $participant->full_name ?: 'Erased participant' }}</a>
                        </td>
                        <td class="px-5 py-3.5 text-slate-600">{{ $participant->email ?: '—' }}</td>
                        <td class="px-5 py-3.5 text-slate-600">{{ $participant->organization ?: '—' }}</td>
                        @foreach($forms as $form)
                            @php
                                $submission = $participant->submissions->where('form_id', $form->id)->sortByDesc('attempt_number')->first();
                                $completed = $form->type === 'registration' ? $participant->verified_at !== null : $submission !== null;
                            @endphp
                            <td class="px-5 py-3.5">
                                <span class="badge {{ $completed ? 'badge-green' : 'badge-slate' }}">{{ $completed ? 'Yes' : 'No' }}</span>
                                @if($submission?->score !== null)
                                    <span class="mt-1 block whitespace-nowrap text-[11px] tabular-nums text-slate-500">{{ number_format((float) $submission->score, 1) }} / {{ number_format((float) $submission->maximum_score, 1) }}</span>
                                @endif
                            </td>
                        @endforeach
                        <td class="px-5 py-3.5">
                            <span class="badge {{ $participant->eligibility['eligible'] ? 'badge-green' : 'badge-slate' }}">{{ $participant->eligibility['eligible'] ? 'complete' : 'incomplete' }}</span>
                            @if($participant->eligibility['overridden'])<span class="badge badge-amber ml-1">override</span>@endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <a class="text-[13px] font-medium text-accent-600 hover:underline" href="{{ route('admin.participants.show', [$webinar, $participant]) }}">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-16 text-center text-sm text-slate-500" colspan="{{ $forms->count() + 5 }}">No participant records match this view yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6">{{ $participants->links() }}</div>
@endsection
