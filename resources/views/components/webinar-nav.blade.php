@props(['webinar'])
@php
    // One light query keyed by type drives the Tests links regardless of what
    // each page happened to eager-load.
    $navForms = $webinar->forms()->select(['id', 'webinar_id', 'type', 'title'])->get()->keyBy('type');
    $currentFormId = request()->route('form')?->id;

    $isOverview = request()->routeIs('admin.webinars.show');
    $isSettings = request()->routeIs('admin.webinars.edit');
    $isReports = request()->routeIs('admin.webinars.reports');
    $isParticipants = request()->routeIs('admin.participants.index', 'admin.participants.show');
    $isTemplate = request()->routeIs('admin.certification.*');
    $isStudio = request()->routeIs('admin.certificates.studio');

    $formActive = fn ($type) => request()->routeIs('admin.forms.edit')
        && isset($navForms[$type])
        && $currentFormId === $navForms[$type]->id;
@endphp

<aside class="webinar-nav sticky top-0 z-20 hidden h-screen shrink-0 flex-col border-r border-slate-200 bg-white lg:flex">
    {{-- Workspace header --}}
    <div class="shrink-0 border-b border-slate-100 px-4 pb-4 pt-5">
        <div class="flex items-center justify-between gap-2">
            <a href="{{ route('admin.webinars.index') }}" class="webinar-nav-label inline-flex items-center gap-1.5 text-[12px] font-medium text-slate-500 transition hover:text-slate-900">
            <x-icon name="arrow-left" class="size-4" />All webinars
            </a>
            <button type="button" class="webinar-nav-toggle" data-webinar-nav-toggle aria-expanded="true" aria-label="Minimize webinar sidebar" title="Minimize webinar sidebar">
                <x-icon name="panel-left" class="size-4" />
            </button>
        </div>
        <div class="mt-3 flex items-start gap-2.5">
            <span class="mt-0.5 grid size-8 shrink-0 place-items-center rounded-lg bg-accent-600 text-[13px] font-bold text-white">
                {{ Str::upper(Str::substr($webinar->title, 0, 1)) }}
            </span>
            <div class="webinar-nav-label min-w-0">
                <p class="truncate text-[14px] font-semibold leading-5 text-slate-900" title="{{ $webinar->title }}">{{ $webinar->title }}</p>
                <span class="badge mt-1 {{ $webinar->isOpen() ? 'badge-green' : 'badge-slate' }}">{{ $webinar->availabilityLabel() }}</span>
            </div>
        </div>
    </div>

    {{-- Categorised, collapsible navigation --}}
    <nav class="flex-1 space-y-1 overflow-y-auto px-3 py-4">

        {{-- Participants --}}
        <details data-nav-group="participants" open>
            <summary class="wn-summary">
                <span class="flex items-center gap-2"><x-icon name="users" class="size-4 text-slate-400" /><span class="webinar-nav-label">Participants</span></span>
                <x-icon name="chevron-down" class="wn-chevron size-4 text-slate-400" />
            </summary>
            <div class="mt-0.5 space-y-0.5 pb-1 pl-1">
                @if($registrationForm = $navForms['registration'] ?? null)
                    <a href="{{ route('admin.forms.edit', [$webinar, $registrationForm]) }}" class="wn-item {{ $formActive('registration') ? 'wn-item-active' : '' }}" @if($formActive('registration')) aria-current="page" @endif>
                        <x-icon name="link" class="size-[15px]" /><span class="webinar-nav-label">Registration form &amp; link</span>
                    </a>
                @endif
                <a href="{{ route('admin.participants.index', $webinar) }}" class="wn-item {{ $isParticipants ? 'wn-item-active' : '' }}" @if($isParticipants) aria-current="page" @endif>
                    <x-icon name="grid" class="size-[15px]" /><span class="webinar-nav-label">Registered participants</span>
                </a>
            </div>
        </details>

        {{-- Tests --}}
        <details data-nav-group="tests" open>
            <summary class="wn-summary">
                <span class="flex items-center gap-2"><x-icon name="clipboard" class="size-4 text-slate-400" /><span class="webinar-nav-label">Tests</span></span>
                <x-icon name="chevron-down" class="wn-chevron size-4 text-slate-400" />
            </summary>
            <div class="mt-0.5 space-y-0.5 pb-1 pl-1">
                @foreach(['pretest' => 'Pre-test', 'posttest' => 'Post-test', 'evaluation' => 'Evaluation'] as $type => $label)
                    @if($form = $navForms[$type] ?? null)
                        <a href="{{ route('admin.forms.edit', [$webinar, $form]) }}" class="wn-item {{ $formActive($type) ? 'wn-item-active' : '' }}" @if($formActive($type)) aria-current="page" @endif>
                            <x-icon name="file-text" class="size-[15px]" /><span class="webinar-nav-label">{{ $label }}</span>
                        </a>
                    @endif
                @endforeach
            </div>
        </details>

        {{-- Certificate --}}
        <details data-nav-group="certificate" open>
            <summary class="wn-summary">
                <span class="flex items-center gap-2"><x-icon name="award" class="size-4 text-slate-400" /><span class="webinar-nav-label">Certificate</span></span>
                <x-icon name="chevron-down" class="wn-chevron size-4 text-slate-400" />
            </summary>
            <div class="mt-0.5 space-y-0.5 pb-1 pl-1">
                <a href="{{ route('admin.certification.edit', $webinar) }}" class="wn-item {{ $isTemplate ? 'wn-item-active' : '' }}" @if($isTemplate) aria-current="page" @endif>
                    <x-icon name="image" class="size-[15px]" /><span class="webinar-nav-label">Template &amp; requirements</span>
                </a>
                <a href="{{ route('admin.certificates.studio', $webinar) }}" class="wn-item {{ $isStudio ? 'wn-item-active' : '' }}" @if($isStudio) aria-current="page" @endif>
                    <x-icon name="send" class="size-[15px]" /><span class="webinar-nav-label">Send certificates</span>
                </a>
            </div>
        </details>

        {{-- Others --}}
        <details data-nav-group="others" open>
            <summary class="wn-summary">
                <span class="flex items-center gap-2"><x-icon name="settings" class="size-4 text-slate-400" /><span class="webinar-nav-label">Others</span></span>
                <x-icon name="chevron-down" class="wn-chevron size-4 text-slate-400" />
            </summary>
            <div class="mt-0.5 space-y-0.5 pb-1 pl-1">
                <a href="{{ route('admin.webinars.show', $webinar) }}" class="wn-item {{ $isOverview ? 'wn-item-active' : '' }}" @if($isOverview) aria-current="page" @endif>
                    <x-icon name="home" class="size-[15px]" /><span class="webinar-nav-label">Overview</span>
                </a>
                <a href="{{ route('admin.webinars.reports', $webinar) }}" class="wn-item {{ $isReports ? 'wn-item-active' : '' }}" @if($isReports) aria-current="page" @endif>
                    <x-icon name="bar-chart" class="size-[15px]" /><span class="webinar-nav-label">Reports &amp; analytics</span>
                </a>
                <a href="{{ route('admin.webinars.edit', $webinar) }}" class="wn-item {{ $isSettings ? 'wn-item-active' : '' }}" @if($isSettings) aria-current="page" @endif>
                    <x-icon name="edit" class="size-[15px]" /><span class="webinar-nav-label">Webinar settings</span>
                </a>
                <a href="{{ route('admin.participants.export', $webinar) }}" class="wn-item" download>
                    <x-icon name="download" class="size-[15px]" /><span class="webinar-nav-label">Export data (CSV)</span>
                </a>
            </div>
        </details>
    </nav>
</aside>
