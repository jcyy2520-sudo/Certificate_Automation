@extends('layouts.auth')
@section('heading', 'Verify your email')
@section('subheading', 'Confirm your request to join the platform.')
@section('content')
<form novalidate method="POST" action="{{ route('account.verify.consume', $user) }}" class="grid gap-5" data-account-link>
@csrf
<input type="hidden" name="token" data-account-link-value="token" value="">
<p class="text-sm leading-6">Verify your email address to send your account request for administrator review. This does not activate an account or send any certificates.</p>
<button class="button-primary">Verify email address</button>
</form>
@endsection
