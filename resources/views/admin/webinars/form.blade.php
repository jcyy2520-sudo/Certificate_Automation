@extends($webinar->exists ? 'layouts.webinar' : 'layouts.app')
@section('title', $webinar->exists ? 'Edit webinar' : 'New webinar')
@section('content')
@php
    // A saved webinar lives inside its workspace (the secondary sidebar gives
    // context), so it needs no breadcrumb. A brand-new one still does.
    $crumbs = $webinar->exists ? [] : ['Webinars' => route('admin.webinars.index')];
    // Schedule times are shown and entered in the app's single timezone; the
    // organizer never has to pick or think about one.
    $displayTimezone = $webinar->timezone ?: config('app.timezone');

    // New webinars have nothing to summarize, so every row starts open. An
    // existing one stays collapsed until you choose to edit it, or a row is
    // reopened automatically because its own field failed validation.
    $isNew = ! $webinar->exists;
    $webinarOpen = (bool) old('is_open', $webinar->status === 'published');
    $verificationOn = (bool) old('requires_verification', $webinar->exists ? $webinar->requiresVerification() : true);
    $open = [
        'title' => $isNew || $errors->has('title'),
        'description' => $isNew || $errors->has('description'),
        'availability' => $isNew || $errors->has('is_open'),
        'retention' => $isNew || $errors->has('data_retention_days'),
        'verification' => $isNew || $errors->has('requires_verification'),
        'capacity' => $isNew || $errors->has('registration_capacity'),
        'schedule' => $isNew || $errors->hasAny(['starts_at', 'ends_at', 'registration_closes_at']),
    ];

    $scheduleDates = collect([
        'start' => $webinar->starts_at,
        'end' => $webinar->ends_at,
    ])->filter();
    $hasTimeline = ! $isNew && $scheduleDates->count() === 2 && ! $open['schedule'];
    if ($hasTimeline) {
        $min = $scheduleDates->min()->timestamp;
        $span = max($scheduleDates->max()->timestamp - $min, 1);
        $pct = fn ($date) => round((($date->timestamp - $min) / $span) * 100, 1);
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

        <details class="group" @if($open['capacity']) open @endif>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-slate-900">Registration capacity</p>
                    <p class="mt-0.5 text-[13px] text-slate-500 group-open:hidden">Limit how many participants can complete registration.</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="text-[13px] text-slate-900 group-open:hidden">{{ $webinar->registration_capacity ? number_format($webinar->registration_capacity).' participants' : 'Unlimited' }}</span>
                    <span class="text-[13px] font-medium text-accent-600">
                        <span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span>
                    </span>
                </div>
            </summary>
            <div class="px-5 pb-5">
                <label for="registration_capacity" class="sr-only">Registration capacity</label>
                <input id="registration_capacity" class="field max-w-xs {{ $errors->has('registration_capacity') ? 'field-invalid' : '' }}" type="number" min="1" step="1"
                       name="registration_capacity" value="{{ old('registration_capacity', $webinar->registration_capacity) }}" placeholder="Unlimited">
                <x-field-error :error="$errors->first('registration_capacity')" />
                <p class="mt-1.5 text-[12px] leading-5 text-slate-500">Leave blank for unlimited registration. You can change this at any time, including while registration is open.</p>
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

        <details class="group" @if($open['availability']) open @endif>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-slate-900">Webinar availability</p>
                    <p class="mt-0.5 text-[13px] text-slate-500 group-open:hidden">Open it when every participant-facing part is ready.</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="badge group-open:hidden {{ $webinarOpen ? 'badge-green' : 'badge-slate' }}">{{ $webinarOpen ? 'Open' : 'Closed' }}</span>
                    <span class="text-[13px] font-medium text-accent-600">
                        <span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span>
                    </span>
                </div>
            </summary>
            <div class="px-5 pb-5">
                <input type="hidden" name="is_open" value="0">
                <label class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                    <span class="min-w-0">
                        <span class="block text-[13px] font-medium text-slate-900" data-webinar-open-label>{{ $webinarOpen ? 'Webinar open' : 'Webinar closed' }}</span>
                        <span class="mt-0.5 block text-[12px] text-slate-500">This manual switch works alongside the schedule. Closing the webinar immediately stops all participant forms.</span>
                    </span>
                    <span class="switch">
                        <input type="checkbox" name="is_open" value="1" role="switch" data-webinar-open-switch @checked($webinarOpen) @disabled($webinar->status === 'archived')>
                    </span>
                </label>
                <x-field-error :error="$errors->first('is_open')" />

                <div class="mt-3 rounded-lg border px-4 py-3 text-[12px] leading-5 {{ $webinarOpen ? 'border-emerald-200 bg-emerald-50 text-emerald-900' : 'border-slate-200 bg-slate-50 text-slate-600' }}" data-webinar-open-help>
                    <span class="flex items-start gap-2">
                        <x-icon name="{{ $webinarOpen ? 'unlock' : 'lock' }}" class="mt-px size-4 shrink-0" />
                        <span data-webinar-open-text>{{ $webinarOpen ? 'The webinar is open. Each form still follows its own Open/Closed switch and deadline.' : 'The webinar is closed. No participant form can accept responses until you open it.' }}</span>
                    </span>
                </div>
            </div>
        </details>

        <details class="group" @if($open['verification']) open @endif>
            <summary class="flex cursor-pointer list-none items-start justify-between gap-4 px-5 py-4 hover:bg-slate-50">
                <div class="min-w-0">
                    <p class="text-[13px] font-medium text-slate-900">Email confirmation</p>
                    <p class="mt-0.5 text-[13px] text-slate-500 group-open:hidden">Ask participants to confirm their email before they open the forms.</p>
                </div>
                <div class="flex shrink-0 items-center gap-3">
                    <span class="badge group-open:hidden {{ $webinar->requiresVerification() ? 'badge-green' : 'badge-slate' }}">{{ $webinar->requiresVerification() ? 'On' : 'Off' }}</span>
                    <span class="text-[13px] font-medium text-accent-600">
                        <span class="group-open:hidden">Edit</span><span class="hidden group-open:inline">Close</span>
                    </span>
                </div>
            </summary>
            <div class="px-5 pb-5">
                <input type="hidden" name="requires_verification" value="0">
                <label class="flex items-center justify-between gap-4 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3">
                    <span class="min-w-0">
                        <span class="block text-[13px] font-medium text-slate-900">Email confirmation <span class="font-normal text-slate-400">recommended</span></span>
                        <span class="mt-0.5 block text-[12px] text-slate-500">Turn on to confirm each participant owns their email address.</span>
                    </span>
                    <span class="switch">
                        <input type="checkbox" name="requires_verification" value="1" role="switch" data-verify-switch @checked($verificationOn)>
                    </span>
                </label>

                <div class="mt-3" data-verify-help>
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 px-4 py-3 text-[12px] leading-5 text-emerald-900 {{ $verificationOn ? '' : 'hidden' }}" data-verify-help-for="on">
                        <span class="flex items-start gap-2">
                            <x-icon name="mail" class="mt-px size-4 shrink-0" />
                            <span>Participants receive an email confirmation before they can access the webinar forms. Once confirmed, they can use that email for every form and evaluation in this webinar.</span>
                        </span>
                    </div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-[12px] leading-5 text-slate-600 {{ $verificationOn ? 'hidden' : '' }}" data-verify-help-for="off">
                        <span class="flex items-start gap-2">
                            <x-icon name="info" class="mt-px size-4 shrink-0" />
                            <span>Participants can continue without confirming their email. This is quicker, but a response is only linked to whatever email address the participant types in.</span>
                        </span>
                    </div>
                </div>
                <x-field-error :error="$errors->first('requires_verification')" />
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
                <label for="data_retention_days" class="field-label">Days to keep participant data after the event ends</label>
                <input id="data_retention_days" class="field max-w-xs {{ $errors->has('data_retention_days') ? 'field-invalid' : '' }}" type="number" min="1" max="3650"
                       name="data_retention_days" value="{{ old('data_retention_days', $webinar->data_retention_days ?: \App\Models\Webinar::defaultRetentionDays()) }}" required>
                <x-field-error :error="$errors->first('data_retention_days')" />
                <div class="mt-3 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-[12px] leading-5 text-slate-600">
                    <span class="flex items-start gap-2">
                        <x-icon name="shield" class="mt-px size-4 shrink-0 text-slate-400" />
                        <span>After this many days, each participant's name, email, organization, and their form responses are permanently erased. Issued certificates keep only their verification code, event, and issue date — never personal details — so certificates stay verifiable.</span>
                    </span>
                </div>
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
                            <div class="absolute top-0 h-1.5 rounded-full bg-accent-600" style="left: {{ $eventLeft }}%; width: {{ $eventWidth }}%;"></div>
                        </div>
                        <div class="mt-2 flex justify-between text-[11px] tabular-nums text-slate-400">
                            <span>{{ $webinar->starts_at->timezone($displayTimezone)->format('M j') }}</span>
                            <span>{{ $webinar->ends_at->timezone($displayTimezone)->format('M j, g:ia') }}</span>
                        </div>
                    </div>
                    <div class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-[12px] text-slate-500">
                        <span class="inline-flex items-center gap-1.5"><span class="size-2 rounded-full bg-accent-600"></span>Event window</span>
                    </div>
                    <p class="mt-3 text-[12px] text-slate-500">Event {{ $webinar->starts_at->isFuture() ? 'starts '.$webinar->starts_at->diffForHumans() : 'started '.$webinar->starts_at->diffForHumans() }}.</p>
                </div>
            @else
                <div class="px-5 pb-3 pt-0">
                    <p class="text-[13px] text-slate-500">
                        Set an optional registration deadline and choose when the event runs. The Registration switch controls when registration opens.
                    </p>
                </div>
            @endif

            <div class="space-y-6 border-t border-slate-100 p-5">
                <div>
                    <p class="eyebrow mb-3">Registration</p>
                    <div class="grid gap-4">
                        <label class="field-label">Closing deadline <span class="font-normal text-slate-400">optional</span>
                            <input class="field {{ $errors->has('registration_closes_at') ? 'field-invalid' : '' }}" type="datetime-local" id="registration_closes_at" name="registration_closes_at"
                                   value="{{ old('registration_closes_at', $webinar->registration_closes_at?->timezone($displayTimezone)->format('Y-m-d\TH:i')) }}">
                            <x-field-error :error="$errors->first('registration_closes_at')" />
                        </label>
                    </div>
                    <p class="mt-2 text-[12px] text-slate-500">Use the Registration Open/Closed switch on the form to open it immediately. This deadline can close it automatically later.</p>
                </div>
                <div>
                    <p class="eyebrow mb-3">The event</p>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="field-label">Starts
                            <input class="field {{ $errors->has('starts_at') ? 'field-invalid' : '' }}" type="datetime-local" id="starts_at" name="starts_at"
                                   value="{{ old('starts_at', $webinar->starts_at?->timezone($displayTimezone)->format('Y-m-d\TH:i')) }}">
                            <x-field-error :error="$errors->first('starts_at')" />
                        </label>
                        <label class="field-label">Ends
                            <input class="field {{ $errors->has('ends_at') ? 'field-invalid' : '' }}" type="datetime-local" id="ends_at" name="ends_at"
                                   value="{{ old('ends_at', $webinar->ends_at?->timezone($displayTimezone)->format('Y-m-d\TH:i')) }}">
                            <x-field-error :error="$errors->first('ends_at')" />
                        </label>
                    </div>
                    <p class="mt-2 text-[12px] text-slate-500">An end time is required when the webinar is open, and remains required after it has been closed. It sets the privacy-deletion date.</p>
                </div>
            </div>
        </details>
    </section>

    <div class="mt-6 flex items-center gap-3">
        <button class="button-primary">{{ $webinar->exists ? 'Save changes' : 'Create webinar' }}</button>
        <a class="button-secondary" href="{{ $webinar->exists ? route('admin.webinars.show', $webinar) : route('admin.webinars.index') }}">Cancel</a>
    </div>
</form>

<script nonce="{{ $cspNonce }}">
    (function () {
        // Keep the Open/Closed explanation in sync before settings are saved.
        var openSwitch = document.querySelector('[data-webinar-open-switch]');
        var openLabel = document.querySelector('[data-webinar-open-label]');
        var openHelp = document.querySelector('[data-webinar-open-help]');
        var openText = document.querySelector('[data-webinar-open-text]');
        if (openSwitch && openLabel && openHelp && openText) {
            var syncOpen = function () {
                var on = openSwitch.checked;
                openLabel.textContent = on ? 'Webinar open' : 'Webinar closed';
                openText.textContent = on
                    ? 'The webinar is open. Each form still follows its own Open/Closed switch and deadline.'
                    : 'The webinar is closed. No participant form can accept responses until you open it.';
                openHelp.classList.toggle('border-emerald-200', on);
                openHelp.classList.toggle('bg-emerald-50', on);
                openHelp.classList.toggle('text-emerald-900', on);
                openHelp.classList.toggle('border-slate-200', !on);
                openHelp.classList.toggle('bg-slate-50', !on);
                openHelp.classList.toggle('text-slate-600', !on);
            };
            openSwitch.addEventListener('change', syncOpen);
            syncOpen();
        }

        // Swap the plain-language explanation as the email-confirmation switch flips.
        var verifySwitch = document.querySelector('[data-verify-switch]');
        var verifyHelp = document.querySelector('[data-verify-help]');
        if (verifySwitch && verifyHelp) {
            var syncVerify = function () {
                var state = verifySwitch.checked ? 'on' : 'off';
                verifyHelp.querySelectorAll('[data-verify-help-for]').forEach(function (el) {
                    el.classList.toggle('hidden', el.getAttribute('data-verify-help-for') !== state);
                });
            };
            verifySwitch.addEventListener('change', syncVerify);
            syncVerify();
        }
    })();
</script>
@endsection
