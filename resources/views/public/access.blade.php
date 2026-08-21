@extends('public.layout')
@section('title', 'Secure form access')
@section('content')
<header class="text-center">
    <p class="text-[22px] leading-8 text-slate-800">{{ $form->webinar->title }}</p>
    <h1 class="mt-1 text-[22px] font-bold leading-9 text-slate-900">Verify your email to continue</h1>
    <p class="mx-auto mt-4 max-w-md text-[13px] leading-5 text-slate-600">
        We use a short-lived, one-time link so another person cannot submit this form using only your email address.
    </p>
    <p class="mx-auto mt-2 max-w-md text-[12px] leading-5 text-slate-500">
        Your address is used to deliver that link. If you do not continue, the unverified record is scheduled for deletion within {{ (int) ceil(config('webinar.unverified_participant_retention_minutes', 1440) / 60) }} hours.
    </p>
</header>

@if(session('participant_access_requested'))
    <div class="mt-8 rounded-xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-[13px] leading-5 text-emerald-900" role="status">
        If that address can receive an access email, a link is on its way. Check spam or request another after a few minutes.
    </div>
@endif

@if(session('participant_access_error'))
    <div class="mt-8 rounded-xl border border-amber-200 bg-amber-50 px-5 py-4 text-[13px] leading-5 text-amber-900" role="alert">
        That access link is invalid or has expired. Request a new one below.
    </div>
@endif

@if($errors->any())
    <div class="mt-8 rounded-xl border border-red-300 bg-red-50 px-5 py-4 text-[13px] text-red-900" role="alert">
        {{ $errors->first('email') }}
    </div>
@endif

<form class="mt-10" method="POST" action="{{ route('forms.public.access.request', $form->public_token) }}">
    @csrf
    <label class="block">
        <span class="text-[15px] text-slate-600">Email address <span class="text-red-600">*</span></span>
        <input class="survey-input @error('email') border-red-400 @enderror" type="email" name="email" value="{{ old('email') }}" required autocomplete="email" autofocus>
    </label>

    <div class="mt-8 flex flex-col items-center gap-4">
        <button class="button-primary w-full sm:w-auto sm:min-w-50">Email me a secure link</button>
        <p class="text-center text-[12px] leading-5 text-slate-400">The response above is the same whether or not an address already has a record. The full event retention notice appears before submission.</p>
    </div>
</form>
@endsection
