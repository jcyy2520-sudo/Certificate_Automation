@extends('layouts.app')
@section('title', 'Profile')
@section('content')
<x-page-header title="Profile" />
<form novalidate method="POST" action="{{ route('admin.profile.update') }}" class="panel grid max-w-xl gap-5 p-5">
@csrf @method('PUT')
<x-account-field name="name" label="Full name" :value="auth()->user()->name" autocomplete="name" />
<p class="text-sm text-slate-500">Email: {{ auth()->user()->email }}. Contact the administrator if this address needs to change.</p>
<button class="button-primary justify-self-start">Save profile</button>
</form>
@endsection
