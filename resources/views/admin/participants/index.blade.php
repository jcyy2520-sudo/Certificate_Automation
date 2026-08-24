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
        <a class="button-primary" href="#add-participant"><x-icon name="plus" class="size-4" />Add participant</a>
        <a class="button-secondary" href="{{ route('admin.participants.export', $webinar) }}" download><x-icon name="download" class="size-4" />Download CSV</a>
    </x-slot:actions>
</x-page-header>

<details id="add-participant" class="panel mb-5 scroll-mt-5 overflow-hidden" @if($errors->hasAny(['full_name', 'email', 'organization'])) open @endif>
    <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 marker:hidden">
        <span>
            <span class="block text-[14px] font-semibold text-slate-950">Add a participant to this table</span>
            <span class="mt-0.5 block text-[12px] text-slate-500">Use this for an off-platform attendee. They will be eligible and selected for certificate review.</span>
        </span>
        <span class="grid size-8 shrink-0 place-items-center rounded-lg bg-amber-50 text-amber-800"><x-icon name="plus" class="size-4" /></span>
    </summary>
    <form method="POST" action="{{ route('admin.participants.store', $webinar) }}" class="grid gap-4 border-t border-slate-100 bg-stone-50/70 px-5 py-5 md:grid-cols-[1fr_1fr_0.8fr_auto] md:items-start">
        @csrf
        <label class="field-label">Full name
            <input class="field @error('full_name') field-invalid @enderror" name="full_name" value="{{ old('full_name') }}" required maxlength="120" autocomplete="name" placeholder="Full participant name">
            @error('full_name')<span class="field-error">{{ $message }}</span>@enderror
        </label>
        <label class="field-label">Email address
            <input class="field @error('email') field-invalid @enderror" type="email" name="email" value="{{ old('email') }}" required maxlength="255" autocomplete="email" placeholder="maria@example.com">
            @error('email')<span class="field-error">{{ $message }}</span>@enderror
        </label>
        <label class="field-label">Organization <span class="font-normal text-slate-400">Optional</span>
            <input class="field @error('organization') field-invalid @enderror" name="organization" value="{{ old('organization') }}" maxlength="180" autocomplete="organization" placeholder="Organization">
            @error('organization')<span class="field-error">{{ $message }}</span>@enderror
        </label>
        <button class="button-primary mt-[22px]"><x-icon name="plus" class="size-4" />Add &amp; select</button>
    </form>
</details>

<form method="POST" action="{{ route('admin.participants.filter', $webinar) }}" class="mb-3 flex flex-wrap items-center gap-3">
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
    <button class="button-secondary"><x-icon name="search" class="size-4" />Apply filters</button>
    @if($participantFilters !== [])
        <button class="text-[13px] font-medium text-slate-500 hover:text-slate-900" name="clear" value="1">Clear filters</button>
    @endif
</form>

<p class="mb-6 text-[12px] text-slate-500">
    @if(filled($participantFilters['filter'] ?? null) || filled($participantFilters['search'] ?? null))
        Showing filtered results. Filtering applies to every matching record before pagination.
    @else
        Type a name or email and choose a status, then <span class="font-medium text-slate-600">Apply filters</span>. The status menu applies on its own.
    @endif
</p>

<form method="GET" action="{{ route('admin.certificates.studio', $webinar) }}" data-participant-certificate-selection>
<div class="mb-3 flex flex-col gap-3 rounded-2xl border border-amber-200/70 bg-amber-50/60 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
    <div><p class="text-[13px] font-semibold text-slate-900">Certificate sending</p><p class="text-[11px] text-slate-500">Select eligible participants, then review every certificate in the full-screen studio.</p></div>
    <button class="button-primary" disabled data-open-certificate-studio><x-icon name="send" class="size-4" />Send certificates <span class="rounded bg-white/15 px-1.5 py-0.5 text-[10px]" data-participant-selection-count>0</span></button>
