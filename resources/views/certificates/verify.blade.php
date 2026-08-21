<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Certificate verification</title>
    @vite('resources/css/app.css')
</head>
@php
    $privacyUnavailable = $certificate->privacy_erased_at !== null
        && ! config('webinar.public_verification_after_privacy_erasure', false);
    $valid = $certificate->isPubliclyValid();
    $state = match (true) {
        $privacyUnavailable => ['Certificate record unavailable', 'text-slate-700', 'This public verification record is no longer available under the organizer\'s privacy retention policy.'],
        $valid => ['Valid certificate', 'text-emerald-700', 'This certificate was issued by the organizer and has not been revoked.'],
        $certificate->revoked_at !== null => ['Certificate revoked', 'text-red-700', 'The organizer revoked this certificate on '.$certificate->revoked_at->toFormattedDateString().'. It should no longer be accepted as proof of completion.'],
        default => ['Certificate not issued', 'text-amber-700', 'This identifier exists but no certificate has been issued against it yet.'],
    };
@endphp
<body class="min-h-screen bg-slate-100 text-slate-900">
    <main class="mx-auto flex min-h-screen max-w-2xl items-center px-6 py-12">
        <section class="w-full rounded-2xl bg-white p-8 shadow-sm ring-1 ring-slate-200">
            <p class="text-sm font-semibold uppercase tracking-wider {{ $state[1] }}">{{ $state[0] }}</p>
            <h1 class="mt-3 text-3xl font-bold">{{ $privacyUnavailable ? 'Certificate verification' : $certificate->webinar->title }}</h1>
            <p class="mt-3 text-slate-600">{{ $state[2] }}</p>
            @unless($privacyUnavailable)
                <dl class="mt-8 grid gap-5 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-500">Certificate ID</dt><dd class="font-mono">{{ $certificate->verification_code }}</dd></div>
                    <div><dt class="text-sm text-slate-500">Issued</dt><dd>{{ $certificate->issued_at?->toFormattedDateString() ?? 'Pending' }}</dd></div>
                </dl>
                <p class="mt-8 text-sm text-slate-500">This public record intentionally excludes email, organization, and assessment results.</p>
            @endunless
        </section>
    </main>
</body>
</html>
