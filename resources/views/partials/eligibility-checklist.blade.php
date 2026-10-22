<div class="divide-y divide-slate-100">
@foreach($summary['activities'] as $activity)
    <article class="px-5 py-4">
        <div class="flex flex-wrap items-start justify-between gap-2">
            <div><h3 class="text-sm font-semibold text-slate-900">{{ $activity['label'] }}</h3><p class="mt-1 text-xs text-slate-500">{{ $activity['required'] ? 'Required for this webinar' : 'Not required for eligibility' }}</p></div>
            <span class="badge {{ $activity['state'] === 'Completed' ? 'badge-green' : 'badge-slate' }}">{{ $activity['state'] }}</span>
        </div>
        <p class="mt-2 text-xs leading-5 text-slate-600">{{ $activity['detail'] }} @if($activity['required'] && $activity['met'] === false)<strong>Still required.</strong>@endif</p>
        @if($activity['minimum_score'] !== null && $activity['required'])<p class="mt-1 text-xs text-slate-600">Configured minimum score: {{ $activity['minimum_score'] }}</p>@endif
        @if($activity['marker_at'])<p class="mt-2 text-xs text-slate-500">Verified record: <time datetime="{{ $activity['marker_at'] }}">{{ \Carbon\Carbon::parse($activity['marker_at'])->timezone($participant->webinar->timezone ?: config('app.timezone'))->format('M j, Y g:i A T') }}</time></p>@endif
        @foreach($activity['records'] as $record)
            <p class="mt-2 break-words text-xs leading-5 text-slate-500">{{ $record['reference'] }} · {{ $record['source'] }}@if($record['import_reference']) · {{ $record['import_reference'] }}@endif<br>{{ $record['match'] }}.
                @if($record['recorded_at'])<br>{{ $record['timestamp_kind'] }}: <time datetime="{{ $record['recorded_at'] }}">{{ \Carbon\Carbon::parse($record['recorded_at'])->timezone($participant->webinar->timezone ?: config('app.timezone'))->format('M j, Y g:i A T') }}</time>@endif
                @if($record['score'] !== null)<br>Recorded score: {{ $record['score'] }}@endif
            </p>
        @endforeach
    </article>
@endforeach
</div>
