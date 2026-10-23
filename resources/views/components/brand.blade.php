@props(['compact' => false, 'inverse' => false, 'labelClass' => ''])
@php $brandName = config('branding.name', 'Certimate'); @endphp
<span {{ $attributes->class(['inline-flex max-w-full shrink-0 items-center gap-2']) }} data-brand="{{ $compact ? 'compact' : ($inverse ? 'inverse' : 'full') }}">
    @if($compact || $inverse)
        <picture class="block shrink-0">
            <source srcset="{{ asset('brand/certimate-icon.webp') }}" type="image/webp">
            <img src="{{ asset('brand/certimate-icon.png') }}" alt="{{ $compact ? $brandName : '' }}" width="40" height="40" class="size-8 rounded-md object-contain">
        </picture>
        @unless($compact)<span class="text-[20px] font-bold tracking-tight text-white {{ $labelClass }}">{{ $brandName }}</span>@endunless
    @else
        <picture class="block dark:hidden">
            <source srcset="{{ asset('brand/certimate-wordmark.webp') }}" type="image/webp">
            <img src="{{ asset('brand/certimate-wordmark.png') }}" alt="{{ $brandName }}" width="180" height="50" class="h-auto w-45 max-w-full rounded-md object-contain">
        </picture>
        <span class="hidden items-center gap-2 dark:inline-flex">
            <picture class="block shrink-0">
                <source srcset="{{ asset('brand/certimate-icon.webp') }}" type="image/webp">
                <img src="{{ asset('brand/certimate-icon.png') }}" alt="" width="40" height="40" class="size-8 rounded-md object-contain">
            </picture>
            <span class="text-[20px] font-bold tracking-tight text-white">{{ $brandName }}</span>
        </span>
    @endif
</span>
