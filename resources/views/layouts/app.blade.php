<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('title', 'Dashboard') — {{ config('branding.name') }}</title>
    @include('partials.brand-meta')
    {{-- Applied before paint so the rail never flashes at the wrong width. --}}
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-app-layout="app" class="bg-slate-50 text-slate-900 dark:text-slate-100">
<a href="#main-content" class="skip-link">Skip to content</a>
<div class="min-h-screen">
    @include('partials.admin-mobile-header')

    <div class="flex min-h-screen">

        @include('partials.admin-rail')

        <div class="flex min-w-0 flex-1 flex-col">
            <main id="main-content" tabindex="-1" class="mx-auto w-full max-w-[1440px] flex-1 px-4 py-6 sm:px-6 sm:py-8 lg:px-10 lg:py-10 2xl:px-14 2xl:py-12">
                @include('partials.admin-flash')

                @yield('content')
            </main>
        </div>
    </div>
</div>

@include('partials.admin-ui')
@include('partials.admin-scripts')
@include('partials.account-controls')
</body>
</html>
