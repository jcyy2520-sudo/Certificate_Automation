@props(['webinar'])
@php
    $workflowPolicy = app(\App\Policies\WebinarPolicy::class);
    $workflowCanParticipants = $workflowPolicy->permission(auth()->user(), $webinar, 'manage_participants');
    $workflowCanCertificates = $workflowPolicy->permission(auth()->user(), $webinar, 'manage_certificates');
    $workflowCanSend = $workflowPolicy->permission(auth()->user(), $webinar, 'send_emails');
    $workflowRegistration = $webinar->forms->firstWhere('type', 'registration');
    $workflowSteps = [
        ['number' => 1, 'title' => 'Forms', 'description' => 'Prepare registration, pre-test, post-test and evaluation. Each form has its own questions, share link and Open/Closed switch.', 'href' => $workflowCanParticipants && $workflowRegistration ? route('admin.forms.edit', [$webinar, $workflowRegistration]) : null, 'action' => 'Manage Forms'],
        ['number' => 2, 'title' => 'Responses', 'description' => 'Review online submissions or use Import Responses for a CSV or Excel export. Uploaded documents are stored separately and do not add response rows.', 'href' => $workflowCanParticipants ? route('admin.responses.index', [$webinar, 'type' => 'registration']) : null, 'action' => 'Review Responses'],
        ['number' => 3, 'title' => 'Eligibility', 'description' => 'Check required activities, review participant completion and resolve uncertain import rows before preparing certificates.', 'href' => $workflowCanParticipants ? route('admin.webinars.eligibility.index', $webinar) : null, 'action' => 'Review Eligibility'],
        ['number' => 4, 'title' => 'Certificate setup', 'description' => 'Prepare the certificate design and saved email. Preview the result before sending.', 'href' => $workflowCanCertificates ? route('admin.certification.edit', $webinar) : null, 'action' => 'Open Certificate Setup'],
        ['number' => 5, 'title' => 'Send', 'description' => 'Inspect sample certificates, choose recipients and confirm sending in Certificate Studio. Check the email log for delivery results.', 'href' => $workflowCanCertificates ? route('admin.certificates.studio', $webinar) : null, 'action' => 'Open Certificate Studio'],
    ];
    $workflowSteps = array_filter($workflowSteps, fn ($step) => $step['href'] !== null);
@endphp
@if(! $webinar->isUploadOnly() && count($workflowSteps))
<section class="panel mb-6 overflow-hidden" aria-labelledby="webinar-workflow-heading">
    <div class="border-b border-slate-100 px-5 py-4">
        <h2 id="webinar-workflow-heading" class="section-title">Run this webinar</h2>
        <p class="mt-1 text-sm leading-6 text-slate-500">Follow these steps, or return to any step as your webinar changes.</p>
    </div>
    <ol class="webinar-workflow-list" role="list">
        @foreach($workflowSteps as $step)
        <li value="{{ $step['number'] }}">
            <span class="webinar-workflow-number" aria-hidden="true">{{ $step['number'] }}</span>
            <div class="min-w-0 flex-1">
                <h3 class="text-sm font-semibold">{{ $step['title'] }}</h3>
                <p class="mt-1 max-w-3xl text-[13px] leading-6 text-slate-500">{{ $step['description'] }}</p>
                @if($step['number'] === 3)
                    <a class="mt-1 inline-flex min-h-11 items-center text-[13px] font-medium text-accent-700 underline underline-offset-4" href="{{ route('admin.responses.index', [$webinar, 'type' => 'registration', 'view' => 'settings']) }}">Eligibility Settings</a>
                @elseif($step['number'] === 5 && ! $workflowCanSend)
                    <p class="mt-1 text-[13px] leading-6 text-slate-500">Your administrator must grant email permission before you can send.</p>
                @endif
            </div>
            <a class="button-secondary" href="{{ $step['href'] }}">{{ $step['action'] }}<x-icon name="chevron-right" class="size-4" /></a>
        </li>
        @endforeach
    </ol>
</section>
@endif
