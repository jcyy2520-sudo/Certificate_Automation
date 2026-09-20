{{-- The primary application rail. Present on every admin surface. A webinar
     workspace renders its own secondary sidebar to the right of this one. --}}
@php
    $inWebinars = request()->routeIs('admin.webinars.*', 'admin.forms.*', 'admin.participants.*', 'admin.certification.*', 'admin.certificates.*');
    $railNav = [
        ['route' => 'admin.dashboard', 'icon' => 'home', 'label' => 'Dashboard', 'active' => request()->routeIs('admin.dashboard')],
        ['route' => 'admin.webinars.index', 'icon' => 'layers', 'label' => 'Webinars', 'active' => $inWebinars],
        ['route' => 'admin.email-logs.index', 'icon' => 'mail', 'label' => 'Email logs', 'active' => request()->routeIs('admin.email-logs.*', 'admin.webinars.email-logs.*')],
        ['route' => 'admin.two-factor.show', 'icon' => 'shield', 'label' => 'Security', 'active' => request()->routeIs('admin.two-factor.*')],
    ];
@endphp
<aside class="rail sticky top-0 z-30 flex h-screen shrink-0 flex-col overflow-hidden border-r border-slate-800/80 bg-slate-950 text-slate-300">
    <div class="flex h-[76px] shrink-0 items-center px-5">
        <span class="rail-label truncate text-[14px] font-bold tracking-tight text-white">{{ config('app.name') }}</span>
    </div>

    <nav class="mt-2 flex flex-1 flex-col gap-1 px-3.5">
        @foreach($railNav as $item)
            <a href="{{ route($item['route']) }}"
               class="rail-item {{ $item['active'] ? 'rail-item-active' : '' }}"
               @if(! $item['active']) title="{{ $item['label'] }}" @endif
               @if($item['active']) aria-current="page" @endif>
                <x-icon :name="$item['icon']" />
                <span class="rail-label">{{ $item['label'] }}</span>
            </a>
        @endforeach

        <button type="button" class="rail-item mt-auto" data-theme-toggle aria-label="Toggle theme" title="Toggle theme">
            <x-icon name="sun" class="hidden dark:block text-amber-400" />
            <x-icon name="moon" class="block dark:hidden" />
            <span class="rail-label">
                <span class="block dark:hidden">Dark mode</span>
                <span class="hidden dark:block">Light mode</span>
            </span>
        </button>

        <button type="button" class="rail-item mb-1" data-rail-toggle aria-expanded="false" aria-label="Toggle sidebar">
            <x-icon name="panel-left" />
            <span class="rail-label">Collapse</span>
        </button>
    </nav>

    <div class="shrink-0 border-t border-slate-800/80 p-3.5">
        <div class="flex items-center gap-3 px-[3px]">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-800 text-[13px] font-semibold text-slate-200">
                {{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}
            </span>
            <span class="rail-label min-w-0 text-[13px] font-medium leading-4 text-white">
                {{ Str::limit(auth()->user()->name, 18) }}
                <span class="block truncate text-[11px] font-normal text-slate-400">{{ Str::limit(auth()->user()->email, 22) }}</span>
            </span>
        </div>
        <form method="POST" action="{{ route('admin.logout') }}" class="mt-1">
            @csrf
            <button class="rail-item w-full" title="Sign out">
                <x-icon name="log-out" />
                <span class="rail-label">Sign out</span>
            </button>
        </form>
    </div>
</aside>
