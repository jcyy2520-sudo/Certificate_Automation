@extends('layouts.auth')
@section('title', 'Sign in')
@section('heading', 'Sign in')
@section('subheading', 'Sign in to manage your webinars.')
@section('content')
<form method="POST" action="{{ route('login.store') }}" class="grid gap-5">
    @csrf
    <label class="field-label">Email
        <input class="field" type="email" name="email" value="{{ old('email') }}" required autofocus autocomplete="email">
    </label>

    <label class="field-label">Password
        <input class="field" type="password" name="password" required autocomplete="current-password">
    </label>

    @if(config('security.allow_admin_remember_me'))
        <label class="flex items-center gap-3 text-[13px] text-slate-600">
            <input class="survey-check size-5" type="checkbox" name="remember" value="1">
            Keep me signed in on this private device
        </label>
    @endif

    <button class="button-primary mt-1 w-full">Sign in</button>
</form>
<div class="mt-5 grid gap-3 text-sm">
    <a class="text-accent-600 hover:underline" href="{{ route('password.request') }}">Forgot password or expired setup link?</a>
    <a class="text-accent-600 hover:underline" href="{{ route('account.request') }}">Request an account</a>
    <a class="text-accent-600 hover:underline" href="{{ route('account.status') }}">About account approval and access</a>
</div>
@endsection
@section('footnote', 'This system is private. Every sign-in attempt is recorded.')
