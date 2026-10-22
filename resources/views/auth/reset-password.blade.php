@extends('layouts.auth')
@section('heading', $setup ? 'Set your password' : 'Reset your password')
@section('subheading', 'Use at least 15 characters. A passphrase works well.')
@section('content')
<form novalidate method="POST" action="{{ route('password.update') }}" class="grid gap-5" data-account-link>
@csrf
<input type="hidden" name="token" data-account-link-value="token" value="">
<input type="hidden" name="email" data-account-link-value="email" value="">
<x-account-field name="password" label="New password" type="password" autocomplete="new-password" />
<x-account-field name="password_confirmation" label="Confirm password" type="password" autocomplete="new-password" />
<button class="button-primary">Save password</button>
</form>
<p class="mt-5 text-sm"><a class="text-accent-600 hover:underline" href="{{ route('password.request') }}">Request a new password link</a></p>
@endsection
