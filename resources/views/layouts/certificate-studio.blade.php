<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Certificate Studio') — {{ config('branding.name') }}</title>
    @include('partials.brand-meta')
    <meta name="robots" content="noindex, nofollow, noarchive">
    @include('partials.theme-init')
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body data-app-layout="certificate-studio" class="overflow-hidden bg-slate-950 text-slate-900">
    @yield('content')
    @include('partials.admin-ui')
    @include('partials.admin-scripts')
</body>
</html>
