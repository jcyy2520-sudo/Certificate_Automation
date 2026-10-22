@extends('layouts.auth')
@section('heading', 'Unlock webinar')
@section('subheading', $webinar->title)
@section('content')
<form novalidate method="POST" action="{{ route('admin.webinars.unlock.store', $webinar) }}" class="grid gap-5">
@csrf
<p class="text-sm leading-6">This webinar has an additional management password. Once unlocked, you can move between its pages during this session.</p>
<x-account-field name="password" label="Webinar password" type="password" autocomplete="current-password" />
<button class="button-primary">Unlock webinar</button>
</form>
<a class="mt-5 block text-sm text-accent-600 hover:underline" href="{{ route('admin.webinars.index') }}">Back to webinars</a>
@endsection
