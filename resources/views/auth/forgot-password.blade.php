@extends('layouts.auth')
@section('heading', 'Request a password link')
@section('subheading', 'For approved accounts, including expired setup invitations.')
@section('content')
<form novalidate method="POST" action="{{ route('password.email') }}" class="grid gap-5">
@csrf
<x-account-field name="email" label="Email address" type="email" autocomplete="email" />
<button class="button-primary">Email password link</button>
</form>
<p class="mt-5 text-sm"><a class="text-accent-600 hover:underline" href="{{ route('login') }}">Back to sign in</a></p>
@endsection
