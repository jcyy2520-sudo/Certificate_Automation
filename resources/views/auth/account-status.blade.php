@extends('layouts.auth')
@section('heading', 'Account request')
@section('subheading', 'Access begins after email verification, approval and password setup.')
@section('content')
<div class="space-y-4 text-sm leading-6">
<p>Check your inbox and spam folder for your verification link. After verification, an administrator reviews your request. Approval includes a separate password setup invitation.</p>
<p>If your request was rejected or your account was suspended, contact the platform administrator. Account recovery cannot restore restricted access.</p>
<p><a class="text-accent-600 hover:underline" href="{{ route('account.request') }}">Request a new verification email</a></p>
<p><a class="text-accent-600 hover:underline" href="{{ route('password.request') }}">Request a new password setup link</a></p>
<p><a class="text-accent-600 hover:underline" href="{{ route('login') }}">Back to sign in</a></p>
</div>
@endsection
