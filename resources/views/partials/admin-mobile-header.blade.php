<header class="mobile-app-header lg:hidden">
    <button type="button" class="mobile-header-button" data-mobile-rail-toggle aria-expanded="false" aria-controls="mobile-primary-navigation" aria-label="Open navigation">
        <x-icon name="menu" class="size-5" />
    </button>
    <a href="{{ route('admin.dashboard') }}" class="min-w-0 truncate text-[14px] font-bold tracking-tight text-slate-900 dark:text-white">{{ config('app.name') }}</a>
    <button type="button" class="mobile-header-button" data-theme-toggle aria-label="Toggle theme">
        <x-icon name="sun" class="hidden size-5 text-amber-500 dark:block" />
        <x-icon name="moon" class="block size-5 dark:hidden" />
    </button>
</header>

<div class="mobile-nav-overlay lg:hidden" data-mobile-rail-overlay aria-hidden="true"></div>
<aside id="mobile-primary-navigation" class="mobile-nav-drawer lg:hidden" data-mobile-rail-drawer aria-label="Primary navigation" aria-hidden="true" inert>
    <div class="flex h-[68px] shrink-0 items-center justify-between border-b border-slate-800 px-5">
        <span class="text-[14px] font-bold tracking-tight text-white">{{ config('app.name') }}</span>
        <button type="button" class="mobile-header-button text-slate-300 hover:bg-slate-800 hover:text-white" data-mobile-rail-close aria-label="Close navigation">
            <x-icon name="x" class="size-5" />
        </button>
    </div>

    <nav class="flex flex-1 flex-col gap-1 overflow-y-auto px-3 py-4">
        @include('partials.admin-primary-links', ['mobile' => true])
    </nav>

    <div class="shrink-0 border-t border-slate-800 p-3">
        <button type="button" class="mobile-rail-item w-full" data-theme-toggle>
            <x-icon name="sun" class="hidden text-amber-400 dark:block" />
            <x-icon name="moon" class="block dark:hidden" />
            <span><span class="block dark:hidden">Dark mode</span><span class="hidden dark:block">Light mode</span></span>
        </button>
        <div class="mt-3 flex items-center gap-3 px-3">
            <span class="grid size-9 shrink-0 place-items-center rounded-full bg-slate-800 text-[13px] font-semibold text-slate-200">{{ Str::upper(Str::substr(auth()->user()->name, 0, 1)) }}</span>
            <span class="min-w-0 text-[13px] font-medium leading-4 text-white">{{ Str::limit(auth()->user()->name, 18) }}<span class="block truncate text-[11px] font-normal text-slate-400">{{ Str::limit(auth()->user()->email, 22) }}</span></span>
        </div>
        <form method="POST" action="{{ route('admin.logout') }}" class="mt-2">
            @csrf
            <button class="mobile-rail-item w-full" title="Sign out"><x-icon name="log-out" /><span>Sign out</span></button>
        </form>
    </div>
</aside>
