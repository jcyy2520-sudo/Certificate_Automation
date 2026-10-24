<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('heading') — {{ config('branding.name') }}</title>
    @include('partials.brand-meta')
    @isset($cspNonce) @include('partials.theme-init') @endisset
    @vite(['resources/css/app.css', 'resources/js/standalone.js'])
</head>
<body class="min-h-screen bg-slate-50 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
    <main class="mx-auto flex min-h-screen max-w-xl flex-col justify-center px-5 py-12">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3"><span class="text-sm font-medium text-slate-500">{{ config('branding.name') }}</span><button type="button" class="button-secondary" data-theme-toggle aria-label="Toggle theme">Change theme</button></div>
        <section class="panel p-6 sm:p-8">
            <p class="eyebrow">@yield('code')</p>
            <h1 class="mt-3 page-title">@yield('heading')</h1>
            <p class="mt-4 text-sm text-slate-600">@yield('message')</p>
            <div class="mt-6 flex flex-wrap gap-3">
                @hasSection('actions')
                    @yield('actions')
                @else
                <a href="{{ route('admin.dashboard') }}" class="button-primary">Open dashboard</a>
                <a href="{{ route('login') }}" class="button-secondary">Sign in</a>
                @endif
            </div>
        </section>
    </main>
</body>
</html>
