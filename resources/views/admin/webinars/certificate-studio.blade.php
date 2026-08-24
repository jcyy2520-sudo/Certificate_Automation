@extends('layouts.certificate-studio')
@section('title', 'Certificate studio — '.$webinar->title)
@section('content')
@php
    $backgroundUrl = $hasBackground ? route('admin.certification.background', $webinar) : null;
    $firstParticipant = $participants->first();
    $firstName = $firstParticipant?->full_name ?: 'Participant';
    $fontKey = $layout['name_font_family'] ?? 'sans';
    $sizeVal = (float) ($layout['name_font_size'] ?? 42);
    $accentVal = $layout['accent'] ?? '#1d4ed8';
    $topVal = (float) ($layout['name_top'] ?? 62);
    $leftVal = (float) ($layout['name_left'] ?? 50);
    $weightVal = $layout['name_font_weight'] ?? 'bold';
    $styleVal = $layout['name_font_style'] ?? 'regular';
    $alignVal = $layout['name_text_align'] ?? 'center';
    $readyCount = $participants->where('cert_status', 'ready')->count();
    $queuedCount = $participants->where('cert_status', 'queued')->count();
    $sendingCount = $participants->where('cert_status', 'sending')->count();
    $sentCount = $participants->where('cert_status', 'sent')->count();
    $failedCount = $participants->where('cert_status', 'failed')->count();
    $validEmailCount = $participants->filter(fn ($p) => filled($p->email))->count();
@endphp

<div class="hidden" data-design-state>
    <input name="name_top" value="{{ $topVal }}" data-design-top><input name="name_left" value="{{ $leftVal }}" data-design-left>
    <input name="name_font_family" value="{{ $fontKey }}" data-design-font-store><input name="name_font_size" value="{{ $sizeVal }}" data-design-size-store>
    <input name="accent" value="{{ $accentVal }}" data-design-color-store><input name="name_font_weight" value="{{ $weightVal }}" data-design-weight-store>
    <input name="name_font_style" value="{{ $styleVal }}" data-design-style-store><input name="name_text_align" value="{{ $alignVal }}" data-design-align-store>
</div>
<form id="send-form" class="hidden" method="POST" action="{{ route('admin.certificates.issue-selected', $webinar) }}" data-send-form>
    @csrf <input type="hidden" name="preview_confirmed" value="1"><input type="hidden" name="designs_json" value="{}" data-designs-json>
    @foreach($participants as $participant)
        <input type="checkbox" name="participants[]" value="{{ $participant->public_id }}" data-send-participant="{{ $participant->public_id }}" @checked($selectionScoped && $participant->cert_status === 'ready') @disabled($participant->cert_status !== 'ready')>
        <span data-name-store="{{ $participant->public_id }}" data-value="{{ $participant->full_name }}"></span>
    @endforeach
</form>

