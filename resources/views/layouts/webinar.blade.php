<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('title', 'Webinar') — {{ config('branding.name') }}</title>
    @include('partials.brand-meta')
    {{-- Applied before paint so the rail never flashes at the wrong width. --}}
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-app-layout="webinar" class="webinar-workspace bg-slate-50 text-slate-900 dark:text-slate-100">
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

            <div class="workspace-topbar hidden lg:flex">
                <a href="{{ route('admin.webinars.index') }}" class="workspace-back"><x-icon name="arrow-left" class="size-4" />All webinars</a>
                <button type="button" class="theme-button" data-theme-toggle aria-label="Toggle theme"><x-icon name="sun" class="hidden size-4 dark:block" /><x-icon name="moon" class="size-4 dark:hidden" /><span class="sr-only">Change theme</span></button>
            </div>
            <main id="main-content" tabindex="-1" class="workspace-main">
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
