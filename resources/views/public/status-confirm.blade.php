@extends('public.layout')
@section('title', 'Confirm status access')
@section('content')
<header class="text-center">
    <p class="text-[22px] leading-8 text-slate-800">{{ $form->webinar->title }}</p>
    <h1 class="mt-1 text-[22px] font-bold leading-9 text-slate-900">Confirm status access</h1>
    <p class="mx-auto mt-4 max-w-md text-[13px] leading-5 text-slate-600">
        Select continue to verify that you opened the email. This extra click prevents automated link previews from using your one-time link.
    </p>
</header>

<div class="mt-10">
    <form method="POST" action="{{ route('forms.public.status.access.consume', $form->public_token) }}" data-access-confirm>
        @csrf
        <input type="hidden" name="access_token" value="" data-access-token>
        <button class="button-primary w-full" type="submit" disabled data-access-button>Continue to participant status</button>
    </form>
    <p class="mt-4 text-center text-[13px] leading-5 text-amber-700" hidden data-access-missing>
        This link is incomplete. Return to the status page and request a new email.
    </p>
</div>

<script nonce="{{ $cspNonce }}">
    (function () {
        var tokenInput = document.querySelector('[data-access-token]');
        var button = document.querySelector('[data-access-button]');
        var missing = document.querySelector('[data-access-missing]');
        var params = new URLSearchParams(window.location.hash.slice(1));
        var token = params.get('token') || '';

        history.replaceState(null, '', window.location.pathname);

        if (/^[a-f0-9]{64}$/.test(token)) {
            tokenInput.value = token;
            button.disabled = false;
        } else {
            missing.hidden = false;
        }
    })();
</script>
@endsection
