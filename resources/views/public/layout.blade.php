<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#f5f5f4">
    <title>@yield('title', 'Form')</title>
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/standalone.js'])
</head>
{{--
    This layout is intentionally free of navigation, branding links, and any
    route to the rest of the application. A participant who opens a share link
    can see this page and nothing else.
--}}
<body class="min-h-screen bg-stone-100 dark:bg-slate-950 text-slate-900 dark:text-slate-100 antialiased">
    <main class="mx-auto w-full max-w-150 px-4 pb-20 pt-8 sm:px-8 sm:pt-14">
        <div class="mb-4 flex justify-end">
            <button type="button" class="inline-flex items-center gap-1.5 rounded-lg border border-slate-200 dark:border-slate-800 bg-white dark:bg-slate-900 px-2.5 py-1 text-xs font-medium text-slate-600 dark:text-slate-300 transition hover:bg-slate-50 dark:hover:bg-slate-800" data-theme-toggle aria-label="Toggle theme">
                <x-icon name="sun" class="size-3.5 hidden dark:block text-amber-400" />
                <x-icon name="moon" class="size-3.5 block dark:hidden text-slate-600" />
                <span class="block dark:hidden">Dark</span>
                <span class="hidden dark:block">Light</span>
            </button>
        </div>
        @yield('content')
    </main>


</body>
</html>