<div class="flex h-dvh min-h-0 flex-col bg-slate-900" data-studio data-initial-participant="{{ $firstParticipant?->public_id }}" data-status-url="{{ $statusUrl }}" data-preview-url="{{ route('admin.certification.preview', $webinar) }}">
    <header class="z-30 flex h-16 shrink-0 items-center border-b border-slate-200 bg-white px-3 sm:px-5">
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <a href="{{ $backUrl }}" class="inline-flex h-9 items-center gap-1.5 rounded-lg px-2.5 text-[13px] font-semibold text-slate-600 hover:bg-slate-100 hover:text-slate-950"><x-icon name="arrow-left" class="size-4" />Back</a>
            <button type="button" class="studio-mobile-panel-button lg:hidden" data-toggle-panel="certificates" aria-label="Open certificates"><x-icon name="files" class="size-4" /></button>
        </div>
        <div class="min-w-0 px-2 text-center">
            <div class="flex items-center justify-center gap-2"><h1 class="truncate text-sm font-semibold text-slate-950">Certificate studio</h1><span class="hidden rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-bold uppercase tracking-[0.08em] text-amber-800 sm:inline-flex">{{ $participants->count() === 1 ? 'Single certificate mode' : 'Bulk sending mode' }}</span></div>
            <p class="max-w-[42vw] truncate text-[11px] text-slate-500">{{ $webinar->title }}@if($participants->count() > 1) · {{ $participants->count() }} certificates @endif</p>
        </div>
        <div class="flex flex-1 items-center justify-end gap-1.5 sm:gap-2">
            <a class="hidden h-9 items-center gap-1.5 rounded-lg border border-slate-200 px-3 text-[12px] font-semibold text-slate-700 hover:bg-slate-50 sm:inline-flex" target="_blank" rel="noopener" href="{{ route('admin.certification.preview', $webinar, ['name' => $firstName]) }}" data-preview-pdf><x-icon name="eye" class="size-4" />Preview PDF</a>
            <button type="button" class="inline-flex h-9 items-center gap-1.5 rounded-lg bg-accent-600 px-3 text-[12px] font-semibold text-white hover:bg-accent-700 disabled:cursor-not-allowed disabled:opacity-40" data-open-send-confirm disabled><x-icon name="send" class="size-4" /><span class="hidden sm:inline" data-send-label>Send certificates</span><span class="sm:hidden">Send</span></button>
            <button type="button" class="studio-mobile-panel-button lg:hidden" data-toggle-panel="people" aria-label="Open people"><x-icon name="users" class="size-4" /></button>
        </div>
    </header>

    @if(! $template || ! $hasBackground)
        <main class="studio-empty"><div><x-icon name="image" class="mx-auto size-10 text-slate-300" /><h2>Upload a certificate design first</h2><p>The studio places participant names over your completed certificate artwork.</p><a class="button-primary mt-5" href="{{ route('admin.certification.edit', $webinar) }}">Upload certificate design</a></div></main>
    @elseif($participants->isEmpty())
        <main class="studio-empty"><div><x-icon name="award" class="mx-auto size-10 text-slate-300" /><h2>No eligible certificates to load</h2><p>Add or select recipients from the participant table first. Names and email addresses are managed in one place.</p><a class="button-primary mt-5" href="{{ $backUrl }}#add-participant"><x-icon name="users" class="size-4" />Open participants</a></div></main>
    @else
        @if($pipelineWarning)<div class="absolute left-1/2 top-[4.75rem] z-40 flex w-[min(92vw,720px)] -translate-x-1/2 items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-[12px] leading-5 text-amber-950 shadow-lg"><x-icon name="alert" class="mt-0.5 size-4 shrink-0" /><span><strong class="font-semibold">Delivery needs attention.</strong> {{ $pipelineWarning }}</span></div>@endif
        <div class="relative flex min-h-0 flex-1">
            <button type="button" class="absolute inset-0 z-40 hidden bg-slate-950/45 lg:hidden" data-panel-scrim aria-label="Close panel"></button>

            <aside class="studio-side-panel studio-left-panel" data-panel="certificates">
                <div class="border-b border-slate-200 p-4">
                    <div class="flex items-center justify-between"><div><p class="text-sm font-semibold">Certificates</p><p class="mt-0.5 text-[11px] text-slate-500"><span data-visible-certificate-count>{{ $participants->count() }}</span> in this queue</p></div><label class="flex items-center gap-1.5 text-[11px] font-semibold text-slate-600"><input type="checkbox" class="size-4 rounded border-slate-300 text-accent-700" data-select-all> All ready</label></div>
                    <label class="studio-search"><x-icon name="search" /><input placeholder="Search certificates" data-certificate-search></label>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto p-2" data-certificate-list>
                    @foreach($participants as $participant)
                        @php $certificate = $participant->certificate_record; $state = $participant->certificate_delivery_state; $status = $state->slug(); @endphp
                        <article class="studio-certificate-row" data-certificate-row="{{ $participant->public_id }}" data-search="{{ Str::lower($participant->full_name.' '.$participant->email.' '.$certificate?->verification_code) }}" data-status="{{ $status }}" data-name-update-url="{{ route('admin.participants.name', [$webinar, $participant]) }}">
                            <label class="mt-1 shrink-0" title="Include this certificate when sending"><input type="checkbox" class="size-4 rounded border-slate-300 text-amber-700" data-queue-check="{{ $participant->public_id }}" @checked($selectionScoped && $status === 'ready') @disabled($status !== 'ready')></label>
                            <button type="button" class="min-w-0 flex-1 text-left" data-preview-select="{{ $participant->public_id }}">
                                <span class="flex items-start gap-2"><span class="mt-0.5 flex h-10 w-14 shrink-0 items-center justify-center overflow-hidden rounded-md border border-slate-200 bg-slate-100"><img src="{{ $backgroundUrl }}" alt="" class="h-full w-full object-cover opacity-80"></span><span class="min-w-0 flex-1"><span class="block truncate text-[12px] font-semibold text-slate-900" data-participant-name>{{ $participant->full_name ?: 'Unnamed participant' }}</span><span class="mt-0.5 block truncate text-[11px] text-slate-500">{{ $participant->email ?: 'No email address' }}</span><span class="mt-1 block truncate font-mono text-[9px] uppercase tracking-wide text-slate-400" data-certificate-number>{{ $certificate?->verification_code ?: 'Number assigned on send' }}</span></span></span>
                                <span class="mt-2 flex items-center justify-between pl-16"><span class="certificate-status certificate-status-{{ $status }}" data-status-badge>{{ $state->label() }}</span>@if($status === 'failed')<span class="text-[10px] font-semibold text-red-600">Retry available</span>@endif</span>
                            </button>
                        </article>
                    @endforeach
                </div>
                <div class="flex items-center justify-between border-t border-slate-200 p-3"><button type="button" class="studio-icon-button" data-prev-certificate><x-icon name="chevron-left" class="size-4" /></button><span class="text-[11px] font-medium text-slate-500"><span data-active-index>1</span> / <span data-certificate-total>{{ $participants->count() }}</span></span><button type="button" class="studio-icon-button" data-next-certificate><x-icon name="chevron-right" class="size-4" /></button></div>
            </aside>

            <main class="relative flex min-w-0 flex-1 flex-col overflow-hidden bg-[#171923]">
                <div class="studio-preview-bar"><button type="button" class="studio-dark-button" data-zoom-out><x-icon name="minus" class="size-4" /></button><span class="w-14 text-center text-[11px] font-semibold tabular-nums" data-zoom-readout>100%</span><button type="button" class="studio-dark-button" data-zoom-in><x-icon name="plus" class="size-4" /></button><span class="mx-1 h-4 w-px bg-white/10"></span><button type="button" class="studio-dark-text-button" data-fit-screen>Fit screen</button><span class="mx-1 h-4 w-px bg-white/10"></span><button type="button" class="studio-dark-button" data-prev-certificate><x-icon name="chevron-left" class="size-4" /></button><button type="button" class="studio-dark-button" data-next-certificate><x-icon name="chevron-right" class="size-4" /></button><span class="mx-1 h-4 w-px bg-white/10"></span><button type="button" class="studio-dark-text-button" data-open-name-correction>Correct name</button><button type="button" class="studio-dark-text-button" data-toggle-name-adjustment>Adjust this name</button></div>
                <div class="relative min-h-0 flex-1 overflow-auto p-5 pb-20 sm:p-8 sm:pb-20" data-preview-viewport><div class="flex min-h-full items-center justify-center"><div class="w-full max-w-[980px] origin-center transition-transform duration-150" data-preview-scale><div class="relative overflow-hidden rounded-md bg-white shadow-2xl shadow-black/40 ring-1 ring-white/10" data-canvas-wrap><x-certificate-preview :name="$firstName" :layout="$layout" :background-url="$backgroundUrl" /><div class="pointer-events-none absolute inset-y-0 left-1/2 hidden w-px -translate-x-1/2 bg-fuchsia-500" data-guide-v></div><div class="pointer-events-none absolute inset-x-0 top-1/2 hidden h-px -translate-y-1/2 bg-fuchsia-500" data-guide-h></div></div><p class="mt-3 text-center text-[11px] text-slate-400">This name comes from the participant record. Correct the participant if the spelling is wrong.</p></div></div></div>

                <div class="studio-toolbar-wrap hidden" data-name-adjustment><div class="studio-toolbar" data-name-toolbar>
                    <div class="flex items-center gap-2 overflow-x-auto">
                        <select class="h-9 min-w-36 rounded-lg border border-slate-200 bg-white px-2 text-[12px] font-medium" data-design-font>@foreach($fonts as $key => $font)<option value="{{ $key }}" data-css="{{ $font['css'] }}" @selected($fontKey === $key)>{{ $font['label'] }}</option>@endforeach</select>
                        <label class="flex h-9 min-w-40 items-center gap-2 rounded-lg border border-slate-200 px-2 text-[11px] font-semibold text-slate-500">Size <input type="range" min="12" max="160" value="{{ (int) $sizeVal }}" class="w-20 accent-violet-600" data-design-size><span class="w-6 tabular-nums text-slate-800" data-size-readout>{{ (int) $sizeVal }}</span></label>
                        <label class="flex h-9 items-center gap-1 rounded-lg border border-slate-200 px-1.5"><input type="color" value="{{ $accentVal }}" class="size-6 cursor-pointer border-0 bg-transparent p-0" data-design-color><input value="{{ $accentVal }}" maxlength="7" class="w-[66px] text-[11px] font-mono uppercase outline-none" data-design-hex aria-label="Hex color"></label>
                        <button type="button" class="studio-tool-button {{ $weightVal === 'bold' ? 'is-active' : '' }}" data-toggle-bold><strong>B</strong></button><button type="button" class="studio-tool-button {{ $styleVal === 'italic' ? 'is-active' : '' }}" data-toggle-italic><em>I</em></button><span class="h-6 w-px shrink-0 bg-slate-200"></span>
                        @foreach(['left', 'center', 'right'] as $alignment)<button type="button" class="studio-tool-button {{ $alignVal === $alignment ? 'is-active' : '' }}" data-align="{{ $alignment }}"><x-icon name="align-{{ $alignment }}" class="size-4" /></button>@endforeach
                        <span class="h-6 w-px shrink-0 bg-slate-200"></span><div class="grid shrink-0 grid-cols-3 gap-0.5"><span></span><button type="button" class="studio-nudge" data-nudge="up">↑</button><span></span><button type="button" class="studio-nudge" data-nudge="left">←</button><button type="button" class="studio-nudge text-[9px]" data-reset-position>Reset</button><button type="button" class="studio-nudge" data-nudge="right">→</button><span></span><button type="button" class="studio-nudge" data-nudge="down">↓</button><span></span></div>
                    </div>
                    <div class="mt-2 flex items-center justify-between gap-2 border-t border-slate-100 pt-2"><p class="text-[11px] text-slate-500">Exceptional adjustment for <strong class="text-slate-800" data-adjustment-name>{{ $firstName }}</strong> only. The default style is configured on the Template page.</p><button type="button" class="inline-flex h-8 items-center rounded-lg bg-slate-900 px-3 text-[11px] font-semibold text-white" data-close-name-adjustment>Done</button></div>
                </div></div>
            </main>

            <aside class="studio-side-panel studio-right-panel" data-panel="people">
                <div class="border-b border-slate-200 p-4"><div class="flex items-center justify-between"><div><p class="text-sm font-semibold">People</p><p class="mt-0.5 text-[11px] text-slate-500">Certificate owners &amp; delivery</p></div><span class="rounded-full bg-slate-100 px-2 py-1 text-[10px] font-bold text-slate-600"><span data-selected-count>0</span> selected</span></div><label class="studio-search"><x-icon name="search" /><input placeholder="Search name/email" data-people-search></label><div class="mt-2 flex gap-1 overflow-x-auto" data-status-filters>@foreach(['all', 'ready', 'queued', 'sending', 'sent', 'failed'] as $filter)<button type="button" class="studio-filter {{ $filter === 'all' ? 'is-active' : '' }}" data-status-filter="{{ $filter }}">{{ $filter }}</button>@endforeach</div></div>
                <div class="min-h-0 flex-1 overflow-y-auto p-2" data-people-list>
                    @foreach($participants as $participant)
                        @php $certificate = $participant->certificate_record; $meta = $participant->delivery_meta; $state = $participant->certificate_delivery_state; $status = $state->slug(); @endphp
                        <article class="studio-person-row" data-person-row="{{ $participant->public_id }}" data-search="{{ Str::lower($participant->full_name.' '.$participant->email) }}" data-status="{{ $status }}"><button type="button" class="w-full text-left" data-preview-select="{{ $participant->public_id }}"><span class="flex items-start gap-2.5"><span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-[11px] font-bold text-amber-900">{{ Str::upper(Str::substr($participant->full_name ?: 'P', 0, 1)) }}</span><span class="min-w-0 flex-1"><span class="block truncate text-[12px] font-semibold text-slate-900" data-participant-name>{{ $participant->full_name ?: 'Unnamed participant' }}</span><span class="mt-0.5 block truncate text-[11px] text-slate-500">{{ $participant->email ?: 'No email address' }}</span><span class="mt-1.5 flex items-center gap-1.5"><span class="certificate-status certificate-status-{{ $status }}" data-status-badge>{{ $state->label() }}</span>@if($status === 'failed')<span class="text-[10px] text-slate-400">{{ $meta['retry_count'] ?? 0 }} retries</span>@endif</span><span class="mt-1 block text-[10px] leading-4 text-slate-500" data-status-detail>{{ $state->detail() }}</span></span></span></button><div class="{{ $status === 'failed' ? '' : 'hidden' }} mt-2 rounded-lg border border-red-100 bg-red-50 p-2 text-[10px] leading-4 text-red-800" data-failure-detail><strong>Failed:</strong> <span data-failure-reason>{{ $meta['failed_reason'] ?? 'Certificate generation or email delivery failed' }}</span>@if($certificate?->status === 'issued')<form method="POST" action="{{ route('admin.certificates.resend', $certificate) }}" class="mt-1" data-confirm="Retry this failed certificate delivery?" data-confirm-tone="neutral" data-confirm-action="Retry send" data-confirm-title="Retry certificate">@csrf<button class="font-bold underline">Retry email</button></form>@endif</div></article>
                    @endforeach
                </div>
                <div class="border-t border-slate-200 bg-slate-50 p-4"><p class="text-[11px] font-bold uppercase tracking-[0.08em] text-slate-400">Sending summary</p><div class="mt-2 grid grid-cols-4 gap-1 text-center"><div><strong class="block text-sm tabular-nums" data-summary-total>{{ $participants->count() }}</strong><span class="text-[9px] text-slate-500">Total</span></div><div><strong class="block text-sm tabular-nums text-emerald-600" data-summary-sent>{{ $sentCount }}</strong><span class="text-[9px] text-slate-500">Sent</span></div><div><strong class="block text-sm tabular-nums text-red-600" data-summary-failed>{{ $failedCount }}</strong><span class="text-[9px] text-slate-500">Failed</span></div><div><strong class="block text-sm tabular-nums text-amber-600" data-summary-pending>{{ $readyCount + $queuedCount + $sendingCount }}</strong><span class="text-[9px] text-slate-500">Pending</span></div></div></div>
            </aside>
        </div>
    @endif
