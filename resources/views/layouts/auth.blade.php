<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#f5f5f4">
    <title>@yield('title', 'Sign in')</title>
    @vite('resources/css/app.css')
</head>
{{--
    Sign-in chrome only. No navigation, no marketing, and no links into the
    application: this is the first thing a visitor sees at the root URL.
--}}
<body class="min-h-screen bg-stone-100 text-slate-900 antialiased">
    <main class="mx-auto flex min-h-screen w-full max-w-105 flex-col justify-center px-6 py-12">
        <div class="mb-8 flex flex-col items-center text-center">
            <span class="grid size-12 place-items-center rounded-xl bg-accent-600 text-lg font-bold text-white shadow-sm">W</span>
            <h1 class="mt-5 text-[22px] font-bold leading-8">@yield('heading', 'Sign in')</h1>
            <p class="mt-2 text-[13px] leading-5 text-slate-500">@yield('subheading')</p>
        </div>

        <div class="panel p-7 sm:p-8">
            @if(session('success'))
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-[13px] font-medium text-emerald-900">{{ session('success') }}</div>
            @endif
            @if(session('error'))
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] font-medium text-red-900">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-[13px] text-red-900">
                    <ul class="space-y-1">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
                </div>
            @endif

            @yield('content')
        </div>

        @hasSection('footnote')
            <p class="mt-6 text-center text-[12px] leading-5 text-slate-400">@yield('footnote')</p>
        @endif
    </main>
</body>
</html>
