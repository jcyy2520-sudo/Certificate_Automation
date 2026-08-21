@extends($webinar->exists ? 'layouts.webinar' : 'layouts.app')
@section('title', $webinar->exists ? 'Edit webinar' : 'New webinar')
@section('content')
@php
    // A saved webinar lives inside its workspace (the secondary sidebar gives
    // context), so it needs no breadcrumb. A brand-new one still does.
    $crumbs = $webinar->exists ? [] : ['Webinars' => route('admin.webinars.index')];
    // old('timezone') might be mid-typo (that's what tripped validation), so
    // only trust it for re-displaying the schedule fields if it actually
    // resolves — otherwise every date below would throw trying to convert
    // into a zone that doesn't exist.
    $submittedTimezone = old('timezone');
    $displayTimezone = ($submittedTimezone && \App\Models\Webinar::timezoneLabel($submittedTimezone))
        ? $submittedTimezone
        : ($webinar->timezone ?: config('app.timezone'));

    // New webinars have nothing to summarize, so every row starts open. An
    // existing one stays collapsed until you choose to edit it, or a row is
    // reopened automatically because its own field failed validation.
    $isNew = ! $webinar->exists;
    $open = [
        'title' => $isNew || $errors->has('title'),
        'description' => $isNew || $errors->has('description'),
        'status' => $isNew || $errors->has('status'),
        'timezone' => $isNew || $errors->has('timezone'),
        'retention' => $isNew || $errors->has('data_retention_days'),
        'schedule' => $isNew || $errors->hasAny(['starts_at', 'ends_at', 'registration_opens_at', 'registration_closes_at']),
    ];

    $scheduleDates = collect([
        'reg_open' => $webinar->registration_opens_at,
        'reg_close' => $webinar->registration_closes_at,
        'start' => $webinar->starts_at,
        'end' => $webinar->ends_at,
    ])->filter();
    $hasTimeline = ! $isNew && $scheduleDates->count() === 4 && ! $open['schedule'];
    if ($hasTimeline) {
        $min = $scheduleDates->min()->timestamp;
        $span = max($scheduleDates->max()->timestamp - $min, 1);
        $pct = fn ($date) => round((($date->timestamp - $min) / $span) * 100, 1);
        $regLeft = $pct($webinar->registration_opens_at);
        $regWidth = max($pct($webinar->registration_closes_at) - $regLeft, 1.5);
        $eventLeft = $pct($webinar->starts_at);
        $eventWidth = max($pct($webinar->ends_at) - $eventLeft, 1.5);
    }
@endphp

<x-page-header :title="$webinar->exists ? 'Webinar settings' : 'New webinar'" :crumbs="$crumbs"
               :subtitle="$webinar->exists ? null : 'Creating a webinar also sets up its four forms, a default requirement, and a certificate template.'" />

