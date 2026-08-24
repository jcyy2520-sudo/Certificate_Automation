@extends('layouts.webinar')
@section('title', $webinar->title)
@section('content')
@php
    $displayTimezone = $webinar->timezone ?: config('app.timezone');
    $registrationForm = $webinar->forms->firstWhere('type', 'registration');
    $registrationLive = $registrationForm?->acceptsResponses();
@endphp

<x-page-header title="Overview">
    <x-slot:meta>
        <div class="mt-3 flex flex-wrap items-center gap-2.5 text-[13px] text-slate-500">
            <span class="badge {{ $webinar->isOpen() ? 'badge-green' : 'badge-slate' }}">{{ $webinar->availabilityLabel() }}</span>
            <span class="text-slate-300">·</span>
            <span>{{ $webinar->starts_at?->timezone($displayTimezone)->format('M j, Y · g:i A') ?? 'Not scheduled' }}</span>
        </div>
    </x-slot:meta>
    <x-slot:actions>
        <a class="button-secondary" href="{{ route('admin.webinars.reports', $webinar) }}"><x-icon name="bar-chart" class="size-4" />Reports</a>
        <a class="button-primary" href="{{ route('admin.webinars.edit', $webinar) }}"><x-icon name="settings" class="size-4" />Settings</a>
    </x-slot:actions>
</x-page-header>

@php
    // A short "where am I / what's next" checklist for running a webinar.
    $steps = [
        ['label' => 'Add webinar details', 'done' => filled($webinar->title), 'href' => route('admin.webinars.edit', $webinar), 'cta' => 'Edit details', 'hint' => 'Title, description, and schedule.'],
        ['label' => 'Open the webinar', 'done' => $webinar->isOpen(), 'href' => route('admin.webinars.edit', $webinar), 'cta' => 'Open it', 'hint' => 'Allows any forms you switch on to accept responses.'],
        ['label' => 'Upload the certificate design', 'done' => $hasCertificateDesign, 'href' => route('admin.certification.edit', $webinar), 'cta' => 'Upload design', 'hint' => 'The image each certificate is printed on.'],
        ['label' => 'Collect registrations', 'done' => $webinar->participants_count > 0, 'href' => $registrationForm ? route('admin.forms.edit', [$webinar, $registrationForm]) : route('admin.webinars.show', $webinar), 'cta' => 'Share the link', 'hint' => 'Participants register and confirm their email.'],
        ['label' => 'Send certificates', 'done' => $webinar->issued_certificates_count > 0, 'href' => route('admin.certificates.studio', $webinar), 'cta' => 'Send certificates', 'hint' => 'Once participants meet the requirements.'],
    ];
    $doneCount = collect($steps)->where('done', true)->count();
    $currentIndex = collect($steps)->search(fn ($s) => ! $s['done']);
@endphp

@if($doneCount < count($steps))
    <section class="panel mb-6 p-5">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 class="section-title">Getting this webinar ready</h2>
            <span class="text-[12px] font-medium text-slate-500 tabular-nums">{{ $doneCount }} of {{ count($steps) }} done</span>
        </div>
        <ol class="mt-4 space-y-1.5">
            @foreach($steps as $i => $step)
                @php $isCurrent = $i === $currentIndex; @endphp
                <li class="flex items-center gap-3 rounded-lg px-3 py-2.5 {{ $isCurrent ? 'bg-accent-50 ring-1 ring-accent-100' : '' }}">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full {{ $step['done'] ? 'bg-emerald-600 text-white' : ($isCurrent ? 'border-2 border-accent-600 text-accent-600' : 'border-2 border-slate-200 text-slate-300') }}">
                        @if($step['done'])<x-icon name="check" class="size-3.5" stroke-width="3" />@else<span class="text-[11px] font-semibold tabular-nums">{{ $i + 1 }}</span>@endif
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block text-[13px] font-medium {{ $step['done'] ? 'text-slate-400 line-through' : 'text-slate-900' }}">{{ $step['label'] }}</span>
                        @if($isCurrent)<span class="mt-0.5 block text-[12px] text-slate-500">{{ $step['hint'] }}</span>@endif
                    </span>
                    @if($isCurrent)
                        <a class="button-primary h-8 shrink-0 px-3 text-[13px]" href="{{ $step['href'] }}">{{ $step['cta'] }}</a>
                    @elseif(! $step['done'])
                        <a class="text-[13px] font-medium text-slate-400 hover:text-slate-600" href="{{ $step['href'] }}">{{ $step['cta'] }}</a>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif

@if($registrationForm)
    <section class="panel mb-6 overflow-hidden">
        <div class="flex flex-wrap items-start justify-between gap-4 border-b border-slate-100 bg-accent-50/40 px-5 py-4">
            <div class="min-w-0">
                <h2 class="section-title flex items-center gap-2"><x-icon name="link" class="size-[18px] text-accent-600" />Registration link</h2>
                <p class="mt-1 text-[13px] text-slate-500">Share this link so people can register for the webinar. It opens the registration form and nothing else.</p>
            </div>
            <span class="inline-flex shrink-0 items-center gap-2 rounded-full px-3 py-1 text-[12px] font-medium {{ $registrationLive ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500' }}">
                <span class="size-1.5 rounded-full {{ $registrationLive ? 'bg-emerald-500' : 'bg-slate-400' }}"></span>
                {{ $registrationLive ? 'Registration is open' : 'Not accepting registrations yet' }}
            </span>
        </div>
        <div class="p-5">
            <div class="flex flex-col gap-2 sm:flex-row">
                <input id="registration-link" class="field mt-0 flex-1 font-mono text-[13px]" value="{{ $registrationForm->shareUrl() }}" readonly data-select-on-click aria-label="Registration link">
                <div class="flex gap-2">
                    <button type="button" class="button-secondary shrink-0" data-copy-target="#registration-link" data-copy-label="Copy link" data-copy-success="Link copied"><x-icon name="copy" class="size-4" />Copy link</button>
                    <a class="button-primary shrink-0" target="_blank" rel="noopener" href="{{ $registrationForm->shareUrl() }}"><x-icon name="external" class="size-4" />Open registration page</a>
                </div>
            </div>

            @unless($registrationLive)
                <div class="mt-4 flex items-start gap-2.5 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-900">
                    <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
                    <span>
                        The link works, but the registration form isn't accepting responses yet{{ $registrationForm->closedReason() ? ' — '.rtrim($registrationForm->closedReason(), '.').'.' : '.' }}
                        @if(! $webinar->isOpen())
                            Turn on the <strong>Webinar</strong> switch in <a class="font-medium underline" href="{{ route('admin.webinars.edit', $webinar) }}">Settings</a> to allow registration.
                        @else
                            Open it from the <a class="font-medium underline" href="{{ route('admin.forms.edit', [$webinar, $registrationForm]) }}">registration form</a>.
                        @endif
                    </span>
                </div>
            @endunless
        </div>
    </section>
@endif

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
