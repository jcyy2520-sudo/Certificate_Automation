@extends('public.layout')
@section('title', 'Participant status')
@section('content')
@php
    $labels = [
        'registration' => 'Registration completed',
        'attendance' => 'Attendance recorded',
        'pretest' => 'Pre-assessment completed',
        'posttest' => 'Post-assessment completed',
        'evaluation' => 'Evaluation completed',
    ];
@endphp

<header class="text-center">
    <p class="text-[22px] leading-8 text-slate-800">{{ $form->webinar->title }}</p>
    <h1 class="mt-1 text-[22px] font-bold leading-9 text-slate-900">Participant status</h1>
    <p class="mx-auto mt-3 max-w-md text-[13px] leading-5 text-slate-600">A private, read-only summary of your certificate requirements.</p>
</header>

<section class="mt-10 overflow-hidden rounded-xl border border-slate-200 bg-white">
    <div class="border-b border-slate-100 px-5 py-4">
        <div class="flex items-center justify-between gap-4">
            <h2 class="text-[15px] font-semibold text-slate-900">Required steps</h2>
            <span class="badge {{ $evaluation['eligible'] ? 'badge-green' : 'badge-slate' }}">{{ $evaluation['eligible'] ? 'Complete' : 'In progress' }}</span>
        </div>
        @if($evaluation['overridden'])
            <p class="mt-2 text-[12px] leading-5 text-amber-700">The organizer has recorded an eligibility decision for this participant record.</p>
        @endif
    </div>

    <div class="divide-y divide-slate-100">
        @forelse($evaluation['requirements'] as $requirement => $met)
            <div class="flex items-center justify-between gap-4 px-5 py-4">
                <div class="flex items-center gap-3">
                    <span class="grid size-6 shrink-0 place-items-center rounded-full {{ $met ? 'bg-emerald-600 text-white' : 'border border-slate-300 bg-white text-slate-400' }}">
                        @if($met)<x-icon name="check" class="size-3.5" />@else<span class="size-1.5 rounded-full bg-slate-300"></span>@endif
                    </span>
                    <span class="text-[13px] font-medium text-slate-800">{{ $labels[$requirement] ?? ucfirst(str_replace('_', ' ', $requirement)) }}</span>
                </div>
                <span class="text-[12px] font-medium {{ $met ? 'text-emerald-700' : 'text-slate-500' }}">{{ $met ? 'Completed' : 'Still required' }}</span>
            </div>
        @empty
            <p class="px-5 py-8 text-center text-[13px] text-slate-500">No automatic requirements are currently listed.</p>
        @endforelse
    </div>
</section>

<section class="mt-6 rounded-xl border border-slate-200 bg-white p-5">
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="text-[15px] font-semibold text-slate-900">Certificate</h2>
            @if($certificate)
                <p class="mt-1 text-[13px] leading-5 text-slate-600">Your certificate has been issued and is ready to download.</p>
                <p class="mt-2 font-mono text-[12px] text-slate-500">{{ $certificate->verification_code }}</p>
            @else
                <p class="mt-1 text-[13px] leading-5 text-slate-600">No valid certificate is available yet.</p>
            @endif
        </div>
        @if($certificate)
            <a class="button-success shrink-0" href="{{ route('forms.public.status.certificate.download', [$form->public_token, $certificate->public_id]) }}">Download certificate</a>
        @else
            <span class="badge badge-slate shrink-0">Not available</span>
        @endif
    </div>
</section>

<p class="mx-auto mt-8 max-w-md text-center text-[12px] leading-5 text-slate-400">This page is read-only. Return to the organizer’s form links to complete any outstanding steps.</p>
@endsection
