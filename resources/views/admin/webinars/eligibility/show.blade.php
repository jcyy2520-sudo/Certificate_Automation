@extends('layouts.webinar')
@section('title', 'Eligibility record')
@section('content')
<x-page-header :title="$participant->full_name ?: 'Participant record'" subtitle="Inspect current evidence and resolve discrepancies without replacing original records." />
<div class="mb-5 flex flex-wrap gap-3"><a class="button-secondary" href="{{ route('admin.webinars.eligibility.index', $webinar) }}">All eligibility records</a><a class="button-secondary" href="{{ route('admin.participants.show', [$webinar, $participant]) }}">Participant details</a></div>
<div class="grid gap-6 xl:grid-cols-2">
    <section class="panel overflow-hidden">
        <div class="border-b border-slate-100 p-5"><h2 class="section-title">{{ $summary['label'] }}</h2><p class="mt-2 text-sm text-slate-600">{{ $summary['explanation'] }}</p>@if($summary['final_reason'])<p class="mt-3 whitespace-pre-line text-sm">{{ $summary['final_reason'] }}</p>@endif</div>
        @include('partials.eligibility-checklist')
    </section>
    <div class="grid content-start gap-5">
        <section class="panel p-5"><h2 class="section-title">Certificate status</h2><p class="mt-3 text-sm">{{ $summary['certificate_status'] }} · Email: {{ $summary['delivery_status'] }}</p><p class="mt-2 text-xs text-slate-500">Eligibility does not mean a certificate was generated or delivered.</p>@if(app(\App\Policies\WebinarPolicy::class)->permission(auth()->user(), $webinar, 'manage_certificates'))<a class="mt-3 inline-block text-sm text-accent-700 underline" href="{{ route('admin.certificates.studio', [$webinar, 'manual' => $participant->public_id]) }}">Open Certificate Studio</a>@endif</section>
        @if($issues->isNotEmpty())<section class="panel p-5"><h2 class="section-title">Held submission records</h2><p class="mt-2 text-xs text-slate-500">These records match the participant’s email and still require verification. Other unmatched addresses are never automatically attributed by name.</p>@foreach($issues as $issue)<p class="mt-3 text-sm">Issue #{{ $issue->id }} · {{ $issue->import?->form?->type }} · {{ str_replace('_', ' ', $issue->issue_type) }}</p>@endforeach<a class="mt-3 inline-block text-sm text-accent-700 underline" href="{{ route('admin.webinars.imports.index', $webinar) }}">Open import review</a></section>@endif
        <section class="panel p-5"><h2 class="section-title">Record review requests</h2>
        @forelse($reviews as $review)
            <article class="mt-4 border-t border-slate-100 pt-4"><p class="text-sm font-semibold">{{ ucfirst($review->status) }} · {{ $review->created_at->timezone($participant->webinar->timezone ?: config('app.timezone'))->format('M j, Y g:i A') }}</p><p class="mt-2 whitespace-pre-line break-words text-sm text-slate-600">{{ $review->explanation }}</p>
                @if($review->evidence_status === 'stored')<a class="mt-2 inline-block text-sm text-accent-700 underline" href="{{ route('admin.webinars.eligibility.evidence', [$webinar, $participant, $review]) }}" download>Download private evidence</a>@elseif($review->evidence_status)<p class="mt-2 text-xs text-amber-700">Evidence upload: {{ $review->evidence_status }}. Do not treat this as received evidence.</p>@endif
                @if($review->status === 'pending')
                    <form method="POST" action="{{ route('admin.webinars.eligibility.decide', [$webinar, $participant, $review]) }}" class="mt-4 grid gap-3" data-eligibility-review-decision data-confirm="Save this review decision? The reason will be visible to this participant. Approval requires a verified record correction." data-confirm-action="Save decision">
                        @csrf
                        <label class="field-label" for="review-decision-{{ $review->id }}">Decision</label><select class="field" name="decision" id="review-decision-{{ $review->id }}"><option value="rejected">Reject correction request</option><option value="approved">Approve verified correction</option></select>
                        <div data-eligibility-correction><label class="field-label" for="review-correction-{{ $review->id }}">For approval: verified correction</label><select class="field mt-1" name="correction_type" id="review-correction-{{ $review->id }}"><option value="records_updated">Records already corrected through Imports or Participants</option><option value="existing_import">Apply a validated held import for this email</option></select></div>
                        <label class="field-label" data-eligibility-import>Held import issue number (when applying an import)<input class="field mt-1" name="import_issue_id" type="number" min="1"></label>
                        <label class="field-label">Reason visible to participant<textarea class="field mt-1 h-auto" name="reason" rows="4" minlength="10" maxlength="1500" required>{{ old('reason') }}</textarea></label>
                        <p class="text-xs text-slate-500">Do not include another participant’s information. Approval cannot create completion evidence. Existing imported corrections preserve superseded responses and prevent changes under an active certificate.</p>
                        <button class="button-secondary justify-self-start">Save decision</button>
                    </form>
                @else<p class="mt-2 whitespace-pre-line text-sm">{{ $review->decision_reason }}</p><p class="mt-1 text-xs text-slate-500">Decided by User #{{ $review->decided_by }} · {{ $review->decided_at?->timezone($participant->webinar->timezone ?: config('app.timezone'))->format('M j, Y g:i A') }}</p>@endif
            </article>
        @empty<p class="mt-3 text-sm text-slate-500">No record reviews requested.</p>@endforelse
        <x-field-error :error="$errors->first('review') ?: $errors->first('correction_type') ?: $errors->first('import_issue_id')" />
        </section>
        @if($summary['status'] === 'incomplete')
        <details class="panel p-5"><summary class="cursor-pointer text-sm font-semibold">Finalize a verified ineligibility decision</summary><p class="mt-3 text-xs leading-5 text-slate-500">Use only after collection is closed, all relevant uploads are committed, held rows are resolved and record reviews are complete. Missing or unmatched records are never finalized automatically. This records a communication decision and preserves the eligibility rules and original evidence.</p>
            <form method="POST" action="{{ route('admin.webinars.eligibility.finalize', [$webinar, $participant]) }}" class="mt-4 grid gap-3" data-confirm="Finalize Not Eligible against the current verified records? No email or certificate will be sent." data-confirm-action="Finalize decision">@csrf
                <label class="field-label">Explanation visible to participant<textarea class="field mt-1 h-auto" name="reason" rows="4" minlength="10" maxlength="1500" required>{{ old('reason') }}</textarea></label>
                <label class="flex items-start gap-2 text-sm"><input type="checkbox" name="verification_complete" value="1" required> I verified all relevant uploads and matching, checked discrepancies and confirmed the published requirements are not satisfied.</label>
                <x-field-error :error="$errors->first('reason') ?: $errors->first('verification_complete')" /><button class="button-secondary justify-self-start">Finalize decision</button>
            </form>
        </details>
        @endif
        <section class="panel p-5"><h2 class="section-title">Notification history</h2>@forelse($history as $delivery)<p class="mt-3 text-sm">{{ $delivery->created_at->timezone($participant->webinar->timezone ?: config('app.timezone'))->format('M j, Y g:i A') }} · {{ $delivery->statusLabel() }}</p>@empty<p class="mt-3 text-sm text-slate-500">No eligibility messages recorded.</p>@endforelse
            @if(app(\App\Policies\WebinarPolicy::class)->permission(auth()->user(), $webinar, 'send_emails') && ! app(\App\Services\EligibilityService::class)->canPrepareCertificate($participant))
            <details class="mt-4 rounded border border-slate-200 p-4" data-noneligible-message-preview><summary class="cursor-pointer text-sm font-semibold">Preview this participant's message</summary><div class="mt-4 space-y-3 text-sm leading-6">@include('emails.partials.eligibility-message')</div></details>
            <form method="POST" action="{{ route('admin.webinars.eligibility.notify', $webinar) }}" class="mt-4 grid gap-3" @if(app(\App\Services\LocalDelivery::class)->enabled()) data-immediate-send @endif data-confirm="Send the unmet-requirements message to this non-eligible participant? No certificate is attached. Previously notified recipients require the intentional resend option." data-confirm-action="Send message">@csrf
                <input type="hidden" name="submission_key" value="{{ (string) Str::uuid() }}"><input type="hidden" name="confirmed" value="1"><input type="hidden" name="participants[]" value="{{ $participant->public_id }}">
                <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="resend" value="1"> Intentionally resend</label><label class="flex items-start gap-2 text-xs text-slate-500"><input type="checkbox" name="provider_reconciled" value="1"> I reconciled any uncertain previous attempt with the provider.</label><button class="button-secondary justify-self-start">Send non-eligible message (1 recipient)</button>
            </form>
            <a class="mt-3 inline-block text-sm text-accent-700 underline" href="{{ route('admin.webinars.email-logs.index', $webinar) }}">Open email logs</a>
            @endif
            @if(auth()->user()->isAdministrator())<a class="mt-3 block text-sm text-accent-700 underline" href="{{ route('admin.audit.index', ['webinar' => $webinar->id, 'action' => 'eligibility.']) }}">View eligibility audit trail</a>@endif
        </section>
        <form method="POST" action="{{ route('admin.webinars.eligibility.revoke', [$webinar, $participant]) }}" class="panel p-5" data-confirm="Revoke this participant’s status links and active status sessions? Their original records and certificates will remain." data-confirm-action="Revoke links">@csrf<button class="button-secondary">Revoke personal status access</button></form>
    </div>
</div>
@endsection
