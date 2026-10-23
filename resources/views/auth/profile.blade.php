@extends('layouts.app')
@section('title', 'Profile')
@section('content')
<x-page-header title="Profile" />
<form novalidate method="POST" action="{{ route('admin.profile.update') }}" class="panel grid max-w-xl gap-5 p-5">
@csrf @method('PUT')
<x-account-field name="name" label="Full name" :value="auth()->user()->name" autocomplete="name" />
<p class="text-sm text-slate-500">Email: {{ auth()->user()->email }}. Contact the administrator if this address needs to change.</p>
<button class="button-primary justify-self-start">Save profile</button>
</form>
<form novalidate method="POST" action="{{ route('admin.profile.password') }}" class="panel mt-6 grid max-w-xl gap-5 p-5" data-confirm="Change your password and sign out? Other sessions will also require sign-in again." data-confirm-action="Change password" data-confirm-title="Change password">
    @csrf @method('PUT')
    <h2 class="section-title">Change password</h2>
    <p class="text-sm text-slate-500">Use at least 15 characters and a password you do not use elsewhere. Your two-factor authentication setting stays unchanged.</p>
    @if(config('security.require_sensitive_action_password_confirmation', true))
        <x-account-field name="current_password" label="Current password" type="password" autocomplete="current-password" />
    @endif
    <x-account-field name="password" label="New password" type="password" autocomplete="new-password" />
    <x-account-field name="password_confirmation" label="Confirm new password" type="password" autocomplete="new-password" />
    <button class="button-primary justify-self-start">Change password</button>
</form>
@endsection
