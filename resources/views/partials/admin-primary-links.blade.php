@php
    $mobile = $mobile ?? false;
    $inWebinars = request()->routeIs('admin.webinars.*', 'admin.forms.*', 'admin.participants.*', 'admin.certification.*', 'admin.certificates.*');
    $railNav = [
        ['route' => 'admin.dashboard', 'icon' => 'home', 'label' => 'Dashboard', 'active' => request()->routeIs('admin.dashboard')],
        ['route' => 'admin.webinars.index', 'icon' => 'layers', 'label' => 'Webinars', 'active' => $inWebinars],
        ['route' => 'admin.email-logs.index', 'icon' => 'mail', 'label' => 'Email logs', 'active' => request()->routeIs('admin.email-logs.*', 'admin.webinars.email-logs.*')],
        ['route' => 'admin.two-factor.show', 'icon' => 'shield', 'label' => 'Security', 'active' => request()->routeIs('admin.two-factor.*')],
    ];
@endphp

@foreach($railNav as $item)
    <a href="{{ route($item['route']) }}"
       class="{{ $mobile ? 'mobile-rail-item' : 'rail-item' }} {{ $item['active'] ? ($mobile ? 'mobile-rail-item-active' : 'rail-item-active') : '' }}"
       @if(! $item['active']) title="{{ $item['label'] }}" @endif
       @if($item['active']) aria-current="page" @endif>
        <x-icon :name="$item['icon']" />
        @if($mobile)
            <span>{{ $item['label'] }}</span>
        @else
            <span class="rail-label">{{ $item['label'] }}</span>
        @endif
    </a>
@endforeach
