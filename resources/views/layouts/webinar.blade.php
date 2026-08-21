<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('title', config('app.name'))</title>
    {{-- Applied before paint so the rail never flashes at the wrong width. --}}
    <script nonce="{{ $cspNonce }}">try{if(localStorage.getItem('rail')==='open'){document.documentElement.classList.add('rail-open')}}catch(e){}</script>
    @vite('resources/css/app.css')
</head>
<body class="bg-neutral-50 text-slate-900">
<div class="flex min-h-screen">

    {{-- Primary application rail --}}
    @include('partials.admin-rail')

    {{-- Secondary, webinar-scoped workspace sidebar --}}
    <x-webinar-nav :webinar="$webinar" />

    <div class="flex min-w-0 flex-1 flex-col">
        {{-- Compact sub-nav for narrow screens where the secondary sidebar is hidden --}}
        <div class="sticky top-0 z-10 flex items-center gap-3 border-b border-slate-200 bg-white/95 px-5 py-3 backdrop-blur lg:hidden">
            <a href="{{ route('admin.webinars.index') }}" class="inline-flex items-center gap-1.5 text-[13px] font-medium text-slate-500">
                <x-icon name="arrow-left" class="size-4" />Webinars
            </a>
            <span class="text-slate-300">/</span>
            <span class="min-w-0 truncate text-[13px] font-semibold text-slate-900">{{ $webinar->title }}</span>
        </div>

        <main class="mx-auto w-full max-w-[1180px] flex-1 px-6 py-8 lg:px-10 lg:py-10">
            @include('partials.admin-flash')

            @yield('content')
        </main>
    </div>
</div>

@include('partials.admin-scripts')
</body>
</html>
