<nav class="flex-1 space-y-5 overflow-y-auto px-4 py-5" aria-label="All webinar actions">
    <div class="space-y-1 border-b border-slate-200 pb-3 dark:border-slate-800">
        <a href="{{ route('admin.webinars.index') }}" class="mobile-workspace-item text-slate-700 dark:text-slate-300">
            <x-icon name="arrow-left" /><span>All webinars</span>
        </a>
        <a href="{{ route('admin.dashboard') }}" class="mobile-workspace-item text-slate-700 dark:text-slate-300">
            <x-icon name="home" /><span>Dashboard</span>
        </a>
    </div>

    <div class="space-y-1">
        <a href="{{ route('admin.webinars.show', $webinar) }}" class="mobile-workspace-item {{ $isOverview ? 'mobile-workspace-item-active' : '' }}" @if($isOverview) aria-current="page" @endif><x-icon name="home" />Overview</a>
        @if(! $webinar->isUploadOnly())
        @if($registrationForm = $navForms['registration'] ?? null)
            @if($navCanParticipants)<a href="{{ route('admin.forms.edit', [$webinar, $navSelectedForm ?? $registrationForm]) }}" class="mobile-workspace-item {{ request()->routeIs('admin.forms.*') ? 'mobile-workspace-item-active' : '' }}" @if(request()->routeIs('admin.forms.*')) aria-current="page" @endif><x-icon name="file-text" />Forms</a>@endif
        @endif
        @if($navCanParticipants)<a href="{{ route('admin.responses.index', [$webinar, 'type' => $navSelectedType]) }}" class="mobile-workspace-item {{ $isParticipants || $isImports ? 'mobile-workspace-item-active' : '' }}" @if($isParticipants || $isImports) aria-current="page" @endif><x-icon name="grid" />Form Responses</a>@endif
        @if($navCanParticipants)<a href="{{ route('admin.webinars.eligibility.index', $webinar) }}" class="mobile-workspace-item {{ request()->routeIs('admin.webinars.eligibility.*') ? 'mobile-workspace-item-active' : '' }}" @if(request()->routeIs('admin.webinars.eligibility.*')) aria-current="page" @endif><x-icon name="check" />Participant Eligibility</a>@endif
        @elseif($navCanParticipants)
            <a href="{{ route('admin.webinars.documents.index', $webinar) }}" class="mobile-workspace-item {{ request()->routeIs('admin.webinars.documents.*') ? 'mobile-workspace-item-active' : '' }}" @if(request()->routeIs('admin.webinars.documents.*')) aria-current="page" @endif><x-icon name="folder" />Uploaded documents</a>
        @endif
    </div>

    @if(! $webinar->isUploadOnly() && $navCanCertificates)
    <div class="space-y-1">
        <p class="mobile-workspace-heading">Certificate</p>
        <a href="{{ route('admin.certification.edit', $webinar) }}" class="mobile-workspace-item {{ $isTemplate ? 'mobile-workspace-item-active' : '' }}" @if($isTemplate) aria-current="page" @endif><x-icon name="image" />Certificate Setup</a>
        <a href="{{ route('admin.certificates.studio', $webinar) }}" class="mobile-workspace-item {{ $isStudio ? 'mobile-workspace-item-active' : '' }}" @if($isStudio) aria-current="page" @endif><x-icon name="send" />Certificate Studio</a>
    </div>

    @endif
    <div class="space-y-1">
        <p class="mobile-workspace-heading">More</p>
        @if(! $webinar->isUploadOnly())
        @if($navCanParticipants)<a href="{{ route('admin.webinars.documents.index', $webinar) }}" class="mobile-workspace-item {{ request()->routeIs('admin.webinars.documents.*') ? 'mobile-workspace-item-active' : '' }}" @if(request()->routeIs('admin.webinars.documents.*')) aria-current="page" @endif><x-icon name="folder" />Uploaded documents</a>@endif
        @if($navCanSend)<a href="{{ route('admin.webinars.messages.index', $webinar) }}" class="mobile-workspace-item {{ request()->routeIs('admin.webinars.messages.*') ? 'mobile-workspace-item-active' : '' }}" @if(request()->routeIs('admin.webinars.messages.*')) aria-current="page" @endif><x-icon name="mail" />Send a message</a>@endif
        @if($navCanParticipants)<a href="{{ route('admin.webinars.reports', $webinar) }}" class="mobile-workspace-item {{ $isReports ? 'mobile-workspace-item-active' : '' }}"><x-icon name="bar-chart" />Reports &amp; analytics</a>@endif
        @endif
        @if($navCanSettings)<a href="{{ route('admin.webinars.edit', $webinar) }}" class="mobile-workspace-item {{ $isSettings ? 'mobile-workspace-item-active' : '' }}"><x-icon name="edit" />Webinar settings</a>@endif
        @if(! $webinar->isUploadOnly() && $navCanSend)<a href="{{ route('admin.webinars.email-logs.index', $webinar) }}" class="mobile-workspace-item {{ request()->routeIs('admin.webinars.email-logs.*') ? 'mobile-workspace-item-active' : '' }}"><x-icon name="mail" />Email delivery log</a>@endif
        @if($navCanAudit)<a href="{{ route('admin.webinars.audit', $webinar) }}" class="mobile-workspace-item {{ request()->routeIs('admin.webinars.audit') ? 'mobile-workspace-item-active' : '' }}"><x-icon name="shield" />Audit Trail</a>@endif
        @if(! $webinar->isUploadOnly() && $navCanParticipants)<a href="{{ route('admin.participants.export', $webinar) }}" class="mobile-workspace-item" download><x-icon name="download" />Export pre-send roster</a>@endif
    </div>
</nav>
