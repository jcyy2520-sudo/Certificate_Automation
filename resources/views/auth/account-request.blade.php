@extends('layouts.auth')
@section('heading', 'Request an account')
@section('subheading', 'Request access. After administrator approval, an email invitation lets you activate your account.')
@section('content')
<form novalidate method="POST" action="{{ route('account.store') }}" class="grid gap-5">
    @csrf
    <x-account-field name="name" label="Full name" autocomplete="name" />
    <x-account-field name="email" label="Email address" type="email" autocomplete="email" />
    <button class="button-primary w-full">Request account</button>
</form>
<p class="mt-5 text-sm"><a class="text-accent-600 hover:underline" href="{{ route('login') }}">Back to sign in</a></p>
@endsection
