<section class="panel mb-5 p-5" data-response-summary>
    <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="section-title">{{ $form->title }} summary</h2><a class="button-secondary" href="{{ request()->fullUrl() }}">Refresh summary</a></div>
    <p class="mt-3 text-sm"><strong>{{ $summary['total'] }}</strong> current submissions · {{ $summary['native'] }} native · {{ $summary['imported'] }} imported</p>
    <p class="mt-2 text-sm text-slate-500">Summary includes every current submitted attempt, independently of certificate eligibility. This view refreshes every 30 seconds while visible. Text examples are limited to 20 per question; export for all responses.</p>
    @if($summary['total'] === 0)<p class="mt-4 font-medium">No responses yet. Share this form or import responses to start the summary.</p>@endif
</section>
@if($summary['scores']['count'])
<section class="panel mb-5 p-5"><h3 class="section-title">Recorded scores</h3><p class="mt-2 text-sm">{{ $summary['scores']['count'] }} scored submissions · Average {{ round($summary['scores']['sum'] / $summary['scores']['count'], 2) }} points · Range {{ $summary['scores']['min'] }}–{{ $summary['scores']['max'] }} points</p><p class="mt-2 text-sm text-slate-500">Native quiz scores and explicitly mapped imported scores. Different maximum scores are not normalized.</p></section>
@endif
@if($summary['days'])
<section class="panel mb-5 p-5"><h3 class="section-title">Response trend</h3><p class="mt-1 text-sm text-slate-500">Most recent 30 submission dates · {{ $webinar->timezone }}</p><div class="mt-4 space-y-3">@foreach($summary['days'] as $day => $count)<div class="summary-bar"><span>{{ $day }}</span><meter min="0" max="{{ max($summary['days']) }}" value="{{ $count }}" aria-label="{{ $day }}: {{ $count }} submissions">{{ $count }}</meter><strong>{{ $count }}</strong></div>@endforeach</div></section>
@endif
<div class="grid gap-5 xl:grid-cols-2">
@foreach($columns as $key => $column)
    @php $item = $summary['questions'][$key]; @endphp
    <section class="panel min-w-0 p-5"><h3 class="break-words text-sm font-semibold">{{ $column['label'] }}</h3><p class="mt-1 text-sm text-slate-500">{{ $item['answered'] }} answered · {{ $summary['total'] - $item['answered'] }} without an available answer</p>
        @if(in_array($column['type'], ['radio', 'select', 'checkbox', 'multiple_choice', 'true_false', 'rating']))
            <div class="mt-4 space-y-3">@foreach(array_unique(array_values($column['options'])) as $option)
                @php $count = $item['counts'][$option] ?? 0; $percent = $item['answered'] ? round($count / $item['answered'] * 100, 1) : 0; @endphp
                <div class="summary-bar"><span>{{ $option }}</span><meter min="0" max="100" value="{{ $percent }}" aria-label="{{ $option }}: {{ $count }} ({{ $percent }}%)">{{ $percent }}%</meter><strong>{{ $count }} ({{ $percent }}%)</strong></div>
            @endforeach</div>
            @if($column['type'] === 'checkbox')<p class="mt-3 text-xs text-slate-500">Multiple selections can make percentages total more than 100%.</p>@endif
            @if($item['unrecognized'])<p class="mt-3 text-sm text-amber-700">{{ $item['unrecognized'] }} unrecognized choice value(s). Inspect the original imported response.</p>@endif
        @else
            @if($item['numbers']['count'])<p class="mt-3 text-sm">Average {{ round($item['numbers']['sum'] / $item['numbers']['count'], 2) }} · Minimum {{ $item['numbers']['min'] }} · Maximum {{ $item['numbers']['max'] }}</p>@endif
            @if($column['imported'] ?? false)<p class="mt-3 text-xs text-slate-500">Imported field. Question type is unconfirmed; values are shown as text.</p>@endif
            <ul class="mt-4 divide-y divide-slate-200 text-sm">@forelse($item['texts'] as $text)<li class="whitespace-pre-wrap break-words py-3">{{ $text }}</li>@empty<li>No available answers.</li>@endforelse</ul>
        @endif
    </section>
@endforeach
</div>
@if($columns === [])<p class="panel p-5 text-sm text-slate-500">This form has no custom questions. Participant details and original imported cells are available in Responses.</p>@endif
