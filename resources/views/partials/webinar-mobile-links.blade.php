<nav class="flex-1 space-y-5 overflow-y-auto px-4 py-5">
    <div class="space-y-1">
        <p class="mobile-workspace-heading">Participants</p>
        @if($registrationForm = $navForms['registration'] ?? null)
            <a href="{{ route('admin.forms.edit', [$webinar, $registrationForm]) }}" class="mobile-workspace-item {{ $formActive('registration') ? 'mobile-workspace-item-active' : '' }}"><x-icon name="link" />Registration form &amp; link</a>
        @endif
        <a href="{{ route('admin.participants.index', $webinar) }}" class="mobile-workspace-item {{ $isParticipants ? 'mobile-workspace-item-active' : '' }}"><x-icon name="grid" />Registered participants</a>
        <a href="{{ route('admin.webinars.imports.index', $webinar) }}" class="mobile-workspace-item {{ $isImports ? 'mobile-workspace-item-active' : '' }}"><x-icon name="files" />CSV imports</a>
    </div>

    <div class="space-y-1">
        <p class="mobile-workspace-heading">Tests</p>
        @foreach(['pretest' => 'Pre-test', 'posttest' => 'Post-test', 'evaluation' => 'Evaluation'] as $type => $label)
            @if($form = $navForms[$type] ?? null)
                <a href="{{ route('admin.forms.edit', [$webinar, $form]) }}" class="mobile-workspace-item {{ $formActive($type) ? 'mobile-workspace-item-active' : '' }}"><x-icon name="file-text" />{{ $label }}</a>
            @endif
        @endforeach
    </div>

    <div class="space-y-1">
        <p class="mobile-workspace-heading">Certificate</p>
        <a href="{{ route('admin.certification.edit', $webinar) }}" class="mobile-workspace-item {{ $isTemplate ? 'mobile-workspace-item-active' : '' }}"><x-icon name="image" />Template &amp; requirements</a>
        <a href="{{ route('admin.certificates.studio', $webinar) }}" class="mobile-workspace-item {{ $isStudio ? 'mobile-workspace-item-active' : '' }}"><x-icon name="send" />Send certificates</a>
    </div>

    <div class="space-y-1">
        <p class="mobile-workspace-heading">Webinar</p>
        <a href="{{ route('admin.webinars.show', $webinar) }}" class="mobile-workspace-item {{ $isOverview ? 'mobile-workspace-item-active' : '' }}"><x-icon name="home" />Overview</a>
        <a href="{{ route('admin.webinars.reports', $webinar) }}" class="mobile-workspace-item {{ $isReports ? 'mobile-workspace-item-active' : '' }}"><x-icon name="bar-chart" />Reports &amp; analytics</a>
        <a href="{{ route('admin.webinars.edit', $webinar) }}" class="mobile-workspace-item {{ $isSettings ? 'mobile-workspace-item-active' : '' }}"><x-icon name="edit" />Webinar settings</a>
        <a href="{{ route('admin.webinars.email-logs.index', $webinar) }}" class="mobile-workspace-item {{ request()->routeIs('admin.webinars.email-logs.*') ? 'mobile-workspace-item-active' : '' }}"><x-icon name="mail" />Email delivery log</a>
        <a href="{{ route('admin.participants.export', $webinar) }}" class="mobile-workspace-item" download><x-icon name="download" />Export data (CSV)</a>
    </div>
</nav>
