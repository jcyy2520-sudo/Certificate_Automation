@props(['name'])
@php
    // 24px line icons, stroke-based so they inherit currentColor and scale cleanly.
    $paths = [
        'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V9.5"/><path d="M9.5 21v-5.5a2.5 2.5 0 0 1 5 0V21"/>',
        'layers' => '<path d="m12 3 9 5-9 5-9-5 9-5Z"/><path d="m3 13 9 5 9-5"/>',
        'clipboard' => '<path d="M9 4h6v3H9z"/><path d="M15 5.5h2.5A1.5 1.5 0 0 1 19 7v12.5a1.5 1.5 0 0 1-1.5 1.5h-11A1.5 1.5 0 0 1 5 19.5V7a1.5 1.5 0 0 1 1.5-1.5H9"/><path d="M9 12h6M9 16h4"/>',
        'users' => '<circle cx="9" cy="8" r="3.2"/><path d="M3.5 20a5.5 5.5 0 0 1 11 0"/><path d="M16 5.2a3.2 3.2 0 0 1 0 5.9"/><path d="M17.5 14.6a5.5 5.5 0 0 1 3 5.4"/>',
        'award' => '<circle cx="12" cy="9" r="5.5"/><path d="m8.5 13.8-1.3 6.4 4.8-2.6 4.8 2.6-1.3-6.4"/>',
        'shield' => '<path d="M12 3l7 2.8v5.4c0 4.3-2.9 7.6-7 9.8-4.1-2.2-7-5.5-7-9.8V5.8L12 3Z"/><path d="m9.2 12 2 2 3.6-3.8"/>',
        'panel-left' => '<rect x="3" y="4" width="18" height="16" rx="2.2"/><path d="M9.5 4v16"/>',
        'log-out' => '<path d="M14 20H6a1.5 1.5 0 0 1-1.5-1.5v-13A1.5 1.5 0 0 1 6 4h8"/><path d="m16.5 15.5 3.5-3.5-3.5-3.5"/><path d="M20 12H9.5"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'download' => '<path d="M12 3.5v11"/><path d="m8 11 4 4 4-4"/><path d="M4.5 19.5h15"/>',
        'search' => '<circle cx="11" cy="11" r="6.5"/><path d="m20 20-3.6-3.6"/>',
        'filter' => '<path d="M4 6h16M7 12h10M10 18h4"/>',
        'link' => '<path d="M10.5 13.5a3.5 3.5 0 0 0 5 0l3-3a3.5 3.5 0 0 0-5-5l-1.4 1.4"/><path d="M13.5 10.5a3.5 3.5 0 0 0-5 0l-3 3a3.5 3.5 0 0 0 5 5l1.4-1.4"/>',
        'external' => '<path d="M14 4h6v6"/><path d="m20 4-8.5 8.5"/><path d="M18 14.5V19a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h4.5"/>',
        'chevron-left' => '<path d="m14 6-6 6 6 6"/>',
        'arrow-left' => '<path d="M19 12H5"/><path d="m11 6-6 6 6 6"/>',
        'check' => '<path d="m5 12.5 4.5 4.5L19 7"/>',
        'x' => '<path d="M18 6 6 18M6 6l12 12"/>',
        'alert' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 8v4.5M12 16h.01"/>',
        'mail' => '<rect x="3" y="5.5" width="18" height="13" rx="2"/><path d="m3.8 7 8.2 6 8.2-6"/>',
        'calendar' => '<rect x="3.5" y="5" width="17" height="15.5" rx="2"/><path d="M3.5 10h17M8.5 3v4M15.5 3v4"/>',
        'settings' => '<path d="M4 8h9M17.5 8H20M4 16h3.5M12 16h8"/><circle cx="15" cy="8" r="2.3"/><circle cx="9.5" cy="16" r="2.3"/>',
        'chevron-down' => '<path d="m6 9.5 6 6 6-6"/>',
        'chevron-right' => '<path d="m9 6 6 6-6 6"/>',
        'bar-chart' => '<path d="M4 20.5h16"/><rect x="5.5" y="10" width="3.4" height="8" rx="0.6"/><rect x="10.3" y="4.5" width="3.4" height="13.5" rx="0.6"/><rect x="15.1" y="13" width="3.4" height="5" rx="0.6"/>',
        'image' => '<rect x="3.5" y="4.5" width="17" height="15" rx="2"/><circle cx="8.8" cy="9.8" r="1.6"/><path d="m4.5 17 4.3-4.3 3.2 3.2L15.5 12l4.5 4.5"/>',
        'file-text' => '<path d="M14 3H7a1.5 1.5 0 0 0-1.5 1.5v15A1.5 1.5 0 0 0 7 21h10a1.5 1.5 0 0 0 1.5-1.5V7.5L14 3Z"/><path d="M13.8 3v4.7h4.6M9 13h6M9 16.5h4"/>',
        'edit' => '<path d="M4 20h4.2L19 9.2a1.9 1.9 0 0 0-2.7-2.7L5.5 17.3 4 20Z"/><path d="m14.5 8 2.7 2.7"/>',
        'grid' => '<rect x="4" y="4" width="7" height="7" rx="1.4"/><rect x="13" y="4" width="7" height="7" rx="1.4"/><rect x="4" y="13" width="7" height="7" rx="1.4"/><rect x="13" y="13" width="7" height="7" rx="1.4"/>',
        'send' => '<path d="M20 4 3.5 11.2 10 13l2 6.5L20 4Z"/><path d="M10 13 20 4"/>',
        'clock' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
        'check-circle' => '<circle cx="12" cy="12" r="8.5"/><path d="m8.5 12 2.4 2.4 4.6-4.8"/>',
    ];
@endphp
<svg {{ $attributes->merge(['class' => 'size-5', 'aria-hidden' => 'true']) }}
     viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6"
     stroke-linecap="round" stroke-linejoin="round">
    {!! $paths[$name] ?? $paths['alert'] !!}
</svg>
