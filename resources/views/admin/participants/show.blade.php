@extends('layouts.webinar')
@section('title', ($participant->full_name ?: 'Participant').' — '.$webinar->title)
@section('content')

<x-page-header :title="$participant->full_name ?: 'Erased participant'"
               :crumbs="['Registered participants' => route('admin.participants.index', $webinar)]">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500">
            <span class="badge {{ $eligibility['eligible'] ? 'badge-green' : 'badge-slate' }}">{{ $eligibility['eligible'] ? 'eligible' : 'not eligible' }}</span>
            @if($eligibility['overridden'])<span class="badge badge-amber">override</span>@endif
            @if($participant->privacy_erased_at)<span class="badge badge-slate">privacy erased</span>@endif
            <span class="text-slate-300">·</span>
            <span>{{ $participant->email ?: 'Personal data erased' }}</span>
            @if($participant->organization)<span class="text-slate-300">·</span><span>{{ $participant->organization }}</span>@endif
        </div>
    </x-slot:meta>
    <x-slot:actions>
        <form method="POST" action="{{ route('admin.participants.attendance', [$webinar, $participant]) }}">@csrf
            <button class="button-secondary"><x-icon name="check-circle" class="size-4" />{{ $participant->checked_in_at ? 'Remove attendance' : 'Mark present' }}</button>
        </form>
        <a class="button-success" href="{{ route('admin.certificates.studio', ['webinar' => $webinar, 'manual' => $participant->public_id]) }}">
            <x-icon name="award" class="size-4" />Send certificate
        </a>
    </x-slot:actions>
</x-page-header>

