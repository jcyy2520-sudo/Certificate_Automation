<nav class="flex-1 space-y-5 overflow-y-auto px-4 py-5">
    <div class="space-y-1 border-b border-slate-200 pb-3 dark:border-slate-800">
        <a href="{{ route('admin.webinars.index') }}" class="mobile-workspace-item text-slate-700 dark:text-slate-300">
            <x-icon name="arrow-left" /><span>All webinars</span>
        </a>
        <a href="{{ route('admin.dashboard') }}" class="mobile-workspace-item text-slate-700 dark:text-slate-300">
            <x-icon name="home" /><span>Dashboard</span>
        </a>
    </div>

    <div class="space-y-1">
        <p class="mobile-workspace-heading">Webinar workflow</p>
        @if($registrationForm = $navForms['registration'] ?? null)
            <a href="{{ route('admin.forms.edit', [$webinar, $navSelectedForm ?? $registrationForm]) }}" class="mobile-workspace-item {{ request()->routeIs('admin.forms.*') ? 'mobile-workspace-item-active' : '' }}"><x-icon name="file-text" />Forms</a>
        @endif
        <a href="{{ route('admin.responses.index', [$webinar, 'type' => $navSelectedType]) }}" class="mobile-workspace-item {{ $isParticipants || $isImports ? 'mobile-workspace-item-active' : '' }}"><x-icon name="grid" />Form Responses</a>
    </div>

    <div class="space-y-1">
        <p class="mobile-workspace-heading">Certificate</p>
        <a href="{{ route('admin.certification.edit', $webinar) }}" class="mobile-workspace-item {{ $isTemplate ? 'mobile-workspace-item-active' : '' }}"><x-icon name="image" />Certificate Setup</a>
        <a href="{{ route('admin.certificates.studio', $webinar) }}" class="mobile-workspace-item {{ $isStudio ? 'mobile-workspace-item-active' : '' }}"><x-icon name="send" />Certificate Studio</a>
    </div>

    <div class="space-y-1">
        <p class="mobile-workspace-heading">Webinar</p>
        <a href="{{ route('admin.webinars.show', $webinar) }}" class="mobile-workspace-item {{ $isOverview ? 'mobile-workspace-item-active' : '' }}"><x-icon name="home" />Overview</a>
        <a href="{{ route('admin.webinars.reports', $webinar) }}" class="mobile-workspace-item {{ $isReports ? 'mobile-workspace-item-active' : '' }}"><x-icon name="bar-chart" />Reports &amp; analytics</a>
        <a href="{{ route('admin.webinars.edit', $webinar) }}" class="mobile-workspace-item {{ $isSettings ? 'mobile-workspace-item-active' : '' }}"><x-icon name="edit" />Webinar settings</a>
        <a href="{{ route('admin.webinars.email-logs.index', $webinar) }}" class="mobile-workspace-item {{ request()->routeIs('admin.webinars.email-logs.*') ? 'mobile-workspace-item-active' : '' }}"><x-icon name="mail" />Email delivery log</a>
        <a href="{{ route('admin.participants.export', $webinar) }}" class="mobile-workspace-item" download><x-icon name="download" />Export pre-send roster</a>
    </div>
</nav>
