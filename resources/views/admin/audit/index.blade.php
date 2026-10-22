@extends($webinar ? 'layouts.webinar' : 'layouts.app')
@section('title', 'Audit Trail')
@section('content')
<x-page-header title="Audit Trail" :subtitle="$webinar ? 'Activity for '.$webinar->title.' only, attributed to the individual who performed it.' : 'All account and webinar activity, attributed to the individual who performed it.'">
    <x-slot:actions>@if($webinar && auth()->user()->isAdministrator())<a class="button-secondary" href="{{ route('admin.audit.index') }}">All system activity</a>@endif</x-slot:actions>
</x-page-header>
<form method="GET" action="{{ $trailUrl }}" class="panel mb-6 grid gap-4 p-5 sm:grid-cols-2 xl:grid-cols-3">
<label class="field-label">User<select class="field" name="actor"><option value="">All users</option>@foreach($actors as $actor)<option value="{{ $actor->id }}" @selected(($filters['actor'] ?? '') == $actor->id)>{{ $actor->name }} (#{{ $actor->id }})</option>@endforeach @if(!empty($filters['actor']) && !$actors->contains('id', $filters['actor']))<option selected value="{{ $filters['actor'] }}">Historical user #{{ $filters['actor'] }}</option>@endif</select></label>
<label class="field-label">Action<input class="field" name="action" maxlength="100" placeholder="For example: account or certificate" value="{{ $filters['action'] ?? '' }}"></label>
@if(!$webinar)<label class="field-label">Webinar<select class="field" name="webinar"><option value="">All webinars</option>@foreach($webinars as $event)<option value="{{ $event->id }}" @selected(($filters['webinar'] ?? '') == $event->id)>{{ $event->title }}</option>@endforeach</select></label>@endif
<label class="field-label">From (Asia/Manila)<input class="field" type="date" name="from" value="{{ $filters['from'] ?? '' }}"></label>
<label class="field-label">Through (Asia/Manila)<input class="field" type="date" name="to" value="{{ $filters['to'] ?? '' }}"></label>
<label class="field-label">Result<select class="field" name="outcome"><option value="">All results</option>@foreach(['success' => 'Success', 'failure' => 'Failure'] as $key => $label)<option value="{{ $key }}" @selected(($filters['outcome'] ?? '') === $key)>{{ $label }}</option>@endforeach</select></label>
<div class="flex gap-2"><button class="button-primary">Apply filters</button><a class="button-secondary" href="{{ $trailUrl }}">Clear</a></div>
</form>
<p class="mb-4 text-sm text-slate-500">{{ $logs->total() }} {{ Str::plural('event', $logs->total()) }}. IP and browser signals are retained as keyed fingerprints. Historical identity snapshots appear when available; older records are preserved as recorded.</p>
<div class="space-y-3">
@forelse($logs as $log)
<article class="panel p-5">
<div class="flex flex-wrap justify-between gap-3"><div><h2 class="text-sm font-semibold">{{ Str::headline(str_replace('.', ' ', $log->action)) }}</h2><p class="mt-1 text-xs text-slate-500">{{ $log->action }} · {{ $log->created_at->timezone('Asia/Manila')->format('M j, Y H:i:s') }} · Event #{{ $log->id }}</p></div><span class="badge badge-slate">{{ $log->outcome ? ucfirst($log->outcome) : 'Historical record' }}</span></div>
<p class="mt-3 text-sm">{{ $log->actor_name ?? $log->user?->name ?? ($log->user_id || $log->actor_user_id ? 'Historical account' : 'Public request or system task') }}@if($id = ($log->actor_user_id ?? $log->user_id)) <span class="text-slate-500">· User #{{ $id }}</span>@endif</p>
@if(auth()->user()->isAdministrator() && $log->actor_email)<p class="mt-1 break-all text-xs text-slate-500">{{ $log->actor_email }}</p>@endif
<p class="mt-2 text-xs text-slate-500">Resource: {{ $log->auditable_type ? class_basename($log->auditable_type).' #'.$log->auditable_id : 'System' }}@if($log->webinar_id) · Webinar #{{ $log->webinar_id }}@endif</p>
@if($log->metadata || (auth()->user()->isAdministrator() && ($log->ip_address_hash || $log->user_agent)))<details class="mt-3"><summary class="cursor-pointer text-sm text-accent-600">Details and changes</summary><pre class="mt-3 max-h-80 overflow-auto whitespace-pre-wrap break-all rounded-lg border border-slate-200 p-3 text-xs">{{ json_encode(['changes_and_context' => $log->metadata] + (auth()->user()->isAdministrator() ? ['network_fingerprint' => $log->ip_address_hash, 'browser_fingerprint' => $log->user_agent] : []), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre></details>@endif
</article>
@empty<div class="panel p-8 text-center text-slate-500">No activity matches these filters.</div>@endforelse
</div><div class="mt-6">{{ $logs->links() }}</div>
@endsection
