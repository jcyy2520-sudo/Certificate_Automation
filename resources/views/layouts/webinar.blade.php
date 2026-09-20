<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('title', config('app.name'))</title>
    {{-- Applied before paint so the rail never flashes at the wrong width. --}}
    <script nonce="{{ $cspNonce }}">try{if(localStorage.getItem('rail')==='open'){document.documentElement.classList.add('rail-open')}}catch(e){}try{var t=localStorage.getItem('theme');if(t==='dark'||(!t&&window.matchMedia('(prefers-color-scheme: dark)').matches)){document.documentElement.classList.add('dark')}else{document.documentElement.classList.remove('dark')}}catch(e){}</script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="bg-transparent text-slate-900 dark:text-slate-100">
<div class="flex min-h-screen">

    {{-- Primary application rail --}}
    @include('partials.admin-rail')

    {{-- Secondary, webinar-scoped workspace sidebar --}}
    <x-webinar-nav :webinar="$webinar" />

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Compact sub-nav for narrow screens where the secondary sidebar is hidden --}}
        <div class="sticky top-0 z-10 flex items-center justify-between border-b border-slate-200 dark:border-slate-800 bg-white/95 dark:bg-slate-900/95 px-5 py-3 backdrop-blur lg:hidden">
            <div class="flex items-center gap-3 min-w-0">
                <a href="{{ route('admin.webinars.index') }}" class="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500 dark:text-slate-400">
                    <x-icon name="arrow-left" class="size-4" />Webinars
                </a>
                <span class="text-slate-300 dark:text-slate-700">/</span>
                <span class="min-w-0 truncate text-[13px] font-semibold text-slate-900 dark:text-white">{{ $webinar->title }}</span>
            </div>
            <button type="button" class="p-1.5 rounded-lg text-slate-500 hover:text-slate-900 dark:text-slate-400 dark:hover:text-white" data-theme-toggle aria-label="Toggle theme">
                <x-icon name="sun" class="size-4 hidden dark:block text-amber-400" />
                <x-icon name="moon" class="size-4 block dark:hidden" />
            </button>
        </div>

        <main class="mx-auto w-full max-w-[1440px] flex-1 px-6 py-8 lg:px-10 lg:py-10 2xl:px-14 2xl:py-12">
            @include('partials.admin-flash')

            @yield('content')
        </main>
    </div>
</div>

@include('partials.admin-ui')
@include('partials.admin-scripts')
</body>
</html>
