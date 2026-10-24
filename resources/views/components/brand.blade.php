@props(['compact' => false, 'inverse' => false, 'navigation' => false, 'labelClass' => ''])
@php $brandName = config('branding.name', 'Certimate'); @endphp
<span {{ $attributes->class(['brand-lockup', 'brand-lockup-full' => ! $compact && ! $navigation, 'brand-lockup-navigation' => $navigation, 'brand-lockup-inverse' => $inverse]) }} data-brand="{{ $compact ? 'compact' : ($inverse ? 'inverse' : 'full') }}">
    <picture class="block shrink-0">
        <source srcset="{{ asset('brand/certimate-mark.webp') }}" type="image/webp">
        <img src="{{ asset('brand/certimate-mark.png') }}" alt="{{ $compact ? $brandName : '' }}" width="40" height="40" class="brand-mark">
    </picture>
    @unless($compact)<span class="brand-name {{ $labelClass }}">{{ $brandName }}</span>@endunless
</span>
