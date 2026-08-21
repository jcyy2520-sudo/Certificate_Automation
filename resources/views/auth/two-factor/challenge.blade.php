@extends('layouts.auth')
@section('title', 'Two-factor authentication')
@section('heading', 'Confirm it is you')
@section('subheading', 'Your password was accepted. Enter the code from your authenticator app.')
@section('content')
<form method="POST" action="{{ route('two-factor.challenge.store') }}" class="grid gap-5">
    @csrf
    <label class="field-label">Authentication code
        <input class="field text-center font-mono text-lg tracking-[0.4em]" name="code" inputmode="numeric" autocomplete="one-time-code" autofocus placeholder="000000">
    </label>
    <button class="button-primary w-full">Verify and sign in</button>
</form>

<details class="mt-7 border-t border-slate-200 pt-5">
    <summary class="cursor-pointer text-[13px] font-semibold text-accent-600">Lost your device? Use a recovery code</summary>
    <form class="mt-4 grid gap-4" method="POST" action="{{ route('two-factor.challenge.store') }}">
        @csrf
        <label class="field-label">Recovery code
            <input class="field font-mono" name="recovery_code" placeholder="XXXXX-XXXXX" autocomplete="off">
        </label>
        <p class="text-[12px] leading-5 text-slate-500">Each recovery code works once and is used up when you sign in with it.</p>
        <button class="button-secondary w-full">Use recovery code</button>
    </form>
</details>
@endsection
@section('footnote', 'Close this tab to cancel the sign-in attempt.')
