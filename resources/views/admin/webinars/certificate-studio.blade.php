@extends('layouts.certificate-studio')
@section('title', 'Certificate studio — '.$webinar->title)
@section('content')


@php
    $backgroundUrl = $hasBackground ? route('admin.certification.background', $webinar) : null;
    $list = $participants->getCollection();
    $firstParticipant = $list->first();
    $firstName = $firstParticipant?->full_name ?: 'Participant';
    $fontKey = $layout['name_font_family'] ?? 'sans';
    $sizeVal = (float) ($layout['name_font_size'] ?? 42);
    $accentVal = $layout['accent'] ?? '#1d4ed8';
    $topVal = (float) ($layout['name_top'] ?? 62);
    $leftVal = (float) ($layout['name_left'] ?? 50);
    $weightVal = $layout['name_font_weight'] ?? 'bold';
    $styleVal = $layout['name_font_style'] ?? 'regular';
    $alignVal = $layout['name_text_align'] ?? 'center';
    // Delivered recipients are separated out of the working list, so the page
    // count and email coverage describe only people still awaiting a send.
    $validEmailCount = $list->filter(fn ($p) => filled($p->email))->count();
@endphp

<div class="hidden" data-design-state>
    <input name="name_top" value="{{ $topVal }}" data-design-top><input name="name_left" value="{{ $leftVal }}" data-design-left>
    <input name="name_font_family" value="{{ $fontKey }}" data-design-font-store><input name="name_font_size" value="{{ $sizeVal }}" data-design-size-store>
    <input name="accent" value="{{ $accentVal }}" data-design-color-store><input name="name_font_weight" value="{{ $weightVal }}" data-design-weight-store>
    <input name="name_font_style" value="{{ $styleVal }}" data-design-style-store><input name="name_text_align" value="{{ $alignVal }}" data-design-align-store>
</div>
<form id="send-form" class="hidden" method="POST" action="{{ route('admin.certificates.issue-selected', $webinar) }}" data-send-form @if(app(\App\Services\LocalDelivery::class)->enabled()) data-immediate-send data-certificate-progress="selected" data-inline-submit @endif>
    @csrf <input type="hidden" name="preview_confirmed" value="1"><input type="hidden" name="designs_json" value="{}" data-designs-json>
    @include('admin.webinars.partials.studio-send-inputs')
</form>

