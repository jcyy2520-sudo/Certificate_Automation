@extends('layouts.app')
@section('title', 'Security')
@section('content')

<x-page-header title="Two-factor authentication" :crumbs="['Dashboard' => route('admin.dashboard')]"
               subtitle="A time-based code from your authenticator app, required after your password. The secret and recovery codes are stored encrypted." />

@if($recoveryCodes)
    <section class="mb-6 rounded-xl border border-amber-200 bg-amber-50 p-5">
        <h2 class="section-title text-amber-900">Save your recovery codes</h2>
        <p class="mt-1 text-[13px] text-amber-900">Shown once. Each one signs you in a single time if you lose your device.</p>
        <ul class="mt-4 grid gap-1.5 font-mono text-[13px] text-amber-950 sm:grid-cols-2">
            @foreach($recoveryCodes as $code)<li class="rounded-md border border-amber-200 bg-white px-3 py-1.5">{{ $code }}</li>@endforeach
        </ul>
    </section>
@endif

<div class="max-w-3xl space-y-6">
    @if($user->two_factor_confirmed_at)
        <section class="panel p-5">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2.5">
                        <h2 class="section-title">Enabled</h2>
                        <span class="badge badge-green">on</span>
                    </div>
                    <p class="mt-1 text-[13px] text-slate-500 tabular-nums">Confirmed {{ $user->two_factor_confirmed_at->format('F j, Y') }} · {{ $remainingRecoveryCodes }} recovery codes left.</p>
                </div>
            </div>
            <form class="mt-4 grid max-w-sm gap-4 border-t border-slate-100 pt-4" method="POST" action="{{ route('admin.two-factor.recovery-codes') }}" data-confirm="Generate a new set? Your current recovery codes stop working.">@csrf
                <label class="field-label">Current password<input class="field" type="password" name="password" required autocomplete="current-password"></label>
                <button class="button-secondary justify-self-start">Regenerate recovery codes</button>
            </form>
        </section>

        <section class="panel p-5">
            <h2 class="section-title">Turn it off</h2>
            <p class="mt-1 text-[13px] text-slate-500">Confirm your password to remove the second factor.</p>
            <form class="mt-4 grid max-w-sm gap-4" method="POST" action="{{ route('admin.two-factor.disable') }}">@csrf @method('DELETE')
                <label class="field-label">Current password<input class="field" type="password" name="password" required autocomplete="current-password"></label>
                <button class="button-danger justify-self-start">Disable two-factor authentication</button>
            </form>
        </section>
    @else
        <section class="panel p-5 sm:p-6">
            <div class="flex items-center gap-2.5">
                <h2 class="section-title">Set it up</h2>
                <span class="badge badge-slate">off</span>
            </div>

            <div class="mt-5 grid gap-6 sm:grid-cols-[188px_minmax(0,1fr)] sm:items-start">
                <div class="shrink-0">
                    @if($qrCode)
                        <img class="w-full max-w-47 rounded-lg border border-slate-200 bg-white p-2" src="{{ $qrCode }}" alt="Two-factor setup QR code">
                    @else
                        <p class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-[13px] text-slate-500">QR rendering is unavailable on this server. Enter the key by hand.</p>
                    @endif
                </div>

                <div>
                    <p class="text-[13px] font-medium text-slate-900">1 · Scan the code</p>
                    <p class="mt-1 text-[13px] text-slate-500">Google Authenticator, 1Password, Aegis, or any TOTP app. If the camera will not read it, type this key instead:</p>
                    <p class="mt-2.5 break-all rounded-md border border-slate-200 bg-slate-50 px-3 py-2 font-mono text-[13px]">{{ $secret }}</p>

                    <p class="mt-6 text-[13px] font-medium text-slate-900">2 · Confirm the first code</p>
                    <form class="mt-2.5 grid max-w-sm gap-4" method="POST" action="{{ route('admin.two-factor.enable') }}">@csrf
                        <label class="field-label">Six-digit code
                            <input class="field w-40 text-center font-mono tracking-[0.3em]" name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="000000" required>
                        </label>
                        <label class="field-label">Current password
                            <input class="field" type="password" name="password" required autocomplete="current-password">
                        </label>
                        <button class="button-primary justify-self-start">Enable</button>
                    </form>
                </div>
            </div>
        </section>
    @endif
</div>
@endsection
