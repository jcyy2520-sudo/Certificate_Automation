@extends('layouts.webinar')
@section('title', $webinar->title)
@section('content')
@php $displayTimezone = $webinar->timezone ?: config('app.timezone'); @endphp

<x-page-header title="Overview">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500">
            <span class="badge {{ $webinar->status === 'published' ? 'badge-green' : 'badge-slate' }}">{{ $webinar->status }}</span>
            <span class="text-slate-300">·</span>
            <span>{{ $webinar->starts_at?->timezone($displayTimezone)->format('M j, Y · g:i A') ?? 'Not scheduled' }}</span>
            <span class="text-slate-300">·</span>
            <span>{{ $webinar->timezone }}</span>
        </div>
    </x-slot:meta>
    <x-slot:actions>
        <a class="button-secondary" href="{{ route('admin.webinars.reports', $webinar) }}"><x-icon name="bar-chart" class="size-4" />Reports</a>
        <a class="button-primary" href="{{ route('admin.webinars.edit', $webinar) }}"><x-icon name="settings" class="size-4" />Settings</a>
    </x-slot:actions>
</x-page-header>

<div class="grid grid-cols-3 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200">
    @foreach([['Participants', $webinar->participants_count], ['Certificates', $webinar->certificates_count], ['Retention', $webinar->data_retention_days.' days']] as [$label, $value])
        <div class="bg-white p-5">
            <p class="text-[13px] text-slate-500">{{ $label }}</p>
            <p class="mt-2 text-[26px] font-semibold leading-none tracking-[-0.02em] tabular-nums">{{ $value }}</p>
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-[1fr_1fr]">
    <section class="panel overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 class="section-title">Forms</h2>
            <span class="text-[13px] text-slate-500">Each has its own share link</span>
        </div>
        <div class="divide-y divide-slate-100">
            @foreach($webinar->forms as $form)
                <a href="{{ route('admin.forms.edit', [$webinar, $form]) }}" class="flex items-center justify-between gap-4 px-5 py-4 transition hover:bg-slate-50">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $form->title }}</p>
                        <p class="mt-0.5 text-[12px] uppercase tracking-wide text-slate-400">{{ $form->type }}</p>
                    </div>
                    <span class="inline-flex shrink-0 items-center gap-2 text-[13px] font-medium {{ $form->acceptsResponses() ? 'text-emerald-700' : 'text-slate-400' }}">
                        <span class="size-1.5 rounded-full {{ $form->acceptsResponses() ? 'bg-emerald-500' : 'bg-slate-300' }}"></span>
                        {{ $form->acceptsResponses() ? 'Live' : 'Closed' }}
                    </span>
                </a>
            @endforeach
        </div>
    </section>

    <section class="panel overflow-hidden">
        <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
            <h2 class="section-title">Recent participants</h2>
            <a class="text-[13px] font-medium text-accent-600 hover:underline" href="{{ route('admin.participants.index', $webinar) }}">View all</a>
        </div>
        <div class="divide-y divide-slate-100">
            @forelse($recentParticipants as $participant)
                <a class="flex items-center justify-between gap-4 px-5 py-3.5 transition hover:bg-slate-50" href="{{ route('admin.participants.show', [$webinar, $participant]) }}">
                    <div class="min-w-0">
                        <p class="truncate text-sm font-medium">{{ $participant->full_name ?: 'Erased participant' }}</p>
                        <p class="mt-0.5 truncate text-[12px] text-slate-500">{{ $participant->email ?: 'Personal data erased' }}</p>
                    </div>
                    <span class="badge shrink-0 {{ $participant->verified_at ? 'badge-green' : 'badge-slate' }}">{{ $participant->verified_at ? 'registered' : 'pending' }}</span>
                </a>
            @empty
                <p class="px-5 py-12 text-center text-sm text-slate-500">No responses yet. Share a form link to collect them.</p>
            @endforelse
        </div>
    </section>
</div>

@php $issuedCertificates = $webinar->issued_certificates_count; @endphp
<section class="mt-8 rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-100 px-5 py-4">
        <h2 class="section-title">Ending this webinar</h2>
    </div>

    <div class="flex flex-col gap-4 border-b border-slate-100 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
        <div>
            <p class="text-[13px] font-medium text-slate-900">Archive</p>
            <p class="mt-0.5 text-[13px] text-slate-500">Closes every share link and hides the webinar from the active list. Nothing is lost.</p>
        </div>
        <form method="POST" action="{{ route('admin.webinars.archive', $webinar) }}" data-confirm="Archive this webinar? Its share links stop working.">@csrf
            <button class="button-secondary shrink-0">Archive</button>
        </form>
    </div>

    <details class="group px-5 py-4">
        <summary class="flex list-none cursor-pointer items-center justify-between gap-4">
            <div>
                <p class="text-[13px] font-medium text-red-600">Delete permanently</p>
                <p class="mt-0.5 text-[13px] text-slate-500">
                    Removes the forms, {{ $webinar->participants_count }} response(s){{ $issuedCertificates ? ', and '.$issuedCertificates.' issued certificate(s)' : '' }}.
                </p>
            </div>
            <span class="shrink-0 text-[13px] font-medium text-slate-500">Show</span>
        </summary>

        <form class="mt-4 max-w-md border-t border-slate-100 pt-4" method="POST" action="{{ route('admin.webinars.destroy', $webinar) }}">@csrf @method('DELETE')
            @if($issuedCertificates)
                <p class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-900">
                    <strong class="font-semibold">{{ $issuedCertificates }} certificate(s) will stop verifying.</strong>
                    Anyone holding one will get a “not found” page. Archive instead if you need those links to keep working.
                </p>
            @endif
            <label class="field-label">Type <span class="font-mono text-slate-900">{{ $webinar->title }}</span> to confirm
                <input class="field" name="confirm" autocomplete="off" placeholder="{{ $webinar->title }}">
            </label>
            <button class="button-danger mt-4">Delete this webinar</button>
        </form>
    </details>
</section>
@endsection
