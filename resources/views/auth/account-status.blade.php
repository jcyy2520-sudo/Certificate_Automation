@extends('layouts.auth')
@section('heading', 'Account request')
@section('subheading', 'Administrator review, then a secure invitation to activate your account.')
@section('content')
<div class="space-y-4 text-sm leading-6">
@if(app(\App\Services\AccountEmailReadiness::class)->ready())
<p>Your request is visible to the administrator immediately. After approval, check your inbox and spam folder for the activation invitation. Open the link to verify your address and choose your password. Queued email may take a few minutes to reach the provider.</p>
@else
<p>Account email delivery is currently unavailable. Requests are still saved for administrative review. After delivery is restored and your request is approved, an activation link will let you verify your address and choose your password.</p>
@endif
<p>If your request was rejected or your account was suspended, contact the platform administrator. Account recovery cannot restore restricted access.</p>
<p><a class="text-accent-600 hover:underline" href="{{ route('account.request') }}">Return to account request</a></p>
<p><a class="text-accent-600 hover:underline" href="{{ route('password.request') }}">Request a new password setup link</a></p>
<p><a class="text-accent-600 hover:underline" href="{{ route('login') }}">Back to sign in</a></p>
</div>
@endsection
