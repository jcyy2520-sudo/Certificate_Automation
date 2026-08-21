@extends('layouts.webinar')
@section('title', 'Participants — '.$webinar->title)
@section('content')
@php
    // Attendance is inferred from any submission to a non-registration stage.
    $nonRegFormIds = $forms->where('type', '!=', 'registration')->pluck('id')->all();
@endphp

<x-page-header title="Registered participants"
               subtitle="Everyone who registered for this webinar, with their attendance and completion status.">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500 tabular-nums">
            <span><strong class="font-semibold text-slate-900">{{ $participants->total() }}</strong> registered</span>
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
    <p class="mb-4 text-[12px] text-slate-500">Filtering applies to every matching registration before pagination.</p>
@endif

<div class="panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-[11px] uppercase tracking-[0.06em] text-slate-500">
                <tr>
                    <th class="px-5 py-3 font-semibold">Full name</th>
                    <th class="px-5 py-3 font-semibold">Email</th>
                    <th class="px-5 py-3 font-semibold">Organization</th>
                    <th class="px-5 py-3 font-semibold">Registered</th>
                    <th class="px-5 py-3 font-semibold">Attendance</th>
                    <th class="px-5 py-3 font-semibold">Completion</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($participants as $participant)
                    @php
                        $attended = $participant->submissions->contains(fn ($s) => in_array($s->form_id, $nonRegFormIds, true));
                    @endphp
                    <tr class="transition hover:bg-slate-50">
                        <td class="px-5 py-3.5">
                            <a href="{{ route('admin.participants.show', [$webinar, $participant]) }}" class="font-medium text-slate-900 hover:text-accent-700">{{ $participant->full_name ?: 'Erased participant' }}</a>
                        </td>
                        <td class="px-5 py-3.5 text-slate-600">{{ $participant->email ?: '—' }}</td>
                        <td class="px-5 py-3.5 text-slate-600">{{ $participant->organization ?: '—' }}</td>
                        <td class="px-5 py-3.5 tabular-nums text-slate-600">{{ $participant->created_at->format('M j, Y') }}</td>
                        <td class="px-5 py-3.5">
                            @if($attended)
                                <span class="badge badge-accent">attended</span>
                            @elseif($participant->verified_at)
                                <span class="badge badge-green">registered</span>
                            @else
                                <span class="badge badge-slate">pending</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5">
                            <span class="badge {{ $participant->eligibility['eligible'] ? 'badge-green' : 'badge-slate' }}">{{ $participant->eligibility['eligible'] ? 'complete' : 'incomplete' }}</span>
                            @if($participant->eligibility['overridden'])<span class="badge badge-amber ml-1">override</span>@endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <a class="text-[13px] font-medium text-accent-600 hover:underline" href="{{ route('admin.participants.show', [$webinar, $participant]) }}">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-16 text-center text-sm text-slate-500" colspan="7">No registrations match this view yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6">{{ $participants->links() }}</div>
@endsection
