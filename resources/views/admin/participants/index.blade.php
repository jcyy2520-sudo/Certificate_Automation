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
        <a class="button-primary" href="{{ route('admin.certificates.studio', [$webinar, 'add_recipient' => 1]) }}"><x-icon name="plus" class="size-4" />Add Recipient Manually</a>
        <a class="button-secondary" href="{{ route('admin.participants.export', $webinar) }}" download><x-icon name="download" class="size-4" />Download pre-send roster</a>
        <a class="button-secondary" href="{{ route('admin.webinars.certificates.export-zip', $webinar) }}" title="Download all issued certificate PDFs as a ZIP archive"><x-icon name="archive" class="size-4" />Download certificates (ZIP)</a>
    </x-slot:actions>
</x-page-header>



<form method="POST" action="{{ route('admin.participants.filter', $webinar) }}" class="mb-3 flex flex-wrap items-center gap-3">
    @csrf
    <label class="chip w-full sm:w-72">
        <x-icon name="search" class="size-[18px] text-slate-400" />
        <input name="search" value="{{ $participantFilters['search'] ?? '' }}" placeholder="Search name or email" autocomplete="off">
    </label>
    <label class="chip w-full sm:w-64">
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
<div class="mb-3 flex flex-col gap-3 rounded-2xl border border-indigo-100 bg-indigo-50/40 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
    <div><p class="text-[13px] font-semibold text-slate-900">Certificate sending</p><p class="text-[11px] text-slate-500">Select eligible participants, then review every certificate in the full-screen studio.</p></div>
    <button class="button-success" disabled data-open-certificate-studio><x-icon name="send" class="size-4" />Send certificates <span class="rounded bg-white/15 px-1.5 py-0.5 text-[10px]" data-participant-selection-count>0</span></button>
</div>
<div class="panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="record-table w-full min-w-[960px] text-left text-sm">
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
                        $certificateState = $participant->certificate_delivery_state;
                        $certificateStatus = $certificateState->slug();
                        $certificateBadge = match ($certificateStatus) {
                            'sent' => 'badge-green',
                            'queued', 'sending' => 'badge-amber',
                            'failed' => 'badge-red',
                            default => 'badge-slate',
                        };
                        // A greyed-out checkbox must still say why. Name the
                        // missing requirements so nobody has to guess whether
                        // the app is broken.
                        $missingRequirements = collect($participant->eligibility['requirements'] ?? [])
                            ->filter(fn ($met) => ! $met)
                            ->keys()
                            ->map(fn ($requirement) => ucfirst(str_replace('_', ' ', $requirement)))
                            ->values()
                            ->all();
                        $checkboxTitle = $canSendCertificate
                            ? 'Select for certificate sending'
                            : ($missingRequirements
                                ? 'Not eligible — missing '.implode(' and ', $missingRequirements).'. Use Add Recipient Manually in Certificate Studio for an authorized exception.'
                                : 'Not available for certificate sending');
                    @endphp
                    <tr class="transition hover:bg-amber-50/30 {{ $justAdded ? 'bg-amber-50/70' : '' }}">
                        <td class="px-5 py-3.5"><input type="checkbox" name="participants[]" value="{{ $participant->public_id }}" class="survey-check size-[18px]" data-participant-certificate-checkbox @if($justAdded && $canSendCertificate) data-auto-selected checked @endif @disabled(! $canSendCertificate) title="{{ $checkboxTitle }}" aria-label="Select {{ $participant->full_name ?: 'participant' }} for certificate sending"></td>
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
                            <span class="badge {{ $certificateBadge }}">{{ $certificateState->label() }}</span>
                            @if($certificateState->detail() !== '')
                                <span class="mt-1 block whitespace-nowrap text-[10px] text-slate-500">{{ $certificateState->detail() }}</span>
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
