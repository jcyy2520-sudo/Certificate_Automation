<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-slate-950 text-white">
    <main class="mx-auto max-w-6xl px-6 py-20 lg:py-32">
        <p class="text-sm font-semibold uppercase tracking-[0.2em] text-cyan-300">Laravel 12 foundation</p>
        <h1 class="mt-5 max-w-4xl text-5xl font-bold tracking-tight sm:text-7xl">Events, assessments, eligibility, and certificates in one configurable platform.</h1>
        <p class="mt-7 max-w-2xl text-lg leading-8 text-slate-300">The application foundation is installed and ready for the admin and participant experience modules.</p>
        <div class="mt-12 grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            @foreach (['Dynamic forms', 'Assessments', 'Certificate batches', 'Privacy controls'] as $module)
                <div class="rounded-xl border border-slate-800 bg-slate-900 p-5 text-sm font-medium text-slate-200">{{ $module }}</div>
            @endforeach
        </div>
    </main>
</body>
</html>
