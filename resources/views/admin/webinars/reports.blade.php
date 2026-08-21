@extends('layouts.webinar')
@section('title', 'Reports — '.$webinar->title)
@section('content')

<x-page-header title="Reports & analytics"
               subtitle="How this webinar’s audience moved from registration through to certification." />

@php
    $cards = [
        ['label' => 'Participants', 'value' => $webinar->participants_count, 'icon' => 'users'],
        ['label' => 'Verified', 'value' => $verifiedCount, 'icon' => 'check-circle'],
        ['label' => 'Eligible', 'value' => $eligibleCount, 'icon' => 'clipboard'],
        ['label' => 'Certificates issued', 'value' => $issuedCount, 'icon' => 'award'],
    ];
@endphp
<div class="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-slate-200 bg-slate-200 xl:grid-cols-4">
    @foreach($cards as $card)
        <div class="bg-white p-5">
            <div class="flex items-center gap-2 text-slate-500"><x-icon name="{{ $card['icon'] }}" class="size-4" /><p class="text-[13px]">{{ $card['label'] }}</p></div>
            <p class="mt-2 text-[26px] font-semibold leading-none tracking-[-0.02em] tabular-nums">{{ $card['value'] }}</p>
        </div>
    @endforeach
</div>

<div class="mt-6 grid gap-6 xl:grid-cols-[1.3fr_1fr]">
    <section class="panel overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="section-title">Response rate by stage</h2>
            <p class="mt-0.5 text-[13px] text-slate-500">Distinct participants who submitted each form, as a share of all {{ $webinar->participants_count }} participants.</p>
        </div>
        <div class="space-y-4 p-5">
            @forelse($formStats as $stat)
                <div>
                    <div class="mb-1.5 flex items-center justify-between text-[13px]">
                        <span class="font-medium text-slate-900">{{ $stat['title'] }}</span>
                        <span class="tabular-nums text-slate-500">{{ $stat['respondents'] }} · {{ $stat['rate'] }}%</span>
                    </div>
                    <div class="h-2.5 overflow-hidden rounded-full bg-slate-100">
                        <div class="h-full rounded-full bg-accent-600" style="width: {{ min(100, $stat['rate']) }}%"></div>
                    </div>
                </div>
            @empty
                <p class="py-6 text-center text-sm text-slate-500">This webinar has no forms.</p>
            @endforelse
        </div>
    </section>

    <section class="panel overflow-hidden">
        <div class="border-b border-slate-100 px-5 py-4">
            <h2 class="section-title">Assessment averages</h2>
            <p class="mt-0.5 text-[13px] text-slate-500">Mean score across submitted attempts.</p>
        </div>
        <div class="divide-y divide-slate-100">
            @php $scored = $formStats->whereIn('type', ['pretest', 'posttest']); @endphp
            @forelse($scored as $stat)
                <div class="px-5 py-4">
                    <div class="flex items-baseline justify-between">
                        <span class="text-[13px] font-medium text-slate-900">{{ $stat['title'] }}</span>
                        @if($stat['avg'] !== null)
                            <span class="tabular-nums text-[15px] font-semibold text-slate-900">{{ $stat['avg'] }}<span class="text-[13px] font-normal text-slate-400"> / {{ $stat['avgMax'] ?? '—' }}</span></span>
                        @else
                            <span class="text-[13px] text-slate-400">No scored submissions</span>
                        @endif
                    </div>
                    @if($stat['avg'] !== null && $stat['avgMax'])
                        <div class="mt-2 h-2 overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-emerald-500" style="width: {{ min(100, (int) round($stat['avg'] / max(0.01, $stat['avgMax']) * 100)) }}%"></div>
                        </div>
                    @endif
                </div>
            @empty
                <p class="px-5 py-8 text-center text-sm text-slate-500">No pre/post assessments on this webinar.</p>
            @endforelse
        </div>
    </section>
</div>

<section class="panel mt-6 overflow-hidden">
    <div class="border-b border-slate-100 px-5 py-4">
        <h2 class="section-title">Certificates</h2>
    </div>
    <div class="grid grid-cols-2 gap-px bg-slate-100 sm:grid-cols-4">
        @foreach([
            ['Eligible', $eligibleCount],
            ['Issued', $issuedCount],
            ['Awaiting', max(0, $eligibleCount - $issuedCount)],
            ['Revoked', $revokedCount],
        ] as [$label, $value])
            <div class="bg-white p-5">
                <p class="text-[13px] text-slate-500">{{ $label }}</p>
                <p class="mt-1.5 text-[22px] font-semibold leading-none tabular-nums">{{ $value }}</p>
            </div>
        @endforeach
    </div>
    <div class="border-t border-slate-100 px-5 py-3.5">
        <a class="inline-flex items-center gap-1.5 text-[13px] font-medium text-accent-600 hover:underline" href="{{ route('admin.certificates.studio', $webinar) }}">
            <x-icon name="send" class="size-4" />Go to certificate selection &amp; preview
        </a>
    </div>
</section>
@endsection
