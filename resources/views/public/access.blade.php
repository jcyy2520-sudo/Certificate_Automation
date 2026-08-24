@extends('public.layout')
@section('title', 'Confirm your email')
@section('content')
@php
    // ?sent=1 (set on redirect) keeps this screen through a refresh; the flash
    // covers the very first render before the query string is in place.
    $requested = request()->boolean('sent') || session('participant_access_requested');
    $sentEmail = session('pf_verify_email');
    // Mask the address for the success screen: first character + domain.
    $maskedEmail = null;
    if (is_string($sentEmail) && str_contains($sentEmail, '@')) {
        [$local, $domain] = explode('@', $sentEmail, 2);
        $maskedEmail = mb_substr($local, 0, 1).str_repeat('•', max(2, mb_strlen($local) - 1)).'@'.$domain;
    }
    $resendCooldown = 45;
@endphp

@if($requested)
    {{-- ── Success screen ─────────────────────────────────────────────── --}}
    <div class="text-center">
        <span class="mx-auto grid size-14 place-items-center rounded-full bg-emerald-50 text-emerald-600">
            <x-icon name="mail" class="size-7" />
        </span>
        <h1 class="mt-5 text-[22px] font-bold leading-8 text-slate-900">Check your inbox</h1>
        <p class="mx-auto mt-3 max-w-md text-[14px] leading-6 text-slate-600">
            @if($maskedEmail)
                Thank you. We’ve sent a confirmation link to <span class="font-medium text-slate-800">{{ $maskedEmail }}</span>.
            @else
                Thank you. We’ve sent a confirmation link to your email.
            @endif
            Please open it to continue to the form.
        </p>
        <p class="mx-auto mt-2 max-w-md text-[12px] leading-5 text-slate-400">
            It can take a minute to arrive. If you don’t see it, please check your spam or junk folder.
        </p>
    </div>

    <div class="mt-8 rounded-xl border border-slate-200 bg-white p-5 text-center">
        <p class="text-[13px] text-slate-600">Didn’t receive the email?</p>
        <form class="mt-3" method="POST" action="{{ route('forms.public.access.request', $form->public_token) }}" data-resend-form>
            @csrf
            <input type="hidden" name="email" value="{{ $sentEmail }}">
            <button class="button-primary w-full sm:w-auto sm:min-w-56" data-resend-button disabled>
                <span data-resend-idle class="hidden">Resend confirmation email</span>
                <span data-resend-wait>Resend available in <span data-resend-countdown>{{ $resendCooldown }}</span>s</span>
                <span data-resend-sending class="hidden">Sending…</span>
            </button>
        </form>
        <p class="mt-4 text-[12px] text-slate-400">
            Entered the wrong address? <a class="font-medium text-accent-600 hover:underline" href="{{ route('forms.public', $form->public_token) }}">Start over</a>.
        </p>
    </div>

    <script nonce="{{ $cspNonce }}">
        (function () {
            var form = document.querySelector('[data-resend-form]');
            if (!form) return;
            var button = form.querySelector('[data-resend-button]');
            var idle = button.querySelector('[data-resend-idle]');
            var wait = button.querySelector('[data-resend-wait]');
            var sending = button.querySelector('[data-resend-sending]');
            var countdown = button.querySelector('[data-resend-countdown]');
            var remaining = {{ $resendCooldown }};

            var tick = function () {
                remaining -= 1;
                if (remaining <= 0) {
                    button.disabled = false;
                    wait.classList.add('hidden');
                    idle.classList.remove('hidden');
                    return;
                }
                countdown.textContent = remaining;
                window.setTimeout(tick, 1000);
            };
            window.setTimeout(tick, 1000);

            // Show a clear sending state instead of a button that looks stuck.
            form.addEventListener('submit', function () {
                button.disabled = true;
                idle.classList.add('hidden');
                wait.classList.add('hidden');
                sending.classList.remove('hidden');
            });
        })();
    </script>
@else
    {{-- ── Email entry ────────────────────────────────────────────────── --}}
    <header class="text-center">
        <p class="text-[15px] leading-6 text-slate-500">{{ $form->webinar->title }}</p>
        <h1 class="mt-1 text-[22px] font-bold leading-8 text-slate-900">Confirm your email to continue</h1>
        <p class="mx-auto mt-4 max-w-md text-[14px] leading-6 text-slate-600">
            Enter your email address and we’ll send you a secure link to open this form. This keeps your responses connected to you across the webinar.
        </p>
    </header>

    @if(session('participant_access_error'))
        <div class="mt-8 flex items-start gap-2.5 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-900" role="alert">
            <x-icon name="info" class="mt-0.5 size-4 shrink-0" />
            <span>That link was invalid or has expired. Enter your email below and we’ll send you a fresh one.</span>
        </div>
    @endif

    @error('email')
        <div class="mt-8 flex items-start gap-2.5 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] leading-5 text-red-900" role="alert">
            <x-icon name="alert" class="mt-0.5 size-4 shrink-0" />
            <span>{{ $message }}</span>
        </div>
    @enderror

    <form class="mt-8" method="POST" action="{{ route('forms.public.access.request', $form->public_token) }}" data-access-form>
        @csrf
        <label class="block">
            <span class="text-[15px] text-slate-600">Email address <span class="text-red-600">*</span></span>
            <input class="survey-input @error('email') border-red-400 @enderror" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus inputmode="email">
        </label>

        <div class="mt-8 flex flex-col items-center gap-4">
            <button class="button-primary w-full sm:w-auto sm:min-w-56" data-access-button>
                <span data-access-label>Confirm</span>
                <span class="hidden items-center gap-2" data-access-sending>
                    <span class="size-4 animate-spin rounded-full border-2 border-white/40 border-t-white"></span>Sending…
                </span>
            </button>
            <p class="max-w-md text-center text-[12px] leading-5 text-slate-400">
                We only use your address to send this secure link. If you don’t continue, the unverified record is scheduled for deletion within {{ (int) ceil(config('webinar.unverified_participant_retention_minutes', 1440) / 60) }} hours.
            </p>
        </div>
    </form>

    <script nonce="{{ $cspNonce }}">
        (function () {
            var form = document.querySelector('[data-access-form]');
            if (!form) return;
            var button = form.querySelector('[data-access-button]');
            var label = button.querySelector('[data-access-label]');
            var sending = button.querySelector('[data-access-sending]');
            form.addEventListener('submit', function () {
                button.disabled = true;
                label.classList.add('hidden');
                sending.classList.remove('hidden');
                sending.classList.add('inline-flex');
            });
        })();
    </script>
@endif
@endsection
