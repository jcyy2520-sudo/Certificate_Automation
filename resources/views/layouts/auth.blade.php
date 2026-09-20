<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#f5f5f4">
    <script nonce="{{ $cspNonce }}">try{var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')}}catch(e){}</script>
    @vite('resources/css/app.css')
</head>
{{--
    Sign-in chrome only. No navigation, no marketing, and no links into the
    application: this is the first thing a visitor sees at the root URL.
--}}
<body class="relative min-h-screen bg-stone-100 dark:bg-slate-950 text-slate-900 dark:text-slate-100 antialiased">
    <div class="absolute top-4 right-4 sm:top-6 sm:right-6">
        <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-3 py-1.5 text-xs font-medium text-slate-700 dark:text-slate-300 transition hover:bg-slate-50 dark:hover:bg-slate-800" data-theme-toggle aria-label="Toggle theme">
            <x-icon name="sun" class="size-3.5 hidden dark:block text-amber-400" />
            <x-icon name="moon" class="size-3.5 block dark:hidden text-slate-600" />
            <span class="block dark:hidden">Dark</span>
            <span class="hidden dark:block">Light</span>
        </button>
    </div>

    <main class="mx-auto flex min-h-screen w-full max-w-105 flex-col justify-center px-6 py-12">
        <div class="mb-8 flex flex-col items-center text-center">
            <h1 class="text-[24px] font-bold leading-8 tracking-tight">@yield('heading', 'Sign in')</h1>
            <p class="mt-2 text-[13px] leading-5 text-slate-500 dark:text-slate-400">@yield('subheading')</p>
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
