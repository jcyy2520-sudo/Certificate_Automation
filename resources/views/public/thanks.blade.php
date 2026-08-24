@extends('public.layout')
@section('title', 'Response recorded')
@section('content')
@php
    $titleWords = preg_split('/\s+/', trim($form->title)) ?: [$form->title];
    $lastWord = array_pop($titleWords);
    $leadWords = implode(' ', $titleWords);
@endphp

<header class="text-center">
    <p class="text-[22px] leading-8 text-slate-800">{{ $form->webinar->title }}</p>
    <h1 class="mt-1 text-[22px] font-bold leading-9 text-slate-900">
        @if($leadWords !== ''){{ $leadWords }} @endif<span class="title-mark">{{ $lastWord }}</span>
    </h1>
</header>

<div class="stepper mt-9 h-2" role="progressbar" aria-label="Form completion" aria-valuemin="0" aria-valuemax="100" aria-valuenow="100">
    <span class="stepper-track"></span>
    <span class="stepper-fill" style="width:100%"></span>
</div>

<div class="mt-12 text-center">
    <div class="mx-auto grid size-14 place-items-center rounded-full bg-accent-600">
        <svg class="size-7" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20 6 9 17l-5-5"/></svg>
    </div>

    <h2 class="mt-6 text-[20px] font-bold text-slate-900">Your response has been recorded</h2>
    <p class="mx-auto mt-3 max-w-sm text-[15px] leading-6 text-slate-600">Thank you for completing this form.</p>

    @if($score)
        <div class="mx-auto mt-8 max-w-xs rounded-2xl border border-slate-200 bg-white px-6 py-5">
            <p class="text-[11px] font-semibold uppercase tracking-[0.14em] text-slate-500">Your score</p>
            <p class="mt-2 text-[34px] font-bold leading-none text-accent-600">
                {{ number_format((float) $score->score, 1) }}<span class="text-[18px] font-normal text-slate-400"> / {{ number_format((float) $score->maximum_score, 1) }}</span>
            </p>
        </div>
    @endif

    <p class="mx-auto mt-10 max-w-sm border-t border-slate-200 pt-6 text-[13px] leading-5 text-slate-500">You can close this page now. If the organizer needs anything further from you, they will be in touch by email.</p>
</div>
@endsection
