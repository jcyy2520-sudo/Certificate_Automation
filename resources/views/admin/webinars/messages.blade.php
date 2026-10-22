@extends('layouts.webinar')
@section('title', 'Send a message')
@section('content')
<x-page-header title="Send a message" subtitle="Email eligible participants without generating or attaching a certificate." />
@if(config('webinar.email.provider') === 'log')
<p class="panel mb-5 p-4 text-sm" role="status">Email is in log mode. Queued messages will be captured by the system; they will not reach recipients until a delivery provider is configured.</p>
@endif
<form novalidate method="POST" action="{{ route('admin.webinars.messages.store', $webinar) }}" data-message-composer data-confirm="Queue this message for the selected recipients?" data-confirm-title="Send message" data-confirm-action="Queue message" data-confirm-tone="normal" class="grid gap-5">
    @csrf
    <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) Str::uuid()) }}">
    <section class="panel p-5">
        <h2 class="section-title">Eligible recipients</h2>
        <p class="mt-2 text-sm text-slate-500">Select up to {{ config('webinar.message_recipient_limit') }} recipients. Selection applies to this page; eligibility is checked again before delivery.</p>
        <x-field-error :error="$errors->first('participants') ?: $errors->first('participants.*')" />
        <label class="mt-4 flex items-center gap-3 text-sm"><input type="checkbox" data-message-select-page> Select this page</label>
        <div class="mt-3 divide-y divide-slate-100">
        @forelse($participants as $participant)
            <label class="flex items-center gap-3 py-3 text-sm"><input type="checkbox" name="participants[]" value="{{ $participant->public_id }}" data-message-recipient @checked(in_array($participant->public_id, old('participants', []), true))><span>{{ $participant->full_name }} <span class="text-slate-500">{{ \App\Http\Controllers\Admin\EmailDeliveryController::maskRecipient($participant->email) }}</span></span></label>
        @empty
            <p class="py-6 text-sm text-slate-500">No eligible recipients with email addresses. Review the webinar's form responses and eligibility requirements first.</p>
        @endforelse
        </div>
    </section>
    <section class="panel grid gap-4 p-5">
        <x-account-field name="subject" label="Message subject" :value="old('subject')" maxlength="180" />
        <label for="message-body" class="field-label">Message body</label>
        <textarea id="message-body" name="body" rows="10" maxlength="10000" class="field h-auto resize-none" required aria-describedby="message-body-help message-body-error" @if($errors->has('body')) aria-invalid="true" @endif>{{ old('body') }}</textarea>
        <p id="message-body-help" class="text-sm text-slate-500">Plain text only. No certificate or other attachment will be included.</p>
        <div id="message-body-error"><x-field-error :error="$errors->first('body')" /></div>
        <p class="text-sm" aria-live="polite"><strong data-message-count>0</strong> recipients selected</p>
        <button class="button-primary justify-self-start" data-message-submit>Queue message</button>
    </section>
</form>
<div class="mt-6">{{ $participants->links() }}</div>
@endsection
