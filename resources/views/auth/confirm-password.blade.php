@extends('layouts.app')
@section('title', 'Confirm password')
@section('content')

<x-page-header title="Confirm your password" :crumbs="['Dashboard' => route('admin.dashboard')]"
               :subtitle="$pendingAction
                   ? 'Confirm your password to '.$pendingAction['label'].'.'
                   : 'Re-enter your password to continue.'" />

<div class="max-w-md">
    @if($pendingAction)
        <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-[13px] leading-5 text-amber-900">
            <p class="font-semibold">Your password is required to {{ $pendingAction['label'] }}.</p>
            <p class="mt-1">Confirm it here and the action continues straight away — you do not have to fill the form in again.</p>
        </div>
    @endif

    <form class="panel p-5" method="POST" action="{{ route('admin.password.confirm.store') }}">
        @csrf
        <label class="field-label">Current password
            <input class="field" type="password" name="password" required autofocus autocomplete="current-password">
        </label>
        <button class="button-primary mt-5">{{ $pendingAction ? 'Confirm and continue' : 'Continue securely' }}</button>
    </form>
</div>
@endsection
