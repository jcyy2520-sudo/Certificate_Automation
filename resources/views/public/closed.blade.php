@extends('public.layout')
@section('title', 'Form closed')
@section('content')
@php
    $titleWords = preg_split('/\s+/', trim($form->title)) ?: [$form->title];
    $lastWord = array_pop($titleWords);
    $leadWords = implode(' ', $titleWords);
@endphp

<header class="text-center">
    <p class="text-[22px] leading-8 text-slate-800">{{ $form->webinar->title }}</p>
    <h1 class="mt-1 text-[22px] font-bold leading-9 text-slate-900">
        @if($leadWords !== ''){{ $leadWords }} @endif<span class="rounded bg-slate-400 px-2 py-0.5 text-white">{{ $lastWord }}</span>
    </h1>
</header>

<div class="stepper mt-9 h-4 justify-between">
    <span class="stepper-track"></span>
    <span class="stepper-dot"></span>
    <span class="stepper-dot"></span>
    <span class="stepper-dot"></span>
</div>

<div class="mt-12 text-center">
    <div class="mx-auto grid size-14 place-items-center rounded-full bg-slate-300">
        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" aria-hidden="true"><path d="M18 6 6 18M6 6l12 12"/></svg>
    </div>

    <h2 class="mt-6 text-[20px] font-bold text-slate-900">{{ $form->closedReason() }}</h2>

    @if($form->opens_at && $form->opens_at->isFuture())
        <p class="mx-auto mt-3 max-w-sm text-[15px] leading-6 text-slate-600">It opens on {{ $form->opens_at->format('F j, Y \a\t g:i A') }}.</p>
    @endif

    <p class="mx-auto mt-10 max-w-sm border-t border-slate-200 pt-6 text-[13px] leading-5 text-slate-500">If you believe this is a mistake, contact the event organizer.</p>
</div>
@endsection
