@extends('layouts.webinar')
@section('title', 'Participant Eligibility')
@section('content')
<x-page-header title="Participant Eligibility" subtitle="Review verified records, unresolved requirements and certificate delivery separately. Messages are sent only when you select recipients and confirm sending." />
<form method="GET" action="{{ route('admin.eligibility.select') }}" class="mb-5 flex flex-wrap items-end gap-3"><label class="field-label">Webinar<select class="field mt-1" name="webinar">@foreach($webinars as $event)<option value="{{ $event->id }}" @selected($event->id === $webinar->id)>{{ $event->title }}</option>@endforeach</select></label><button class="button-secondary">Open webinar</button></form>
<form method="POST" action="{{ route('admin.webinars.eligibility.filter', $webinar) }}" class="panel mb-5 grid gap-3 p-4 sm:grid-cols-3">
    @csrf
    <label class="field-label">Name or email<input class="field mt-1" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="180"></label>
    <label class="field-label">Eligibility status<select class="field mt-1" name="status"><option value="">All participants</option>@foreach(['eligible' => 'Eligible', 'incomplete' => 'Incomplete / Under Review', 'not_eligible' => 'Not Eligible (finalized)', 'review' => 'Pending record review'] as $value => $label)<option value="{{ $value }}" @selected(($filters['status'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
    <button class="button-secondary self-end justify-self-start">Apply filters</button>
</form>
@php($canNotify = app(\App\Policies\WebinarPolicy::class)->permission(auth()->user(), $webinar, 'send_emails'))
@if((config('webinar.email.certificate_provider') ?? config('webinar.email.provider')) === 'log')<p role="status" class="panel mb-5 p-4 text-sm">Email is in log mode. Notifications are captured locally and will not reach recipients.</p>@endif
<form method="POST" action="{{ route('admin.webinars.eligibility.notify', $webinar) }}" @if($canNotify) @if(app(\App\Services\LocalDelivery::class)->enabled()) data-immediate-send @endif data-message-composer data-confirm="Send unmet-requirements messages to the selected non-eligible participants? No certificates will be sent." data-confirm-title="Non-eligible messages only" data-confirm-action="Send messages" @endif>
    @csrf
    <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) Str::uuid()) }}">
    <input type="hidden" name="confirmed" value="1">
    <section class="panel overflow-x-auto">
        <table class="account-management-table w-full text-left text-sm">
            <thead class="bg-slate-50 text-xs uppercase text-slate-500"><tr><th class="px-4 py-3">@if($canNotify)<input type="checkbox" data-message-select-page aria-label="Select this page">@endif</th><th class="px-4 py-3">Participant</th><th class="px-4 py-3">Eligibility</th><th class="px-4 py-3">Certificate / email</th><th class="px-4 py-3">Action</th></tr></thead>
            <tbody class="divide-y divide-slate-100">
            @forelse($participants as $participant)
                <tr><td class="px-4 py-4">@if($canNotify && ! app(\App\Services\EligibilityService::class)->canPrepareCertificate($participant))<input type="checkbox" name="participants[]" value="{{ $participant->public_id }}" data-message-recipient aria-label="Select {{ $participant->full_name }}">@endif</td>
                    <td class="px-4 py-4" data-label="Participant"><p class="font-medium">{{ $participant->full_name }}</p><p class="mt-1 text-xs text-slate-500">{{ \App\Http\Controllers\Admin\EmailDeliveryController::maskRecipient($participant->email) }}</p></td>
                    <td class="px-4 py-4" data-label="Eligibility"><span class="badge {{ $participant->feedback['status'] === 'eligible' ? 'badge-green' : 'badge-slate' }}">{{ $participant->feedback['label'] }}</span>@if($participant->feedback['pending_review'])<p class="mt-2 text-xs text-amber-700">Record review pending</p>@endif</td>
                    <td class="px-4 py-4" data-label="Certificate / email">{{ $participant->feedback['certificate_status'] }}<p class="mt-1 text-xs text-slate-500">{{ $participant->feedback['delivery_status'] }}</p></td>
                    <td class="px-4 py-4"><a class="text-accent-700 underline" href="{{ route('admin.webinars.eligibility.show', [$webinar, $participant]) }}">Review records</a></td>
                </tr>
            @empty
                <tr><td class="px-4 py-8 text-slate-500" colspan="5">No participants match these filters.</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
    @if($canNotify)
    <section class="panel mt-5 grid gap-3 p-5">
        <p class="text-sm"><strong data-message-count>0</strong> recipients selected</p>
        <h2 class="section-title">Message non-eligible participants only</h2>
        <p class="text-xs leading-5 text-slate-500">Up to 50 recipients per batch. Each email explains that person's unmet requirements and recorded scores, with no links or attachments. Eligible and manual certificate recipients cannot be selected. This action sends no certificates. Existing pending notifications are skipped.</p>
        <a class="text-sm text-accent-700 underline" href="{{ route('admin.webinars.messages.index', $webinar) }}">Separate message composer for eligible participants</a>
        <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="resend" value="1"> Intentionally resend to previously notified participants</label>
        <label class="flex items-start gap-2 text-xs text-slate-500"><input type="checkbox" name="provider_reconciled" value="1"> For uncertain previous attempts, I checked the provider message history and confirmed a resend is appropriate.</label>
        <x-field-error :error="$errors->first('participants') ?: $errors->first('provider_reconciled')" />
        <button class="button-primary justify-self-start" data-message-submit>Send non-eligible messages</button>
        <a class="justify-self-start text-sm text-accent-700 underline" href="{{ route('admin.webinars.email-logs.index', $webinar) }}">View notification and delivery logs</a>
    </section>
    @endif
</form>
<div class="mt-5">{{ $participants->links() }}</div>
<p class="mt-4 text-xs text-slate-500">Finalized decisions automatically return to review if evidence or rules change. No action on this page grants a certificate exception.</p>
@endsection
