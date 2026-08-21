@extends('layouts.app')
@section('title', 'Confirm password')
@section('content')

<x-page-header title="Confirm your password" :crumbs="['Dashboard' => route('admin.dashboard')]"
               subtitle="Participant exports contain personal information. Re-enter your password before downloading them." />

<form class="panel max-w-md p-5" method="POST" action="{{ route('admin.password.confirm.store') }}">
    @csrf
    <label class="field-label">Current password
        <input class="field" type="password" name="password" required autofocus autocomplete="current-password">
    </label>
    <button class="button-primary mt-5">Continue securely</button>
</form>
@endsection
