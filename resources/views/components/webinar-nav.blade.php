@props(['webinar'])
@php
    // Reuse eager-loaded forms if available, or fetch the light projection keyed by type.
    $navForms = ($webinar->relationLoaded('forms')
        ? $webinar->forms
        : $webinar->forms()->select(['id', 'webinar_id', 'type', 'title'])->get()
    )->keyBy('type');
    $currentFormId = request()->route('form')?->id;
    $navSelectedType = request()->route('form')?->type ?? request('type', 'registration');
    $navSelectedType = is_string($navSelectedType) && $navForms->has($navSelectedType) ? $navSelectedType : 'registration';
    $navSelectedForm = $navForms[$navSelectedType] ?? $navForms['registration'] ?? null;

    $isOverview = request()->routeIs('admin.webinars.show');
    $isSettings = request()->routeIs('admin.webinars.edit');
    $isReports = request()->routeIs('admin.webinars.reports');
    $isParticipants = request()->routeIs('admin.responses.*', 'admin.participants.*');
    $isImports = request()->routeIs('admin.webinars.imports.*', 'admin.webinars.import-issues.*');
    $isTemplate = request()->routeIs('admin.certification.*');
    $isStudio = request()->routeIs('admin.certificates.studio');

    $formActive = fn ($type) => request()->routeIs('admin.forms.*')
        && isset($navForms[$type])
        && $currentFormId === $navForms[$type]->id;
@endphp

<div class="mobile-nav-overlay lg:hidden" data-mobile-webinar-nav-overlay aria-hidden="true"></div>
<aside data-persist-key="mobile-webinar-{{ $webinar->id }}" id="mobile-webinar-navigation" class="mobile-workspace-drawer lg:hidden" data-mobile-webinar-nav-drawer aria-label="Webinar navigation" aria-hidden="true" inert>
    <div class="flex shrink-0 items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-slate-800">
        <div class="min-w-0">
            <p class="text-[12px] font-medium text-slate-500 dark:text-slate-400">Webinar workspace</p>
            <p class="truncate text-[14px] font-semibold text-slate-900 dark:text-white">{{ $webinar->title }}</p>
        </div>
        <button type="button" class="mobile-header-button text-slate-600 dark:text-slate-300" data-mobile-webinar-nav-close aria-label="Close webinar navigation"><x-icon name="x" class="size-5" /></button>
    </div>
    @include('partials.webinar-mobile-links')

    @auth
        <div class="shrink-0 border-t border-slate-200 p-3 dark:border-slate-800">
            <div class="flex items-center gap-3 px-3">
                <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-100 text-[13px] font-semibold text-slate-700 dark:bg-slate-800 dark:text-slate-200">{{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}</span>
                <span class="min-w-0 text-[13px] font-medium leading-4 text-slate-900 dark:text-white">{{ Str::limit(auth()->user()->name, 18) }}<span class="block truncate text-[11px] font-normal text-slate-500 dark:text-slate-400">{{ Str::limit(auth()->user()->email, 22) }}</span></span>
            </div>
            <form method="POST" action="{{ route('admin.logout') }}" class="mt-2">
                @csrf
                <button class="mobile-workspace-item w-full" title="Sign out"><x-icon name="log-out" /><span>Sign out</span></button>
            </form>
        </div>
    @endauth
</aside>

<aside data-persist-key="webinar-{{ $webinar->id }}" class="webinar-nav sticky top-0 z-20 hidden h-screen shrink-0 flex-col border-r border-slate-200 bg-white lg:flex">
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

        <div class="space-y-1 pb-3">
            <a href="{{ route('admin.webinars.documents.index', $webinar) }}" class="wn-item {{ request()->routeIs('admin.webinars.documents.*') ? 'wn-item-active' : '' }}"><x-icon name="file-text" class="size-[15px]" /><span class="webinar-nav-label">Uploaded documents</span></a>
            @if(! $webinar->isUploadOnly())
            @if($registrationForm = $navForms['registration'] ?? null)
                <a href="{{ route('admin.forms.edit', [$webinar, $navSelectedForm ?? $registrationForm]) }}" class="wn-item {{ request()->routeIs('admin.forms.*') ? 'wn-item-active' : '' }}" @if(request()->routeIs('admin.forms.*')) aria-current="page" @endif><x-icon name="file-text" class="size-[15px]" /><span class="webinar-nav-label">Forms</span></a>
            @endif
            <a href="{{ route('admin.responses.index', [$webinar, 'type' => $navSelectedType]) }}" class="wn-item {{ $isParticipants || $isImports ? 'wn-item-active' : '' }}" @if($isParticipants || $isImports) aria-current="page" @endif><x-icon name="grid" class="size-[15px]" /><span class="webinar-nav-label">Form Responses</span></a>
            <a href="{{ route('admin.webinars.messages.index', $webinar) }}" class="wn-item {{ request()->routeIs('admin.webinars.messages.*') ? 'wn-item-active' : '' }}"><x-icon name="mail" class="size-[15px]" /><span class="webinar-nav-label">Send a message</span></a>
            @endif
        </div>

        {{-- Certificate --}}
        @if(! $webinar->isUploadOnly())
        <details data-nav-group="certificate" open>
            <summary class="wn-summary">
                <span class="flex items-center gap-2"><x-icon name="award" class="size-4 text-slate-400" /><span class="webinar-nav-label">Certificate</span></span>
                <x-icon name="chevron-down" class="wn-chevron size-4 text-slate-400" />
            </summary>
            <div class="mt-0.5 space-y-0.5 pb-1 pl-1">
                <a href="{{ route('admin.certification.edit', $webinar) }}" class="wn-item {{ $isTemplate ? 'wn-item-active' : '' }}" @if($isTemplate) aria-current="page" @endif>
                    <x-icon name="image" class="size-[15px]" /><span class="webinar-nav-label">Certificate Setup</span>
                </a>
                <a href="{{ route('admin.certificates.studio', $webinar) }}" class="wn-item {{ $isStudio ? 'wn-item-active' : '' }}" @if($isStudio) aria-current="page" @endif>
                    <x-icon name="send" class="size-[15px]" /><span class="webinar-nav-label">Certificate Studio</span>
                </a>
            </div>
        </details>

        @endif
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
                <a href="{{ route('admin.webinars.email-logs.index', $webinar) }}" class="wn-item {{ request()->routeIs('admin.webinars.email-logs.*') ? 'wn-item-active' : '' }}" @if(request()->routeIs('admin.webinars.email-logs.*')) aria-current="page" @endif>
                    <x-icon name="mail" class="size-[15px]" /><span class="webinar-nav-label">Email delivery log</span>
                </a>
                <a href="{{ route('admin.participants.export', $webinar) }}" class="wn-item" download>
                    <x-icon name="download" class="size-[15px]" /><span class="webinar-nav-label">Export pre-send roster</span>
                </a>
            </div>
        </details>
    </nav>
</aside>