</div>
<div class="panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-[11px] uppercase tracking-[0.06em] text-slate-500">
                <tr>
                    <th class="w-12 px-5 py-3"><input type="checkbox" class="survey-check size-[18px]" data-select-page-certificates aria-label="Select all eligible participants on this page"></th>
                    <th class="px-5 py-3 font-semibold">Full name</th>
                    <th class="px-5 py-3 font-semibold">Email</th>
                    <th class="px-5 py-3 font-semibold">Organization</th>
                    <th class="px-5 py-3 font-semibold">Attendance</th>
                    @foreach($forms as $form)
                        <th class="px-5 py-3 font-semibold">{{ $form->title }}</th>
                    @endforeach
                    <th class="px-5 py-3 font-semibold">Meets all requirements</th>
                    <th class="px-5 py-3 font-semibold">Certificate delivery</th>
                    <th class="px-5 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($participants as $participant)
                    @php
                        $hasActiveCertificate = $participant->certificates->contains(fn ($certificate) => ! $certificate->revoked_at && in_array($certificate->status, ['processing', 'issued'], true));
                        $canSendCertificate = $participant->eligibility['eligible'] && ! $hasActiveCertificate && filled($participant->email);
                        $justAdded = session('new_participant_public_id') === $participant->public_id;
                        $certificateLabels = ['not_sent' => 'Not sent', 'queued' => 'Queued', 'sending' => 'Sending', 'sent' => 'Accepted by provider', 'failed' => 'Failed'];
                        $certificateBadge = match ($participant->certificate_state) {
                            'sent' => 'badge-green',
                            'queued', 'sending' => 'badge-amber',
                            'failed' => 'badge-red',
                            default => 'badge-slate',
                        };
                    @endphp
                    <tr class="transition hover:bg-amber-50/30 {{ $justAdded ? 'bg-amber-50/70' : '' }}">
                        <td class="px-5 py-3.5"><input type="checkbox" name="participants[]" value="{{ $participant->public_id }}" class="survey-check size-[18px]" data-participant-certificate-checkbox @if($justAdded && $canSendCertificate) data-auto-selected checked @endif @disabled(! $canSendCertificate) aria-label="Select {{ $participant->full_name ?: 'participant' }} for certificate sending"></td>
                        <td class="px-5 py-3.5">
                            <a href="{{ route('admin.participants.show', [$webinar, $participant]) }}" class="font-medium text-slate-900 hover:text-accent-700">{{ $participant->full_name ?: 'Erased participant' }}</a>
                        </td>
                        <td class="px-5 py-3.5 text-slate-600">{{ $participant->email ?: '—' }}</td>
                        <td class="px-5 py-3.5 text-slate-600">{{ $participant->organization ?: '—' }}</td>
                        <td class="px-5 py-3.5">
                            <button class="badge {{ $participant->checked_in_at ? 'badge-green' : 'badge-slate' }}" form="attendance-{{ $participant->id }}" title="{{ $participant->checked_in_at?->format('M j, Y g:i A') ?? 'Attendance has not been marked' }}">
                                {{ $participant->checked_in_at ? 'Present' : 'Not marked' }}
                            </button>
                        </td>
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
                        <td class="px-5 py-3.5">
                            <span class="badge {{ $certificateBadge }}">{{ $certificateLabels[$participant->certificate_state] }}</span>
                            @if($participant->certificate_state === 'queued')
                                <span class="mt-1 block whitespace-nowrap text-[10px] text-slate-500">Waiting in background queue</span>
                            @elseif($participant->certificate_state === 'sent')
                                <span class="mt-1 block whitespace-nowrap text-[10px] text-slate-500">Provider accepted the email</span>
                            @endif
                        </td>
                        <td class="px-5 py-3.5 text-right">
                            <a class="text-[13px] font-medium text-accent-600 hover:underline" href="{{ route('admin.participants.show', [$webinar, $participant]) }}">Open</a>
                        </td>
                    </tr>
                @empty
                    <tr><td class="px-5 py-16 text-center text-sm text-slate-500" colspan="{{ $forms->count() + 8 }}">No participant records match this view yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
</form>

@foreach($participants as $participant)
    <form id="attendance-{{ $participant->id }}" method="POST" action="{{ route('admin.participants.attendance', [$webinar, $participant]) }}" class="hidden">
        @csrf
    </form>
@endforeach

<div class="mt-6">{{ $participants->links() }}</div>
@endsection
