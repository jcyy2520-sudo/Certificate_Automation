<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Certificate verification</title>
    <script nonce="{{ $cspNonce }}">try{var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')}}catch(e){}</script>
    @vite('resources/css/app.css')
</head>
@php
    $privacyUnavailable = $certificate->privacy_erased_at !== null
        && ! config('webinar.public_verification_after_privacy_erasure', false);
    $valid = $certificate->isPubliclyValid();
    $state = match (true) {
        $privacyUnavailable => ['Certificate record unavailable', 'text-slate-700 dark:text-slate-300', 'This public verification record is no longer available under the organizer\'s privacy retention policy.'],
        $valid => ['Valid certificate', 'text-emerald-700 dark:text-emerald-400', 'This certificate was issued by the organizer and has not been revoked.'],
        $certificate->revoked_at !== null => ['Certificate revoked', 'text-red-700 dark:text-rose-400', 'The organizer revoked this certificate on '.$certificate->revoked_at->toFormattedDateString().'. It should no longer be accepted as proof of completion.'],
        default => ['Certificate not issued', 'text-amber-700 dark:text-amber-400', 'This identifier exists but no certificate has been issued against it yet.'],
    };
@endphp
<body class="relative min-h-screen bg-slate-100 dark:bg-slate-950 text-slate-900 dark:text-slate-100">
    <div class="absolute top-4 right-4 sm:top-6 sm:right-6">
        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-3 py-1.5 text-xs font-medium text-slate-700 dark:text-slate-300 transition hover:bg-slate-50 dark:hover:bg-slate-800" data-theme-toggle aria-label="Toggle theme">
            <x-icon name="sun" class="size-3.5 hidden dark:block text-amber-400" />
            <x-icon name="moon" class="size-3.5 block dark:hidden text-slate-600" />
            <span class="block dark:hidden">Dark</span>
            <span class="hidden dark:block">Light</span>
        </button>
    </div>

    <main class="mx-auto flex min-h-screen max-w-2xl items-center px-6 py-12">
        <section class="w-full rounded-2xl bg-white dark:bg-[#12141a] p-8 shadow-sm ring-1 ring-slate-200 dark:ring-slate-800">
            <p class="text-sm font-semibold uppercase tracking-wider {{ $state[1] }}">{{ $state[0] }}</p>
            <h1 class="mt-3 text-3xl font-bold">{{ $privacyUnavailable ? 'Certificate verification' : $certificate->webinar->title }}</h1>
            <p class="mt-3 text-slate-600 dark:text-slate-400">{{ $state[2] }}</p>
            @unless($privacyUnavailable)
                <dl class="mt-8 grid gap-5 sm:grid-cols-2">
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">Certificate ID</dt><dd class="font-mono">{{ $certificate->verification_code }}</dd></div>
                    <div><dt class="text-sm text-slate-500 dark:text-slate-400">Issued</dt><dd>{{ $certificate->issued_at?->toFormattedDateString() ?? 'Pending' }}</dd></div>
                </dl>
                <p class="mt-8 text-sm text-slate-500 dark:text-slate-400">This public record intentionally excludes email, organization, and assessment results.</p>
            @endunless
        </section>
    </main>

    <script nonce="{{ $cspNonce }}">
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('[data-theme-toggle]');
        if (btn) {
            var isDark = document.documentElement.classList.toggle('dark');
            try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch(e) {}
        }
    });
    </script>
</body>
</html>