</div>

<div class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" data-name-correction-modal hidden role="dialog" aria-modal="true" aria-labelledby="name-correction-title">
    <form method="POST" class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl" data-name-correction-form>
        @csrf @method('PUT')
        <h2 class="text-lg font-semibold" id="name-correction-title">Correct participant name</h2>
        <p class="mt-1 text-[12px] leading-5 text-slate-500">This updates the participant record. The preview and all future certificates will use the corrected name.</p>
        <label class="field-label mt-5 block">Full name
            <input class="field" name="full_name" required maxlength="120" data-name-correction-input>
        </label>
        <p class="mt-2 hidden text-[12px] text-red-600" data-name-correction-error></p>
        <div class="mt-5 flex justify-end gap-2"><button type="button" class="button-secondary" data-cancel-name-correction>Cancel</button><button class="button-primary">Save corrected name</button></div>
    </form>
</div>

<div class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" data-send-confirm hidden role="dialog" aria-modal="true" aria-labelledby="send-confirm-title"><div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><div class="flex size-11 items-center justify-center rounded-full bg-amber-100 text-amber-900"><x-icon name="send" class="size-5" /></div><h2 class="mt-4 text-lg font-semibold" id="send-confirm-title">Send certificates?</h2><p class="mt-1 text-[13px] leading-5 text-slate-500">You are about to queue certificate delivery to <strong class="text-slate-900" data-confirm-count>0 participants</strong>.</p><dl class="mt-5 divide-y divide-slate-100 rounded-xl border border-slate-200 px-4 text-[12px]"><div class="flex justify-between py-3"><dt class="text-slate-500">Total certificates</dt><dd class="font-semibold tabular-nums" data-confirm-total>0</dd></div><div class="flex justify-between py-3"><dt class="text-slate-500">Valid email addresses</dt><dd class="font-semibold tabular-nums" data-confirm-valid>{{ $validEmailCount }}</dd></div><div class="flex justify-between py-3"><dt class="text-slate-500">Already sent</dt><dd class="font-semibold tabular-nums">{{ $sentCount }}</dd></div><div class="flex justify-between py-3"><dt class="text-slate-500">Certificates skipped</dt><dd class="font-semibold tabular-nums" data-confirm-skipped>0</dd></div></dl><p class="mt-4 rounded-lg bg-amber-50/60 p-3 text-[11px] leading-5 text-slate-600">After confirmation, the certificate first enters the generation queue, then the email queue. This studio shows each stage until the email provider accepts it.</p><div class="mt-6 flex justify-end gap-2"><button type="button" class="button-secondary" data-cancel-send>Cancel</button><button type="button" class="button-primary" data-confirm-send><x-icon name="send" class="size-4" />Confirm &amp; queue</button></div></div></div>
@endsection
