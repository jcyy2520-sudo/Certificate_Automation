@extends('layouts.webinar')
@section('title', $webinar->title)
@section('content')
<x-page-header :title="$webinar->title" subtitle="Your assigned webinar workspace." />
<section class="panel p-6"><p class="text-sm text-slate-500">{{ $webinar->availabilityLabel() }} · {{ $webinar->starts_at?->timezone('Asia/Manila')->format('M j, Y H:i') ?? 'Not scheduled' }}</p>
@if($webinar->description)<p class="mt-4 whitespace-pre-line text-sm">{{ $webinar->description }}</p>@endif
<p class="mt-4 text-sm text-slate-500">Your administrator controls assignments and permissions. Contact them if you need additional access.</p></section>
<div class="mt-6 flex flex-wrap gap-3">
@if($canParticipants)<a class="button-secondary" href="{{ route('admin.webinars.documents.index', $webinar) }}">Uploaded documents</a>
@unless($webinar->isUploadOnly())<a class="button-secondary" href="{{ route('admin.responses.index', $webinar) }}">Form Responses</a>@endunless @endif
@unless($webinar->isUploadOnly())
@if($canCertificates)<a class="button-secondary" href="{{ route('admin.certification.edit', $webinar) }}">Certificate Setup</a><a class="button-secondary" href="{{ route('admin.certificates.studio', $webinar) }}">Certificate Studio</a>@endif
@if($canSend)<a class="button-secondary" href="{{ route('admin.webinars.messages.index', $webinar) }}">Send a message</a><a class="button-secondary" href="{{ route('admin.webinars.email-logs.index', $webinar) }}">Webinar email logs</a>@endif
@endunless
</div>
@endsection
