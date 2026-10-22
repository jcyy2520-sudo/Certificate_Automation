@extends('public.layout')
@section('title', 'Participant status')
@section('content')
<header class="text-center">
    <p class="text-lg text-slate-800">{{ $form->webinar->title }}</p>
    <h1 class="mt-2 text-2xl font-bold text-slate-900">Participant status</h1>
    <p class="mt-3 text-sm text-slate-600">{{ $participant->full_name }} · Your private eligibility and certificate record.</p>
</header>
@if(session('success'))<p role="status" class="mt-6 rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">{{ session('success') }}</p>@endif
@if($errors->any())<div role="alert" class="mt-6 rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-900">{{ $errors->first() }}</div>@endif
<section class="panel mt-8 overflow-hidden">
    <div class="border-b border-slate-100 p-5">
        <h2 class="text-lg font-semibold text-slate-900">{{ $summary['label'] }}</h2>
        <p class="mt-2 text-sm leading-6 text-slate-600">{{ $summary['explanation'] }}</p>
        @if($summary['final_reason'])<p class="mt-3 whitespace-pre-line text-sm text-slate-700">{{ $summary['final_reason'] }}</p>@endif
    </div>
    @include('partials.eligibility-checklist')
</section>
<section class="panel mt-6 p-5">
    <h2 class="section-title">Certificate</h2>
    <dl class="mt-3 grid gap-2 text-sm"><div><dt class="inline text-slate-500">Processing: </dt><dd class="inline">{{ $summary['certificate_status'] }}</dd></div><div><dt class="inline text-slate-500">Email: </dt><dd class="inline">{{ $summary['delivery_status'] }}</dd></div></dl>
    <p class="mt-3 text-xs leading-5 text-slate-500">Eligibility, generation and email delivery are separate steps. Provider acceptance does not guarantee inbox delivery.</p>
    @if($certificate)
        <a class="button-primary mt-4" href="{{ route('forms.public.status.certificate.download', [$form->public_token, $certificate->public_id]) }}">Download certificate</a>
    @else
        <p class="mt-3 text-sm text-slate-600">No valid certificate is available yet.</p>
    @endif
</section>
<section class="panel mt-6 p-5">
    <h2 class="section-title">Record reviews</h2>
    <p class="mt-2 text-sm leading-6 text-slate-600">If a record is missing or incorrect, ask the organizer to check it. Use record references and dates when possible. Do not include passwords or other participants’ personal information.</p>
    @foreach($reviews as $review)
        <article class="mt-4 border-t border-slate-100 pt-4">
            <p class="text-sm font-medium">{{ ucfirst($review->status) }} · {{ $review->created_at->timezone($participant->webinar->timezone ?: config('app.timezone'))->format('M j, Y g:i A') }}</p>
            <p class="mt-2 whitespace-pre-line break-words text-sm text-slate-600">{{ $review->explanation }}</p>
            @if($review->evidence_status === 'stored')<a class="mt-2 inline-block text-sm underline" href="{{ route('forms.public.status.review.evidence', [$form->public_token, $review->public_id]) }}" download>Download your evidence</a>@elseif($review->evidence_status)<p class="mt-2 text-xs text-amber-700">Evidence upload: {{ $review->evidence_status }}. The organizer has not received a confirmed supporting file.</p>@endif
            @if($review->decision_reason)<p class="mt-2 whitespace-pre-line text-sm text-slate-700">Organizer’s decision: {{ $review->decision_reason }}</p><p class="mt-1 text-xs text-slate-500">A review decision does not itself grant eligibility. The checklist shows the current verified records.</p>@endif
        </article>
    @endforeach
    @if($summary['pending_review'])
        <p role="status" class="mt-4 text-sm font-medium text-slate-700">Your review request is pending. The outcome will appear here.</p>
    @else
        <details class="mt-4" @if($errors->any()) open @endif>
            <summary class="cursor-pointer text-sm font-semibold text-accent-700">Request Record Review</summary>
            <form method="POST" enctype="multipart/form-data" action="{{ route('forms.public.status.review.store', $form->public_token) }}" class="mt-4 grid gap-3">
                @csrf
                <input type="hidden" name="submission_key" value="{{ old('submission_key', (string) Str::uuid()) }}">
                <label for="review-explanation" class="field-label">What should the organizer check?</label>
                <textarea id="review-explanation" class="field h-auto" name="explanation" rows="5" minlength="10" maxlength="2000" required>{{ old('explanation') }}</textarea>
                <label for="review-evidence" class="field-label">Supporting evidence (optional)</label>
                <input id="review-evidence" name="evidence" type="file" accept=".pdf,.png,.jpg,.jpeg" aria-describedby="evidence-help">
                <p id="evidence-help" class="text-xs text-slate-500">PDF, PNG or JPEG up to 2 MB. Evidence stays private and is reviewed by an authorized organizer.</p>
                <button class="button-primary justify-self-start">Submit review request</button>
            </form>
        </details>
    @endif
</section>
<p class="mt-6 text-center text-xs leading-5 text-slate-500">This access expires automatically. If your link expires, request a fresh one using your webinar form’s participant status link.</p>
@endsection
