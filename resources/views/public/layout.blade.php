<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="theme-color" content="#f5f5f4">
    <title>@yield('title', 'Form')</title>
    @vite('resources/css/app.css')
</head>
{{--
    This layout is intentionally free of navigation, branding links, and any
    route to the rest of the application. A participant who opens a share link
    can see this page and nothing else.
--}}
<body class="min-h-screen bg-stone-100 text-slate-900 antialiased">
    <main class="mx-auto w-full max-w-150 px-6 pb-24 pt-10 sm:px-8 sm:pt-14">
        @yield('content')
    </main>
</body>
</html>
