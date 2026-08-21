@props([
    'title',
    'crumbs' => [],   // ['Label' => url, ...] — ancestors only; the page itself is the <h1>
    'subtitle' => null,
])
<header class="mb-7">
    @if($crumbs)
        {{-- Ancestors only. Repeating the page title here would just print it twice. --}}
        <nav class="mb-2.5 flex items-center gap-1.5 text-[13px] text-slate-500" aria-label="Breadcrumb">
            @foreach($crumbs as $label => $url)
                @if(! $loop->first)<span class="text-slate-300" aria-hidden="true">/</span>@endif
                <a href="{{ $url }}" class="max-w-[22ch] truncate transition hover:text-slate-900">{{ $label }}</a>
            @endforeach
        </nav>
    @endif

    <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
        <div class="min-w-0">
            <h1 class="page-title">{{ $title }}</h1>
            @if($subtitle)<p class="mt-2 max-w-[68ch] text-sm leading-6 text-slate-500">{{ $subtitle }}</p>@endif
            {{ $meta ?? '' }}
        </div>

        @if(isset($actions))
            <div class="flex shrink-0 flex-wrap items-center gap-2">{{ $actions }}</div>
        @endif
    </div>
</header>