<div class="grid gap-6 xl:grid-cols-[minmax(0,1fr)_minmax(0,360px)]">
    <div class="space-y-6">
        <section class="panel overflow-hidden">
            <div class="border-b border-slate-100 px-5 py-4"><h2 class="section-title">Submissions</h2></div>
            <div class="divide-y divide-slate-100">
                @forelse($participant->submissions->sortByDesc('submitted_at') as $submission)
                    <div class="flex items-center justify-between gap-4 px-5 py-4">
                        <div class="min-w-0">
                            <p class="text-sm font-medium">{{ $submission->form->title }}</p>
                            <p class="mt-0.5 text-[12px] text-slate-500">Attempt {{ $submission->attempt_number }} · {{ $submission->submitted_at?->format('M j, Y g:i A') ?? 'not submitted' }}@if($submission->answers_erased_at) · answers erased @endif</p>
                        </div>
                        @if($submission->score !== null)
                            <p class="shrink-0 text-lg font-semibold tabular-nums">{{ number_format((float) $submission->score, 1) }}<span class="text-[13px] font-normal text-slate-400">/{{ number_format((float) $submission->maximum_score, 1) }}</span></p>
                        @else
                            <span class="badge badge-slate shrink-0">no score</span>
                        @endif
                    </div>
                @empty
                    <p class="px-5 py-12 text-center text-sm text-slate-500">No submissions recorded.</p>
                @endforelse
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="border-b border-slate-100 px-5 py-4"><h2 class="section-title">Certificates</h2></div>
            <div class="divide-y divide-slate-100">
                @forelse($participant->certificates as $certificate)
                    <div class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                        <div class="min-w-0">
                            <p class="font-mono text-[13px] font-medium">{{ $certificate->verification_code }}</p>
                            <p class="mt-0.5 text-[12px] text-slate-500">Issued {{ $certificate->issued_at?->format('M j, Y') ?? '—' }}@if($certificate->revoked_at) · revoked {{ $certificate->revoked_at->format('M j, Y') }}@endif</p>
                        </div>
                        <div class="flex shrink-0 flex-wrap items-center gap-2">
                            <span class="badge {{ $certificate->isPubliclyValid() ? 'badge-green' : 'badge-red' }}">{{ $certificate->status }}</span>
                            @if($certificate->file_path)<a class="button-secondary" href="{{ route('admin.certificates.download', $certificate) }}"><x-icon name="download" class="size-4" />PDF</a>@endif
                            @unless($certificate->revoked_at)
                                <form method="POST" action="{{ route('admin.certificates.revoke', $certificate) }}" data-confirm="Revoke this certificate? Public verification will show it as revoked.">@csrf
                                    <input type="hidden" name="reason" value="Revoked by administrator from the participant record.">
                                    <button class="button-danger">Revoke</button>
                                </form>
                            @endunless
                        </div>
                    </div>
                @empty
                    <p class="px-5 py-12 text-center text-sm text-slate-500">No certificate has been issued yet.</p>
                @endforelse
            </div>
        </section>
    </div>

    <div class="space-y-6">
        <section class="panel p-5">
            <h2 class="section-title">Participant name</h2>
            <p class="mt-1 text-[12px] leading-5 text-slate-500">This is the single name used on participant records and all future certificates.</p>
            <form method="POST" action="{{ route('admin.participants.name', [$webinar, $participant]) }}" class="mt-4 flex items-end gap-2">
                @csrf @method('PUT')
                <label class="field-label min-w-0 flex-1">Full name
                    <input class="field" name="full_name" value="{{ $participant->full_name }}" required maxlength="180">
                </label>
                <button class="button-secondary shrink-0">Correct name</button>
            </form>
        </section>
        <section class="panel p-5">
            <div class="flex items-center justify-between gap-4">
                <div>
                    <h2 class="section-title">Attendance</h2>
                    <p class="mt-1 text-[13px] text-slate-500">{{ $participant->checked_in_at ? 'Marked present '.$participant->checked_in_at->format('M j, Y g:i A') : 'Attendance has not been marked.' }}</p>
                </div>
                <span class="badge {{ $participant->checked_in_at ? 'badge-green' : 'badge-slate' }}">{{ $participant->checked_in_at ? 'Present' : 'Not marked' }}</span>
            </div>
        </section>

        <section class="panel p-5">
            <h2 class="section-title">Requirements</h2>
            @if($activeOverride)
                <div class="mt-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] text-amber-900">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <p class="font-semibold">Active eligibility override</p>
                            <p class="mt-1">{{ ucfirst($activeOverride->decision) }} · set by {{ $activeOverride->administrator?->name ?? 'unknown administrator' }} on {{ $activeOverride->created_at->format('M j, Y g:i A') }}</p>
                        </div>
                        <span class="badge badge-amber shrink-0">{{ $activeOverride->decision }}</span>
                    </div>
                    <p class="mt-2 text-amber-800">{{ $activeOverride->reason }}</p>
                    <form class="mt-3" method="POST" action="{{ route('admin.participants.override.destroy', [$webinar, $participant, $activeOverride]) }}" data-confirm="Remove this eligibility override? Eligibility will be recalculated immediately.">
                        @csrf @method('DELETE')
                        <button class="button-secondary">Remove override</button>
                    </form>
                </div>
            @else
                <div class="mt-4 space-y-2.5">
                    @forelse($eligibility['requirements'] as $requirement => $met)
                        <div class="flex items-center gap-3 text-[13px]">
                            <span class="grid size-5 shrink-0 place-items-center rounded-full {{ $met ? 'bg-emerald-600 text-white' : 'border border-slate-300 bg-white' }}">
                                @if($met)<x-icon name="check" class="size-3" stroke-width="2.5" />@endif
                            </span>
                            <span class="{{ $met ? 'text-slate-900' : 'text-slate-500' }}">{{ ucfirst(str_replace('_', ' ', $requirement)) }}</span>
                        </div>
                    @empty
                        <p class="text-[13px] text-slate-500">No requirements configured.</p>
                    @endforelse
                </div>
            @endif
        </section>

        <section class="panel p-5">
            <h2 class="section-title">Record an override</h2>
            <p class="mt-1 text-[13px] text-slate-500">Audited with your account and reason.</p>
            <form class="mt-4 grid gap-4" method="POST" action="{{ route('admin.participants.override', [$webinar, $participant]) }}">@csrf
                <label class="field-label">Decision<select class="field" name="decision"><option value="eligible">Eligible</option><option value="ineligible">Not eligible</option></select></label>
                <label class="field-label">Reason<textarea class="field" name="reason" rows="3" required placeholder="Explain why this decision was made.">{{ old('reason') }}</textarea></label>
                <button class="button-primary">Save override</button>
            </form>

            @if($participant->eligibilityOverrides->isNotEmpty())
                <div class="mt-5 space-y-2.5 border-t border-slate-100 pt-4">
                    <p class="eyebrow">History</p>
                    @foreach($participant->eligibilityOverrides->sortByDesc('created_at') as $override)
                        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-[13px]">
                            <p class="font-medium">{{ ucfirst($override->decision) }} · {{ $override->created_at->format('M j, Y') }}</p>
                            <p class="mt-1 text-slate-600">{{ $override->reason }}</p>
                            <p class="mt-1 text-[12px] text-slate-400">By {{ $override->administrator?->name ?? 'unknown' }}</p>
                        </div>
                    @endforeach
                </div>
            @endif
        </section>
    </div>
</div>

<section class="mt-8 rounded-xl border border-slate-200 bg-white">
    <details class="group px-5 py-4">
        <summary class="flex list-none cursor-pointer items-center justify-between gap-4">
            <div>
                <p class="text-[13px] font-medium text-red-600">Delete this response</p>
                <p class="mt-0.5 text-[13px] text-slate-500">Removes the participant and every answer they gave. Any issued certificate keeps verifying, without their name on it.</p>
            </div>
            <span class="shrink-0 text-[13px] font-medium text-slate-500">Show</span>
        </summary>
        <form class="mt-4 border-t border-slate-100 pt-4" method="POST" action="{{ route('admin.participants.destroy', [$webinar, $participant]) }}" data-confirm="Delete this participant and all of their responses? This cannot be undone.">@csrf @method('DELETE')
            <button class="button-danger">Delete permanently</button>
        </form>
    </details>
</section>
@endsection
