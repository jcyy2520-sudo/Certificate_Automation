@extends('public.layout')
@section('title', 'Registration required')
@section('content')
<header class="text-center">
    <p class="text-[22px] leading-8 text-slate-800">{{ $form->webinar->title }}</p>
    <h1 class="mt-1 text-[22px] font-bold leading-9 text-slate-900">Registration required</h1>
    <div class="mt-8 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-[13px] leading-5 text-amber-900" role="alert">
        {{ $message }}
    </div>
</header>
@endsection