{{-- Applying the style being edited as the template default: the same audited
     endpoint the Certificate Setup page uses, returning to the studio.
     The design endpoint is a PUT route, so the form posts with a method
     override — without it the browser's POST never reaches it. --}}
<form id="apply-all-form" class="hidden" method="POST" action="{{ route('admin.certification.design', $webinar) }}" data-inline-submit
      data-confirm="Make this exact style the template for EVERY recipient? Their names will use it unless you adjust them individually."
      data-confirm-tone="neutral"
      data-confirm-action="Apply to everyone"
      data-confirm-title="Apply style to every recipient">
    @csrf @method('PUT')
    <input type="hidden" name="name_top" data-apply-top>
    <input type="hidden" name="name_left" data-apply-left>
    <input type="hidden" name="name_font_size" data-apply-size>
    <input type="hidden" name="name_font_family" data-apply-font>
    <input type="hidden" name="accent" data-apply-color>
    <input type="hidden" name="name_font_weight" data-apply-weight>
    <input type="hidden" name="name_font_style" data-apply-style>
    <input type="hidden" name="name_text_align" data-apply-align>
    <input type="hidden" name="studio" value="1">
</form>

<div class="flex h-dvh min-h-0 flex-col bg-slate-100" data-studio data-send-plan-url="{{ route('admin.certificates.send-plan', $webinar) }}" data-has-background="{{ $hasBackground ? 1 : 0 }}" data-initial-participant="{{ $firstParticipant?->public_id }}" data-status-url="{{ $statusUrl }}" data-preview-url="{{ route('admin.certification.preview', $webinar) }}" data-recipients-url="{{ route('admin.certificates.studio.recipients', $webinar) }}" data-manual-url="{{ route('admin.certificates.manual.store', $webinar) }}">
    <div class="studio-approval-notice flex shrink-0 flex-wrap items-center justify-between gap-2 px-4 py-2 text-xs">
        <span>Eligible participants appear automatically. Manual recipients are certificate exceptions.@if($canReviewEligibility) <a class="font-semibold underline" href="{{ route('admin.webinars.eligibility.index', $webinar) }}">View eligibility</a>.@endif</span>
        <button type="button" class="studio-button-secondary" data-add-recipient><x-icon name="plus" class="size-4" />Add Recipient Manually</button>
    </div>

    {{-- ===================== Top bar ===================== --}}
    <header class="studio-topbar">
        <div class="flex min-w-0 flex-1 items-center gap-2">
            <a href="{{ $backUrl }}" class="inline-flex size-9 shrink-0 items-center justify-center" title="Back to participants" aria-label="Back to participants"><x-brand compact /></a>
            <nav class="hidden min-w-0 items-center gap-1 md:flex" aria-label="Breadcrumb">
                <a href="{{ route('admin.webinars.index') }}" class="studio-crumb">Webinars</a>
                <x-icon name="chevron-right" class="size-3.5 shrink-0 text-slate-300" />
                <a href="{{ route('admin.webinars.show', $webinar) }}" class="studio-crumb max-w-[150px] truncate">{{ $webinar->title }}</a>
                <x-icon name="chevron-right" class="size-3.5 shrink-0 text-slate-300" />
                <span class="shrink-0 text-[13px] font-semibold text-slate-900">Certificate studio</span>
            </nav>
            @if($webinar->isAdministratorLocked())<span class="badge badge-amber shrink-0 gap-1"><x-icon name="lock" class="size-3" />Admin locked</span>@endif
            {{-- Honest state: nothing here is persisted until the send is confirmed. --}}
            <span class="studio-state-pill hidden sm:inline-flex" data-adjust-indicator title="Adjustments made here are applied to the selected recipients when you send.">
                <span class="size-2 shrink-0 rounded-full bg-slate-300" data-adjust-dot></span>
                <span data-adjust-label>Template style</span>
            </span>
        </div>

        <div class="flex shrink-0 items-center gap-1">
            <button type="button" class="studio-icon-button" data-undo title="Undo" aria-label="Undo" disabled><x-icon name="undo" class="size-4" /></button>
            <button type="button" class="studio-icon-button" data-redo title="Redo" aria-label="Redo" disabled><x-icon name="redo" class="size-4" /></button>
            <label class="studio-zoom ml-1 hidden sm:flex">
                <span class="sr-only">Zoom</span>
                <select data-zoom-select>
                    <option value="fit">Fit</option>
                    @foreach([0.5, 0.75, 1, 1.25, 1.5, 2] as $level)
                        <option value="{{ $level }}" @selected($level === 1)>{{ (int) ($level * 100) }}%</option>
                    @endforeach
                </select>
            </label>
            <button type="button" class="studio-icon-button ml-1 inline-flex" data-theme-toggle title="Toggle theme" aria-label="Toggle theme">
                <x-icon name="sun" class="size-4 hidden dark:block text-amber-400" />
                <x-icon name="moon" class="size-4 block dark:hidden" />
            </button>
        </div>

        <div class="flex flex-1 items-center justify-end gap-1.5 sm:gap-2">
            @if($hasBackground)
                <form method="POST" action="{{ route('admin.certificates.batch', $webinar) }}"
                      class="hidden {{ $bulkReadyCount > 0 ? 'sm:inline-flex' : '' }}" data-bulk-send @if(app(\App\Services\LocalDelivery::class)->enabled()) data-immediate-send data-certificate-progress="ready" data-inline-submit @endif
                      data-confirm="Send certificates only to every ready participant ({{ $bulkReadyCount }} total)? The template's default style is used — per-recipient style adjustments made here are not applied to a bulk send. Held import rows ({{ $heldCount ?? 0 }}) must already be resolved."
                      data-confirm-tone="success"
                      data-confirm-action="Send to all ready"
                      data-confirm-title="Send to all ready participants">
                    @csrf
                    <button type="submit" class="studio-button-secondary">
                        <x-icon name="users" class="size-4" />
                        <span class="hidden md:inline">Send to all ready</span><span class="md:hidden">All</span>
                        <span class="ml-0.5 rounded bg-slate-900/5 px-1.5 py-0.5 text-[10px] font-bold tabular-nums" data-bulk-ready-count>{{ $bulkReadyCount }}</span>
                    </button>
                </form>
            @endif
            <a class="studio-button-secondary hidden sm:inline-flex" href="{{ route('admin.webinars.certificates.export-zip', $webinar) }}" title="Download all issued certificate PDFs as a ZIP archive"><x-icon name="archive" class="size-4" />Export ZIP</a>
            <a class="studio-button-secondary hidden sm:inline-flex" target="_blank" rel="noopener" href="{{ route('admin.certification.preview', [$webinar, 'participant' => $firstParticipant?->public_id]) }}" data-preview-pdf><x-icon name="download" class="size-4" />Preview PDF</a>
            <button type="button" class="studio-button-success" data-open-send-confirm @unless(app(\App\Policies\WebinarPolicy::class)->permission(auth()->user(), $webinar, 'send_emails')) hidden @endunless disabled><x-icon name="send" class="size-4" /><span class="hidden sm:inline" data-send-label>Send certificates</span><span class="sm:hidden">Send</span></button>
            <button type="button" class="studio-mobile-panel-button lg:hidden" data-toggle-panel="people" aria-label="Open recipients"><x-icon name="users" class="size-4" /></button>
            <button type="button" class="studio-mobile-panel-button lg:hidden" data-toggle-panel="editor" aria-label="Open editor"><x-icon name="type" class="size-4" /></button>
        </div>
    </header>

    @if($errors->any())
        <div class="border-b border-red-200 bg-red-50 px-4 py-3 text-[12px] leading-5 text-red-900" role="alert">
            <strong>The certificates were not sent.</strong>
            <ul class="mt-1 space-y-0.5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @if(($heldCount ?? 0) > 0)
        <div class="border-b border-amber-200 bg-amber-50 px-4 py-3 text-[12px] leading-5 text-amber-900" role="alert">
            <strong>{{ number_format($heldCount) }} import row(s) need review</strong> before issuing new certificates with “Send to all ready”.
            Open <a class="font-semibold underline" href="{{ route('admin.webinars.imports.index', $webinar) }}">Imports</a>
            to review the spreadsheet rows that could not be safely accepted. This is not an email queue. Previewing and resending the corrected certificates in “To send” is still available.
        </div>
    @endif

    @if($latestBatch)
        <details class="shrink-0 border-b border-slate-200 bg-white px-4 py-2 text-[11px] text-slate-700">
            <summary class="cursor-pointer select-none font-medium marker:text-slate-400">
                Latest bulk issuance
                <span class="ml-1.5 rounded-full bg-slate-100 px-1.5 py-0.5 font-semibold tabular-nums text-slate-600">{{ ucfirst($latestBatch->status) }}</span>
                <span class="ml-1.5 text-slate-500">{{ number_format($latestBatch->completed_count) }} issued · {{ number_format($latestBatch->failed_count) }} failed · {{ number_format($latestBatch->total_count) }} total</span>
                <span class="ml-1.5 text-slate-400">{{ $latestBatch->completed_at?->format('M j, Y g:i A') ?? $latestBatch->created_at?->format('M j, Y g:i A') }}</span>
            </summary>
            <div class="mt-2 max-h-48 overflow-auto rounded border border-slate-200">
                @if($latestBatchItems->isEmpty())
                    <p class="px-3 py-2 text-slate-500">This batch has not produced an individual outcome yet. Refresh after the queue worker starts processing it.</p>
                @else
                    <table class="w-full text-left">
                        <thead class="sticky top-0 bg-slate-50 text-[10px] uppercase tracking-wide text-slate-500"><tr><th class="px-3 py-1.5 font-semibold">Participant</th><th class="px-3 py-1.5 font-semibold">Certificate</th><th class="px-3 py-1.5 font-semibold">Outcome</th><th class="px-3 py-1.5 font-semibold">Detail</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            @foreach($latestBatchItems as $item)
                                <tr>
                                    <td class="px-3 py-1.5 font-medium">{{ $item->participant?->full_name ?? 'Erased participant' }}</td>
                                    <td class="px-3 py-1.5 font-mono text-slate-500">{{ $item->certificate?->verification_code ?? '—' }}</td>
                                    <td class="px-3 py-1.5">
                                        @if($item->status === 'issued')
                                            <span class="rounded bg-emerald-50 px-1.5 py-0.5 font-semibold text-emerald-700">Issued</span>
                                        @else
                                            <span class="rounded bg-rose-50 px-1.5 py-0.5 font-semibold text-rose-700">{{ ucfirst($item->status) }}</span>
                                        @endif
                                    </td>
                                    <td class="px-3 py-1.5 text-slate-500">{{ $item->message ?? 'Certificate issued and queued for delivery.' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if($latestBatchItemsTruncated)
                        <p class="border-t border-slate-100 px-3 py-1.5 text-slate-500">Showing the latest {{ number_format($latestBatchItems->count()) }} outcomes. The complete operation record is retained in the audit log.</p>
                    @endif
                @endif
            </div>
        </details>
    @endif

    @if($ineligibleScope->isNotEmpty())
        <div class="shrink-0 px-4 py-2 text-xs text-slate-600">@foreach($ineligibleScope as $blocked)<p>{{ $blocked['participant']->full_name }} cannot be certified yet. Missing {{ implode(' and ', $blocked['missing']) ?: 'current authorization' }}. Use Add Recipient Manually for an authorized certificate exception, or <a class="underline" href="{{ route('admin.participants.show', [$webinar, $blocked['participant']]) }}">review the participant</a>.</p>@endforeach</div>
    @elseif($manualRequested && $participants->isEmpty())
        <p class="shrink-0 px-4 py-2 text-xs">That recipient was not found. No recipients were selected.</p>
    @endif
    @if(! $template || ! $hasBackground)
        <div class="shrink-0 px-4 py-2 text-xs text-slate-600">Upload a certificate design before previewing or sending. <a class="underline" href="{{ route('admin.certification.edit', $webinar) }}">Open Certificate Setup</a>.</div>
    @endif
        {{-- Below `lg` the side panels sit off-canvas via translate-x-full. A transform
             moves them visually but still counts toward the scroll area, so clip it here
             or every narrow viewport scrolls sideways by the panel width. --}}
        <div class="relative flex min-h-0 flex-1 overflow-hidden">
            <button type="button" class="absolute inset-0 z-40 hidden bg-slate-950/45 lg:hidden" data-panel-scrim aria-label="Close panel"></button>

            {{-- ===================== Left: the people ===================== --}}
            <aside class="studio-side-panel studio-left-panel" data-panel="people">
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-2 lg:hidden">
                    <span class="text-sm font-semibold">Recipients</span>
                    <button type="button" class="mobile-header-button" data-close-panel aria-label="Close recipients"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="shrink-0 border-b border-slate-100 p-4 pb-3">
                    <div class="flex items-baseline justify-between gap-2">
                        <p class="text-[13px] font-semibold text-slate-900">Recipients</p>
                        <p class="text-[11px] text-slate-500"><span data-visible-certificate-count>{{ $participants->total() }}</span> shown</p>
                    </div>
                    <div class="recipient-tabs mt-2 flex rounded-lg bg-slate-100 p-0.5" role="tablist" aria-label="Recipient lists">
                        <button type="button" role="tab" aria-selected="true" data-recipient-tab="working" class="is-active">To send<span class="ml-1.5 tabular-nums opacity-60" data-recipient-working-total>{{ number_format($participants->total()) }}</span></button>
                        <button type="button" role="tab" aria-selected="false" data-recipient-tab="sent" class="">Sent<span class="ml-1.5 tabular-nums opacity-60" data-recipient-sent-total>{{ number_format($sentCount) }}</span></button>
                        @if($canReviewEligibility)<button type="button" role="tab" aria-selected="false" data-recipient-tab="incomplete">Not eligible<span class="ml-1 tabular-nums opacity-60" data-recipient-incomplete-total>{{ number_format($notCurrentlyEligibleCount) }}</span></button>@endif
                    </div>
                    <button type="button" class="mt-2 text-xs text-accent-700 underline" data-refresh-recipients>Refresh recipients</button><p class="mt-2 text-xs text-red-700" data-recipient-refresh-error role="alert" hidden></p><a class="mt-2 block text-xs underline" data-recipient-password-confirm hidden href="{{ route('admin.password.confirm') }}" target="_blank" rel="noopener">Confirm password to complete removal</a>
                    <label class="field-label mt-2" for="studio-recipient-source">Recipient source</label><select class="field" id="studio-recipient-source" data-recipient-source><option value="">All sources</option><option value="eligible">Eligible</option><option value="manual">Manual</option></select>
                    <label class="studio-search mt-2"><x-icon name="search" /><input placeholder="Search name or email" data-certificate-search maxlength="200" aria-label="Search recipients"></label>
                </div>

                {{-- Working set: everyone still awaiting a send, selectable. --}}
                <div data-tab-panel="working" class="flex min-h-0 flex-1 flex-col">
                    <label class="flex shrink-0 items-center justify-between gap-2 px-4 pt-1 text-[12px] font-medium text-slate-600">
                        <span class="flex items-center gap-2"><input type="checkbox" class="size-4 rounded border-slate-300 text-accent-700" data-select-all>Select all ready</span>
                        <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[10px] font-bold tabular-nums text-slate-600" data-selected-count>0</span>
                    </label>
                    @include('admin.webinars.partials.studio-recipient-rows')

                    @include('admin.webinars.partials.studio-pagination')
                </div>

                {{-- Delivered certificates remain separate; resending requires an explicit confirmation. --}}
                @include('admin.webinars.partials.studio-sent')
                @if($canReviewEligibility) @include('admin.webinars.partials.studio-incomplete') @endif

                @if($pipelineWarning)
                    <div class="shrink-0 border-t border-slate-100 p-3">
                        <div class="flex items-start gap-2 rounded-lg border border-amber-200 bg-amber-50 p-2.5 text-[11px] leading-4 text-amber-950"><x-icon name="alert" class="mt-px size-3.5 shrink-0" /><span><strong class="font-semibold">Delivery needs attention.</strong> {{ $pipelineWarning }}</span></div>
                    </div>
                @endif
            </aside>

            {{-- ===================== Centre: the certificate ===================== --}}
            <main class="relative flex min-w-0 flex-1 flex-col overflow-hidden bg-slate-100">
                <div class="relative min-h-0 flex-1 overflow-auto p-6 pb-16 sm:p-10 sm:pb-16" data-preview-viewport>
                    <div class="flex min-h-full items-center justify-center">
                        <div class="w-full max-w-[980px] origin-center transition-transform duration-150" data-preview-scale>
                            <div class="relative overflow-hidden rounded-md bg-white shadow-xl shadow-slate-900/10 ring-1 ring-slate-900/5" data-canvas-wrap>
                                <x-certificate-preview :name="$firstName" :layout="$layout" :background-url="$backgroundUrl" />
                                <div class="pointer-events-none absolute inset-y-0 left-1/2 hidden w-px -translate-x-1/2 bg-fuchsia-500" data-guide-v></div>
                                <div class="pointer-events-none absolute inset-x-0 top-1/2 hidden h-px -translate-y-1/2 bg-fuchsia-500" data-guide-h></div>
                            </div>
                            <p class="mt-3 text-center text-[11px] text-slate-500">Drag the name, or use the arrow keys.</p>
                        </div>
                    </div>
                </div>

                <div class="studio-pager">
                    <button type="button" class="studio-icon-button" data-prev-certificate aria-label="Previous certificate"><x-icon name="chevron-left" class="size-4" /></button>
                    <span class="px-1 text-[11px] font-medium tabular-nums text-slate-600"><span data-active-index>{{ $participants->isEmpty() ? 0 : 1 }}</span> / <span data-certificate-total>{{ $participants->count() }}</span></span>
                    <button type="button" class="studio-icon-button" data-next-certificate aria-label="Next certificate"><x-icon name="chevron-right" class="size-4" /></button>
                </div>
            </main>

            {{-- ===================== Right: the editor ===================== --}}
            <aside class="studio-side-panel studio-right-panel" data-panel="editor" data-name-adjustment>
                <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-4 py-2 lg:hidden">
                    <span class="text-sm font-semibold">Editor</span>
                    <button type="button" class="mobile-header-button" data-close-panel aria-label="Close editor"><x-icon name="x" class="size-5" /></button>
                </div>
                <div class="min-h-0 flex-1 overflow-y-auto" data-name-toolbar>
                    <p hidden data-correction-note class="border-b border-slate-200 px-4 py-3 text-xs text-slate-600">This corrected attachment keeps the original name and style. Preview PDF opens the exact stored file. Sending resends that file; style editing is disabled for this correction.</p>

                    <div class="studio-editor-block">
                        <p class="studio-editor-title">Align</p>
                        <div class="studio-editor-row mt-2">
                            @foreach([
                                'left' => ['align-h-left', 'Align to the left third'],
                                'center-h' => ['align-h-center', 'Centre horizontally'],
                                'right' => ['align-h-right', 'Align to the right third'],
                                'top' => ['align-top', 'Align to the upper third'],
                                'middle' => ['align-middle', 'Centre vertically'],
                                'bottom' => ['align-bottom', 'Align to the lower third'],
                            ] as $preset => [$icon, $label])
                                <button type="button" class="studio-tool-button" data-align-preset="{{ $preset }}" title="{{ $label }}" aria-label="{{ $label }}"><x-icon name="{{ $icon }}" class="size-4" /></button>
                            @endforeach
                        </div>
                    </div>

                    <div class="studio-editor-block">
                        <p class="studio-editor-title">Recipient</p>
                        <button type="button" class="studio-recipient-box mt-2" data-open-name-correction aria-label="Correct name">
                            <span class="block truncate text-[13px] font-semibold text-slate-900" data-adjustment-name>{{ $firstName }}</span>
                            {{-- The address sits beside the name being spell-checked in
                                 plain, dark type — easy to read, no colour highlight. --}}
                            <span class="mt-1 block truncate font-mono text-[12px] font-semibold text-slate-900" data-adjustment-email title="{{ $firstParticipant?->email }}">{{ $firstParticipant?->email ?: 'No email address' }}</span>
                            <span class="mt-1.5 block text-[11px] text-slate-400" data-recipient-correction-hint>Click the name to correct its spelling</span>
                        </button>
                    </div>

                    <div class="studio-editor-block">
                        <p class="studio-editor-title">Text</p>

                        <select class="studio-input mt-2 w-full" data-design-font aria-label="Font">
                            @foreach($fonts as $key => $font)<option value="{{ $key }}" data-css="{{ $font['css'] }}" @selected($fontKey === $key)>{{ $font['label'] }}</option>@endforeach
                        </select>

                        <div class="mt-1.5 grid grid-cols-2 gap-1.5">
                            <select class="studio-input" data-design-weight aria-label="Font weight">
                                <option value="regular" @selected($weightVal !== 'bold')>Regular</option>
                                <option value="bold" @selected($weightVal === 'bold')>Bold</option>
                            </select>
                            <label class="studio-input-group" title="Font size in points">
                                <span class="studio-input-affix">Aa</span>
                                <input type="number" min="12" max="160" step="1" value="{{ (int) $sizeVal }}" data-design-size-number aria-label="Font size">
                                <span class="studio-input-suffix">pt</span>
                            </label>
                        </div>

                        <label class="studio-input-group mt-1.5" title="Name colour">
                            <input type="color" value="{{ $accentVal }}" class="studio-swatch" data-design-color aria-label="Name colour">
                            <input value="{{ Str::upper($accentVal) }}" maxlength="7" class="font-mono uppercase" data-design-hex aria-label="Hex colour">
                        </label>

                        <div class="mt-1.5 grid grid-cols-2 gap-1.5">
                            <label class="studio-input-group" title="Distance from the top of the page">
                                <span class="studio-input-affix">Y</span>
                                <input type="number" min="0" max="100" step="0.5" value="{{ $topVal }}" data-design-top-input aria-label="Vertical position">
                                <span class="studio-input-suffix">%</span>
                            </label>
                            <label class="studio-input-group" title="Distance from the left of the page">
                                <span class="studio-input-affix">X</span>
                                <input type="number" min="0" max="100" step="0.5" value="{{ $leftVal }}" data-design-left-input aria-label="Horizontal position">
                                <span class="studio-input-suffix">%</span>
                            </label>
                        </div>

                        <div class="studio-editor-row mt-1.5">
                            @foreach(['left' => 'Align text left', 'center' => 'Centre text', 'right' => 'Align text right'] as $alignment => $label)
                                <button type="button" class="studio-tool-button {{ $alignVal === $alignment ? 'is-active' : '' }}" data-align="{{ $alignment }}" title="{{ $label }}" aria-label="{{ $label }}"><x-icon name="align-{{ $alignment }}" class="size-4" /></button>
                            @endforeach
                            <span class="mx-0.5 h-6 w-px bg-slate-200"></span>
                            <button type="button" class="studio-tool-button {{ $weightVal === 'bold' ? 'is-active' : '' }}" data-toggle-bold title="Bold" aria-label="Bold"><strong class="text-[13px]">B</strong></button>
                            <button type="button" class="studio-tool-button {{ $styleVal === 'italic' ? 'is-active' : '' }}" data-toggle-italic title="Italic" aria-label="Italic"><em class="font-serif text-[13px]">I</em></button>
                        </div>
                    </div>

                    <div class="studio-editor-block border-b-0">
                        <p class="studio-editor-title">Save this style</p>
                        <div class="mt-2 space-y-1.5">
                            <button type="button" class="button-primary w-full justify-center" data-apply-to-all><x-icon name="check-circle" class="size-4" /><span data-apply-all-label>Use for every recipient</span></button>
                            <p class="mt-2 text-xs leading-5 text-slate-600" role="status" data-style-save-status hidden></p>
                            <button type="button" class="button-secondary w-full justify-center" data-reset-design><x-icon name="refresh" class="size-4" />Reset this person to template</button>
                        </div>
                        <p class="mt-2 text-[11px] leading-4 text-slate-500">
                            “Use for every recipient” makes this the template style for the whole webinar.
                            An individual tweak is kept for <strong class="font-semibold text-slate-700" data-adjustment-name>{{ $firstName }}</strong> only
                            and is applied automatically when you send their certificate.
                        </p>
                        <div class="mt-4 space-y-2 lg:hidden">
                            <a class="button-secondary w-full" target="_blank" rel="noopener" href="{{ route('admin.certification.preview', [$webinar, 'participant' => $firstParticipant?->public_id]) }}" data-preview-pdf><x-icon name="download" class="size-4" />Preview PDF</a>
                            <a class="button-secondary w-full" href="{{ route('admin.webinars.certificates.export-zip', $webinar) }}" download><x-icon name="archive" class="size-4" />Export ZIP</a>
                        </div>
                    </div>
                </div>
            </aside>
        </div>
</div>

<div class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" data-name-correction-modal hidden role="dialog" aria-modal="true" aria-labelledby="name-correction-title">
    <form method="POST" class="w-full max-w-sm rounded-2xl bg-white p-6 shadow-2xl" data-name-correction-form>
        @csrf @method('PUT')
        <h2 class="text-lg font-semibold" id="name-correction-title">Correct participant name</h2>
        <p class="mt-1 text-[12px] leading-5 text-slate-500">This updates the participant record. The preview and all future certificates will use the corrected name.</p>
        <label class="field-label mt-5 block">Full name
            <input class="field" name="full_name" required maxlength="180" data-name-correction-input>
        </label>
        <p class="mt-2 hidden text-[12px] text-red-600" data-name-correction-error></p>
        <div class="mt-5 flex justify-end gap-2"><button type="button" class="button-secondary" data-cancel-name-correction>Cancel</button><button class="button-primary">Save corrected name</button></div>
    </form>
</div>

<div class="fixed inset-0 z-[100] hidden items-center justify-center bg-slate-950/60 p-4 backdrop-blur-sm" data-send-confirm hidden role="dialog" aria-modal="true" aria-labelledby="send-confirm-title"><div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl"><div class="flex size-11 items-center justify-center rounded-full bg-amber-100 text-amber-900"><x-icon name="send" class="size-5" /></div><h2 class="mt-4 text-lg font-semibold" id="send-confirm-title">Send certificates?</h2><p class="mt-1 text-[13px] leading-5 text-slate-500">You are about to send certificates only to <strong class="text-slate-900" data-confirm-count>0 participants</strong>.</p><dl class="mt-5 divide-y divide-slate-100 rounded-xl border border-slate-200 px-4 text-[12px]"><div class="flex justify-between py-3"><dt class="text-slate-500">Total certificates</dt><dd class="font-semibold tabular-nums" data-confirm-total>0</dd></div><div class="flex justify-between py-3"><dt class="text-slate-500">Valid email addresses</dt><dd class="font-semibold tabular-nums" data-confirm-valid>{{ $validEmailCount }}</dd></div><div class="flex justify-between py-3"><dt class="text-slate-500">Already sent</dt><dd class="font-semibold tabular-nums">{{ $sentCount }}</dd></div><div class="flex justify-between py-3"><dt class="text-slate-500">Certificates skipped</dt><dd class="font-semibold tabular-nums" data-confirm-skipped>0</dd></div></dl><p class="mt-4 rounded-lg bg-amber-50/60 p-3 text-[11px] leading-5 text-slate-600">@if(app(\App\Services\LocalDelivery::class)->enabled())After confirmation, corrected recipients receive their saved corrected PDF. New recipients have their PDF generated first. Progress and individual results appear on this page; keep it open. Log mode captures email locally without sending.@else Certificate generation and email delivery run in the background. Check each stage here.@endif No non-eligible messages are sent by this action.</p><a class="mt-3 inline-block text-sm font-medium text-accent-700 underline" target="_blank" rel="noopener" href="{{ route('admin.certification.email-preview', $webinar) }}">Preview saved certificate email</a><div class="mt-6 flex justify-end gap-2"><button type="button" class="button-secondary" data-cancel-send>Cancel</button><button type="button" class="button-success" data-confirm-send><x-icon name="send" class="size-4" />Confirm &amp; send</button></div></div></div>
<dialog class="response-dialog panel p-6" data-manual-recipient-dialog aria-labelledby="manual-recipient-title">
    <form method="POST" data-manual-recipient-form data-native-submit novalidate>
        @csrf
        <h2 class="section-title" id="manual-recipient-title">Add Recipient Manually</h2>
        <p class="mt-2 text-sm text-slate-500">Add a certificate exception. This does not change form responses or send a certificate.</p>
        <p class="field-error mt-3" data-manual-error role="alert" tabindex="-1" hidden></p>
        <a class="mt-2 block text-sm underline" data-manual-password-confirm hidden href="{{ route('admin.password.confirm') }}" target="_blank" rel="noopener">Confirm password to complete this change</a>
        <label class="field-label mt-5 block" for="manual-full-name">Full Name</label>
        <input class="field" id="manual-full-name" name="full_name" required maxlength="180" autocomplete="name" aria-describedby="manual-name-error">
        <p class="field-error" id="manual-name-error" hidden></p>
        <label class="field-label mt-4 block" for="manual-email">Email Address</label>
        <input class="field" id="manual-email" name="email" type="email" required maxlength="255" autocomplete="email" aria-describedby="manual-email-error">
        <p class="field-error" id="manual-email-error" hidden></p>
        <div class="mt-6 flex flex-wrap justify-end gap-2"><button type="button" class="button-secondary" data-manual-cancel>Cancel</button><button class="button-primary" data-manual-submit>Add to Studio</button></div>
    </form>
</dialog>

@if(app(\App\Services\LocalDelivery::class)->enabled())
    @include('admin.webinars.partials.certificate-send-progress')
@endif
@endsection
