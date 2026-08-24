@extends('layouts.app')
@section('title', 'Dashboard')
@section('content')

@php $isEmpty = $webinars->isEmpty(); @endphp

<x-page-header title="Dashboard">
    @if(! $isEmpty)
        <x-slot:actions>
            <a class="button-primary" href="{{ route('admin.webinars.create') }}"><x-icon name="plus" class="size-4" />New webinar</a>
        </x-slot:actions>
    @endif
</x-page-header>

@unless(auth()->user()->two_factor_confirmed_at)
    <div class="mb-6 flex flex-col gap-4 rounded-xl border border-amber-200 bg-amber-50 p-5 sm:flex-row sm:items-center sm:justify-between">
        <div class="flex gap-3.5">
            <x-icon name="shield" class="mt-0.5 size-5 shrink-0 text-amber-700" />
            <div>
                <p class="text-sm font-semibold text-amber-900">Two-factor authentication is off</p>
                <p class="mt-1 text-[13px] leading-5 text-amber-800">Your password is the only thing protecting this system.</p>
            </div>
        </div>
        <a class="button-secondary shrink-0" href="{{ route('admin.two-factor.show') }}">Turn it on</a>
    </div>
@endunless

@if($isEmpty)
    {{-- First run. A row of zeroes would tell the administrator nothing, so the
         empty state explains what creating a webinar actually sets up. --}}
    <section class="panel px-6 py-10 text-center sm:px-10 sm:py-14">
        <span class="mx-auto grid size-11 place-items-center rounded-xl bg-slate-100 text-slate-500">
            <x-icon name="layers" />
        </span>
        <h2 class="mt-5 text-[17px] font-semibold tracking-[-0.01em]">No webinars yet</h2>
        <p class="mx-auto mt-2 max-w-[52ch] text-sm leading-6 text-slate-500">
            A webinar carries its own registration, attendance, evaluation and assessment forms — each on a
            separate share link — plus the requirements a participant has to meet before a certificate is issued.
        </p>
        <a class="button-primary mt-6" href="{{ route('admin.webinars.create') }}">
            <x-icon name="plus" class="size-4" />Create the first webinar
        </a>
    </section>
@else
    <dl class="panel flex flex-wrap divide-x divide-slate-200">
        @foreach([['Webinars', $stats['webinars']], ['Participants', $stats['participants']], ['Certificates', $stats['certificates']], ['Emails sent', $stats['emails']]] as [$label, $value])
            <div class="min-w-[8.5rem] flex-1 px-5 py-4">
                <dt class="text-[13px] text-slate-500">{{ $label }}</dt>
                <dd class="mt-1 text-[22px] font-semibold leading-none tracking-[-0.02em] tabular-nums">{{ number_format($value) }}</dd>
            </div>
        @endforeach
    </dl>

    <div class="mt-6 grid gap-6 xl:grid-cols-[1.4fr_1fr]">
        <section class="panel overflow-hidden">
            <div class="flex items-center justify-between border-b border-slate-100 px-5 py-4">
                <h2 class="section-title">Recent webinars</h2>
                <a class="text-[13px] font-medium text-accent-600 hover:underline" href="{{ route('admin.webinars.index') }}">View all</a>
            </div>
            <div class="divide-y divide-slate-100">
                @foreach($webinars as $webinar)
                    <a href="{{ route('admin.webinars.show', $webinar) }}" class="flex items-center justify-between gap-4 px-5 py-3.5 transition hover:bg-slate-50">
                        <div class="min-w-0">
                            <p class="truncate text-sm font-medium">{{ $webinar->title }}</p>
                            <p class="mt-0.5 text-[12px] text-slate-500 tabular-nums">{{ $webinar->participants_count }} participants · {{ $webinar->certificates_count }} certificates</p>
                        </div>
                        <span class="badge shrink-0 {{ $webinar->isOpen() ? 'badge-green' : 'badge-slate' }}">{{ $webinar->availabilityLabel() }}</span>
                    </a>
                @endforeach
            </div>
        </section>

        <section class="panel overflow-hidden">
            <div class="border-b border-slate-100 px-5 py-4">
                <h2 class="section-title">Email activity</h2>
            </div>
            <div class="divide-y divide-slate-100">
                @forelse($deliveries as $delivery)
                    @php
                        $maskedEmail = null;
                        if (is_string($delivery->recipient_email) && str_contains($delivery->recipient_email, '@')) {
                            [$local, $domain] = explode('@', $delivery->recipient_email, 2);
                            $maskedEmail = mb_substr($local, 0, 1).str_repeat('•', max(2, mb_strlen($local) - 1)).'@'.$domain;
                        }
                    @endphp
                    <div class="px-5 py-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <p class="truncate text-[13px] font-medium">{{ $delivery->subject }}</p>
                            <span class="badge shrink-0 {{ $delivery->status === 'sent' ? 'badge-green' : ($delivery->status === 'failed' ? 'badge-red' : 'badge-slate') }}">{{ $delivery->status }}</span>
                        </div>
                        <p class="mt-0.5 truncate text-[12px] text-slate-500">{{ $maskedEmail ?: 'Recipient unavailable' }}</p>
                    </div>
                @empty
                    <p class="px-5 py-12 text-center text-sm text-slate-500">Certificate emails will appear here once you issue some.</p>
                @endforelse
            </div>
        </section>
    </div>
@endif
@endsection
