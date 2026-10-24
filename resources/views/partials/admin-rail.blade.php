{{-- The primary application rail. Present on every admin surface. A webinar
     workspace renders its own secondary sidebar to the right of this one. --}}
<aside data-persist-key="primary-rail" class="rail sticky top-0 z-30 hidden h-screen shrink-0 flex-col overflow-hidden border-r border-slate-200 bg-white text-slate-600 dark:border-slate-800 dark:bg-slate-950 dark:text-slate-300 lg:flex">
    <div class="flex h-[76px] shrink-0 items-center px-5">
        <a href="{{ route('admin.dashboard') }}" aria-label="{{ config('branding.name') }} dashboard"><x-brand navigation label-class="rail-label" /></a>
    </div>

    <nav class="mt-2 flex min-h-0 flex-1 flex-col gap-1 overflow-y-auto px-3.5 pb-2" aria-label="Primary navigation">
        @include('partials.admin-primary-links')

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

    <div class="shrink-0 border-t border-slate-200 p-3.5 dark:border-slate-800">
        <div class="flex items-center gap-3 px-[3px]">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-100 text-[13px] font-semibold text-slate-600 dark:bg-slate-800 dark:text-slate-200">
                {{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}
            </span>
            <span class="rail-label min-w-0 text-[13px] font-medium leading-4 text-slate-900 dark:text-white">
                {{ Str::limit(auth()->user()->name, 18) }}
                <span class="block truncate text-[11px] font-normal text-slate-500 dark:text-slate-400">{{ Str::limit(auth()->user()->email, 22) }}</span>
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
