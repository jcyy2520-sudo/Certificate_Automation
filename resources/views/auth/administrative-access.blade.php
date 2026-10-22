@extends('layouts.app')
@section('title', 'Administrator access')
@section('content')
<x-page-header title="Administrator access" />
<form novalidate method="POST" action="{{ route('admin.webinars.administrative-access.store', $webinar) }}" class="panel grid max-w-xl gap-5 p-5" data-confirm="Access to this owner's webinar will be recorded. Continue?" data-confirm-tone="neutral" data-confirm-action="Access webinar">
@csrf
<p class="text-sm leading-6">Access <strong>{{ $webinar->title }}</strong> using your administrator authority. Access and changes are recorded. This permission expires after one hour.</p>
<input type="hidden" name="confirmed" value="1">
<button class="button-primary justify-self-start">Access webinar</button>
</form>
@endsection
