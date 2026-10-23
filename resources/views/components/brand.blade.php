@props(['compact' => false, 'inverse' => false, 'navigation' => false, 'labelClass' => ''])
@php $brandName = config('branding.name', 'Certimate'); @endphp
<span {{ $attributes->class(['inline-flex max-w-full shrink-0 items-center gap-2']) }} data-brand="{{ $compact ? 'compact' : ($inverse ? 'inverse' : 'full') }}">
    @if($compact || $inverse || $navigation)
        <picture class="block shrink-0">
            <source srcset="{{ asset('brand/certimate-icon.webp') }}" type="image/webp">
            <img src="{{ asset('brand/certimate-icon.png') }}" alt="{{ $compact ? $brandName : '' }}" width="40" height="40" class="size-8 rounded-md object-contain">
        </picture>
        @unless($compact)<span class="text-[20px] font-bold tracking-tight {{ $navigation ? 'text-slate-900 dark:text-white' : 'text-white' }} {{ $labelClass }}">{{ $brandName }}</span>@endunless
    @else
        <picture class="block h-12.5 w-45 max-w-full dark:hidden">
            <source srcset="{{ asset('brand/certimate-wordmark.webp') }}" type="image/webp">
            <img src="{{ asset('brand/certimate-wordmark.png') }}" alt="{{ $brandName }}" width="180" height="50" class="h-full w-full object-contain">
        </picture>
        <span class="hidden h-12.5 w-45 max-w-full items-center gap-2 dark:inline-flex">
            <picture class="block shrink-0">
                <source srcset="{{ asset('brand/certimate-icon.webp') }}" type="image/webp">
                <img src="{{ asset('brand/certimate-icon.png') }}" alt="" width="40" height="40" class="size-10 object-contain">
            </picture>
            <span class="text-[26px] font-bold leading-none tracking-tight text-white">{{ $brandName }}</span>
        </span>
    @endif
</span>
