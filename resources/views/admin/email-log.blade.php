@extends($webinar ? 'layouts.webinar' : 'layouts.app')
@section('title', ($webinar ? 'Email delivery log — '.$webinar->title : 'Email delivery log'))
@section('content')
@php
    $typeLabels = [
        'certificate' => 'Certificate',
        \App\Services\ParticipantMagicLinkService::DELIVERY_TYPE => 'Form access link',
        \App\Services\ParticipantStatusLinkService::DELIVERY_TYPE => 'Status page link',
    ];
    $statusBadges = [
        'sent' => 'badge-green',
        'pending' => 'badge-slate',
        'processing' => 'badge-amber',
        'failed' => 'badge-red',
        'cancelled' => 'badge-slate',
    ];
@endphp
<x-page-header title="Email delivery log"
               :crumbs="$webinar ? ['Webinars' => route('admin.webinars.index'), $webinar->title => route('admin.webinars.show', $webinar)] : []"
               :subtitle="$webinar
                   ? 'Every transactional message this webinar queued, with its delivery outcome. Recipient addresses are masked.'
                   : 'Every transactional message across all webinars, with delivery outcomes. Recipient addresses are masked.'">
    <x-slot:actions>
        <form method="GET" action="{{ $logUrl }}" class="flex items-center gap-2">
            <label class="chip w-48">
                <select name="status" data-auto-submit aria-label="Filter by status">
                    <option value="">All statuses</option>
                    @foreach(['pending', 'processing', 'sent', 'failed', 'cancelled'] as $option)
                        <option value="{{ $option }}" @selected(request('status') === $option)>{{ ucfirst($option) }}</option>
                    @endforeach
                </select>
            </label>
            <button class="button-secondary"><x-icon name="filter" class="size-4" />Apply</button>
        </form>
        @if($webinar)
            <a class="button-secondary" href="{{ route('admin.email-logs.index') }}">All webinars</a>
        @endif
    </x-slot:actions>
</x-page-header>

<div class="panel overflow-hidden">
    <div class="overflow-x-auto">
        <table class="w-full text-left text-sm">
            <thead class="border-b border-slate-200 bg-slate-50/70 text-[11px] uppercase tracking-[0.06em] text-slate-500">
                <tr>
                    <th class="px-5 py-3 font-semibold">Queued</th>
                    <th class="px-5 py-3 font-semibold">Type</th>
                    @if(! $webinar)<th class="px-5 py-3 font-semibold">Webinar</th>@endif
                    <th class="px-5 py-3 font-semibold">Recipient</th>
                    <th class="px-5 py-3 font-semibold">Subject</th>
                    <th class="px-5 py-3 font-semibold">Status</th>
                    <th class="px-5 py-3 font-semibold">Tries</th>
                    <th class="px-5 py-3 font-semibold">Detail</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse($deliveries as $delivery)
                    @php
                        $when = $delivery->sent_at ?? $delivery->failed_at ?? $delivery->scheduled_at ?? $delivery->created_at;
                        $detail = $delivery->last_error ?: ($delivery->provider_message_id ? 'Provider id '.$delivery->provider_message_id : '');
                    @endphp
                    <tr class="transition hover:bg-slate-50/60">
                        <td class="whitespace-nowrap px-5 py-3 text-slate-500 tabular-nums" title="{{ $when?->format('M j, Y g:i A') }}">{{ $when?->diffForHumans(short: true) }}</td>
                        <td class="px-5 py-3"><span class="badge badge-slate">{{ $typeLabels[$delivery->type] ?? Str::headline($delivery->type) }}</span></td>
                        @if(! $webinar)<td class="max-w-[20ch] truncate px-5 py-3 text-slate-600">{{ $delivery->webinar?->title ?? '—' }}</td>@endif
                        <td class="px-5 py-3 font-mono text-[12px] text-slate-700">{{ \App\Http\Controllers\Admin\EmailDeliveryController::maskRecipient($delivery->recipient_email) }}</td>
                        <td class="max-w-[28ch] truncate px-5 py-3 text-slate-600" title="{{ $delivery->subject }}">{{ $delivery->subject ?? '—' }}</td>
                        <td class="px-5 py-3"><span class="badge {{ $statusBadges[$delivery->status] ?? 'badge-slate' }}">{{ ucfirst($delivery->status) }}</span></td>
                        <td class="px-5 py-3 tabular-nums text-slate-600">{{ $delivery->attempts ?? 0 }}</td>
                        <td class="max-w-[34ch] truncate px-5 py-3 text-[12px] text-slate-500" title="{{ $detail }}">{{ $detail ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-5 py-14 text-center text-sm text-slate-500">No email deliveries match this view.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="mt-6">{{ $deliveries->links() }}</div>
@endsection
