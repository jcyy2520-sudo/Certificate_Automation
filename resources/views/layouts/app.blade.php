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

    @include('partials.admin-rail')

    <div class="flex min-w-0 flex-1 flex-col">
        <main class="mx-auto w-full max-w-[1180px] flex-1 px-6 py-8 lg:px-10 lg:py-10">
            @include('partials.admin-flash')

            @yield('content')
        </main>
    </div>
</div>

@include('partials.admin-scripts')
</body>
</html>