<form method="POST" action="{{ $webinar->exists ? route('admin.webinars.update', $webinar) : route('admin.webinars.store') }}" class="max-w-2xl">
    @csrf
    @if($webinar->exists)@method('PUT')@endif

    <section class="panel divide-y divide-slate-100">

        <details class="group" @if($open['title']) open @endif>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-slate-900">Title</p>
                    <p class="mt-0.5 text-[13px] text-slate-500 group-open:hidden">{{ $webinar->title ?: 'Not set yet' }}</p>
                </div>
                <span class="shrink-0 text-[13px] font-medium text-accent-600">
                    <span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span>
                </span>
            </summary>
            <div class="px-5 pb-5">
                <label for="title" class="sr-only">Title</label>
                <input id="title" class="field {{ $errors->has('title') ? 'field-invalid' : '' }}" name="title" value="{{ old('title', $webinar->title) }}" placeholder="Advanced Cardiac Life Support" required>
                <x-field-error :error="$errors->first('title')" />
                <p class="mt-1.5 text-[12px] text-slate-500">Shown to participants and printed on certificates.</p>
            </div>
        </details>

        <details class="group" @if($open['description']) open @endif>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-slate-900">Description</p>
                    <p class="mt-0.5 max-w-[38ch] truncate text-[13px] text-slate-500 group-open:hidden">{{ $webinar->description ?: 'No description yet' }}</p>
                </div>
                <span class="shrink-0 text-[13px] font-medium text-accent-600">
                    <span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span>
                </span>
            </summary>
            <div class="px-5 pb-5">
                <label for="description" class="sr-only">Description</label>
                <textarea id="description" class="field {{ $errors->has('description') ? 'field-invalid' : '' }}" name="description" rows="4">{{ old('description', $webinar->description) }}</textarea>
                <x-field-error :error="$errors->first('description')" />
            </div>
        </details>

        <details class="group" @if($open['status']) open @endif>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-slate-900">Status</p>
                    <p class="mt-0.5 text-[13px] text-slate-500 group-open:hidden">Draft events stay hidden from participants.</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="badge group-open:hidden {{ ($webinar->status ?: 'draft') === 'published' ? 'badge-green' : 'badge-slate' }}">{{ ucfirst($webinar->status ?: 'draft') }}</span>
                    <span class="text-[13px] font-medium text-accent-600">
                        <span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span>
                    </span>
                </div>
            </summary>
            <div class="px-5 pb-5">
                <label for="status" class="sr-only">Status</label>
                <select id="status" class="field {{ $errors->has('status') ? 'field-invalid' : '' }}" name="status">
                    @foreach(['draft', 'published', 'completed'] as $status)
                        <option value="{{ $status }}" @selected(old('status', $webinar->status ?: 'draft') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
                <x-field-error :error="$errors->first('status')" />
            </div>
        </details>

        <details class="group" @if($open['timezone']) open @endif>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-slate-900">Timezone</p>
                    <p class="mt-0.5 text-[13px] text-slate-500 group-open:hidden">Every schedule time below is shown in this zone.</p>
                </div>
                <div class="flex shrink-0 items-center gap-3 text-right">
                    <span class="group-open:hidden">
                        <span class="block text-[13px] text-slate-900">{{ $displayTimezone }}</span>
                        <span class="block text-[12px] text-slate-400">{{ \App\Models\Webinar::timezoneLabel($displayTimezone) }}</span>
                    </span>
                    <span class="text-[13px] font-medium text-accent-600">
                        <span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span>
                    </span>
                </div>
            </summary>
            <div class="px-5 pb-5">
                <label for="timezone" class="sr-only">Timezone</label>
                <input id="timezone" class="field {{ $errors->has('timezone') ? 'field-invalid' : '' }}" name="timezone" list="timezone-options" autocomplete="off"
                       value="{{ old('timezone', $webinar->timezone ?: config('app.timezone')) }}" placeholder="Search a city or zone…" required>
                <datalist id="timezone-options">
                    @foreach($timezoneOptions as $tz)
                        <option value="{{ $tz['id'] }}">{{ $tz['label'] }}</option>
                    @endforeach
                </datalist>
                <x-field-error :error="$errors->first('timezone')" />
                <p class="mt-1.5 text-[12px] text-slate-500">Start typing a city or region — for example “Toronto” finds America/Toronto.</p>
            </div>
        </details>

        <details class="group" @if($open['retention']) open @endif>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-slate-900">Privacy retention</p>
                    <p class="mt-0.5 text-[13px] text-slate-500 group-open:hidden">Participant data is erased this many days after the event ends.</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-[13px] text-slate-900 group-open:hidden">{{ $webinar->data_retention_days ?: \App\Models\Webinar::defaultRetentionDays() }} days</span>
                    <span class="text-[13px] font-medium text-accent-600">
                        <span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span>
                    </span>
                </div>
            </summary>
            <div class="px-5 pb-5">
                <label for="data_retention_days" class="sr-only">Privacy retention (days)</label>
                <input id="data_retention_days" class="field max-w-xs {{ $errors->has('data_retention_days') ? 'field-invalid' : '' }}" type="number" min="1" max="3650"
                       name="data_retention_days" value="{{ old('data_retention_days', $webinar->data_retention_days ?: \App\Models\Webinar::defaultRetentionDays()) }}" required>
                <x-field-error :error="$errors->first('data_retention_days')" />
            </div>
        </details>
    </section>

    <section class="panel mt-6 overflow-hidden">
        <details class="group" @if($open['schedule']) open @endif>
            <summary class="flex cursor-pointer list-none items-center justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <h2 class="section-title">Schedule</h2>
                <span class="text-[13px] font-medium text-accent-600">
                    <span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span>
                </span>
            </summary>

            @if($hasTimeline)
                <div class="px-5 pb-5">
                    <div class="relative pt-1">
                        <div class="relative h-1.5 rounded-full bg-slate-100">
                            <div class="absolute top-0 h-1.5 rounded-full bg-accent-200" style="left: {{ $regLeft }}%; width: {{ $regWidth }}%;"></div>
                            <div class="absolute top-0 h-1.5 rounded-full bg-accent-600" style="left: {{ $eventLeft }}%; width: {{ $eventWidth }}%;"></div>
                        </div>
                        <div class="mt-2 flex justify-between text-[11px] tabular-nums text-slate-400">
                            <span>{{ $webinar->registration_opens_at->timezone($displayTimezone)->format('M j') }}</span>
                            <span>{{ $webinar->ends_at->timezone($displayTimezone)->format('M j, g:ia') }}</span>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[12px] text-slate-500">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-accent-200"></span>Registration window</span>
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-accent-600"></span>Event window</span>
                    </div>
                    <p class="mt-3 text-[12px] text-slate-500">All times in <span class="font-medium text-slate-700">{{ $displayTimezone }}</span> · event {{ $webinar->starts_at->isFuture() ? 'starts '.$webinar->starts_at->diffForHumans() : 'started '.$webinar->starts_at->diffForHumans() }}</p>
                </div>
            @else
                <div class="px-5 pb-3 pt-0">
                    <p class="text-[13px] text-slate-500">
                        All times below are interpreted in <span class="font-medium text-slate-700">{{ $displayTimezone }}</span> (set above).
                        Leave a field blank to leave it unscheduled.
                    </p>
                </div>
            @endif

            <div class="grid gap-5 border-t border-slate-100 p-5 sm:grid-cols-2">
                <label class="field-label">Registration opens
                    <input class="field {{ $errors->has('registration_opens_at') ? 'field-invalid' : '' }}" type="datetime-local" id="registration_opens_at" name="registration_opens_at"
                           value="{{ old('registration_opens_at', $webinar->registration_opens_at?->timezone($displayTimezone)->format('Y-m-d\TH:i')) }}">
                    <x-field-error :error="$errors->first('registration_opens_at')" />
                </label>
                <label class="field-label">Registration closes
                    <input class="field {{ $errors->has('registration_closes_at') ? 'field-invalid' : '' }}" type="datetime-local" id="registration_closes_at" name="registration_closes_at"
                           value="{{ old('registration_closes_at', $webinar->registration_closes_at?->timezone($displayTimezone)->format('Y-m-d\TH:i')) }}">
                    <x-field-error :error="$errors->first('registration_closes_at')" />
                </label>
                <label class="field-label">Starts at
                    <input class="field {{ $errors->has('starts_at') ? 'field-invalid' : '' }}" type="datetime-local" id="starts_at" name="starts_at"
                           value="{{ old('starts_at', $webinar->starts_at?->timezone($displayTimezone)->format('Y-m-d\TH:i')) }}">
                    <x-field-error :error="$errors->first('starts_at')" />
                </label>
                <label class="field-label">Ends at
                    <input class="field {{ $errors->has('ends_at') ? 'field-invalid' : '' }}" type="datetime-local" id="ends_at" name="ends_at"
                           value="{{ old('ends_at', $webinar->ends_at?->timezone($displayTimezone)->format('Y-m-d\TH:i')) }}">
                    <x-field-error :error="$errors->first('ends_at')" />
                </label>
            </div>
        </details>
    </section>

    <div class="mt-6 flex items-center gap-3">
        <button class="button-primary">{{ $webinar->exists ? 'Save changes' : 'Create webinar' }}</button>
        <a class="button-secondary" href="{{ $webinar->exists ? route('admin.webinars.show', $webinar) : route('admin.webinars.index') }}">Cancel</a>
    </div>
</form>
@endsection
