<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('title', config('app.name'))</title>
    {{-- Applied before paint so the rail never flashes at the wrong width. --}}
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-app-layout="webinar" class="bg-transparent text-slate-900 dark:text-slate-100">
<a href="#main-content" class="skip-link">Skip to content</a>
<div class="min-h-screen">
    <div class="flex min-h-screen">

        {{-- Secondary, webinar-scoped workspace sidebar --}}
        <x-webinar-nav :webinar="$webinar" />

        <div class="flex min-w-0 flex-1 flex-col">
            {{-- Unified mobile header for webinar workspace (lg:hidden) --}}
            <header class="mobile-app-header lg:hidden">
                <a href="{{ route('admin.webinars.index') }}" class="mobile-header-button text-slate-600 dark:text-slate-300" aria-label="Back to all webinars" title="All webinars">
                    <x-icon name="arrow-left" class="size-5" />
                </a>
                <div class="flex min-w-0 flex-1 items-center gap-2 px-1">
                    <span class="min-w-0 truncate text-[13px] font-semibold text-slate-900 dark:text-white">{{ $webinar->title }}</span>
                </div>
                <div class="flex items-center gap-1">
                    <button type="button" class="mobile-header-button" data-theme-toggle aria-label="Toggle theme">
                        <x-icon name="sun" class="hidden size-5 text-amber-500 dark:block" />
                        <x-icon name="moon" class="block size-5 dark:hidden" />
                    </button>
                    <button type="button" class="mobile-header-button" data-mobile-webinar-nav-toggle aria-expanded="false" aria-controls="mobile-webinar-navigation" aria-label="Open webinar navigation" title="Workspace menu">
                        <x-icon name="menu" class="size-5" />
                    </button>
                </div>
            </header>

            <div class="hidden items-center justify-end border-b border-slate-200 px-6 py-2 lg:flex">
                <button type="button" class="button-secondary" data-theme-toggle aria-label="Toggle theme"><x-icon name="sun" class="hidden size-4 dark:block" /><x-icon name="moon" class="size-4 dark:hidden" />Change theme</button>
            </div>
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
