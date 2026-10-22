<section class="panel overflow-hidden">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
        <div><h2 class="section-title">Eligibility review</h2><p class="mt-1 text-sm text-slate-500">Eligible participants appear automatically in Certificate Studio. Manual certificate exceptions do not change response evidence.</p></div>
        <a class="button-secondary" href="{{ route('admin.certificates.studio', $webinar) }}">Open Certificate Studio</a>
    </div>
    <form method="POST" action="{{ route('admin.responses.filter', [$webinar, 'type' => $form->type]) }}" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">@csrf
        <input type="hidden" name="view" value="eligibility">
        <label class="field-label flex-1">Search name or email<input class="field" name="search" value="{{ $filters['search'] ?? '' }}" maxlength="180"></label>
        <button class="button-secondary">Search</button><button class="button-secondary" name="clear" value="1">Clear</button>
    </form>
    <form method="GET" class="flex flex-wrap items-end gap-3 border-b border-slate-200 p-4">
        <input type="hidden" name="view" value="eligibility"><input type="hidden" name="type" value="{{ $form->type }}">
        <label class="field-label">Eligibility status<select class="field" name="status">@foreach(['' => 'All participants', 'eligible' => 'Eligible', 'incomplete' => 'Incomplete', 'approved' => 'Historical approvals', 'reviewed' => 'Historical overrides'] as $key => $label)<option value="{{ $key }}" @selected(request('status', '') === $key)>{{ $label }}</option>@endforeach</select></label><button class="button-secondary">Filter</button>
    </form>

        <div class="response-table-scroll" tabindex="0" role="region" aria-label="Participant eligibility">
            <table class="response-table eligibility-table"><thead><tr><th>Participant</th>@foreach(\App\Services\FormResponseService::TYPES as $label)<th>{{ $label }}</th>@endforeach<th>Eligibility / missing requirements</th><th>Certificate Studio</th></tr></thead>
                <tbody>@forelse($participants as $participant)
                    @php $result = $participant->eligibility; $approval = $participant->certificateApproval; $changed = $approval && ($approval->eligibility_snapshot['rules'] ?? []) !== $snapshot; @endphp
                    <tr>

                        <th scope="row"><a class="text-accent-700 underline" href="{{ route('admin.participants.show', [$webinar, $participant]) }}">{{ $participant->full_name }}</a><span class="mt-1 block font-normal">{{ $participant->email }}</span></th>
                        @foreach(\App\Services\FormResponseService::TYPES as $key => $label)
                            @php $complete = $key === 'registration' ? $participant->verified_at !== null : $participant->submissions->contains(fn ($s) => $s->form?->webinar_id === $webinar->id && $s->form?->type === $key && $s->status === 'submitted'); @endphp
                            <td>{{ $complete ? 'Submitted' : 'No submission' }}@if(isset($rules[$key]) && $rules[$key]->is_required && $rules[$key]->minimum_score !== null)
                                @php $best = $participant->submissions->filter(fn ($s) => $s->form?->type === $key && $s->status === 'submitted' && $s->score !== null)->max('score'); @endphp
                                <span class="mt-1 block {{ $best !== null && (float) $best >= (float) $rules[$key]->minimum_score ? 'text-emerald-700' : 'text-amber-700' }}">{{ $best !== null ? $best.' points' : 'No score recorded' }} · Minimum {{ $rules[$key]->minimum_score }}</span>
                            @elseif(! ($rules->get($key)?->is_required ?? false))<span class="mt-1 block text-slate-500">Optional</span>@endif</td>
                        @endforeach
                        <td><span class="badge {{ $result['eligible'] ? 'badge-green' : 'badge-slate' }}">{{ $result['eligible'] ? 'Eligible' : 'Incomplete' }}</span>@if($result['overridden'])<span class="mt-1 block">Manually reviewed</span>@endif<span class="mt-1 block">{{ implode(', ', array_map(fn ($key) => \App\Services\FormResponseService::TYPES[$key] ?? ucfirst($key), array_keys(array_filter($result['requirements'], fn ($met) => ! $met)))) ?: ($result['eligible'] ? 'Requirements satisfied' : 'Manual exclusion') }}</span><a class="mt-2 block text-accent-700 underline" href="{{ route('admin.participants.show', [$webinar, $participant]) }}">View participant</a></td>
                        <td>@if($approval?->source === 'manual' && ! $approval->removed_at)<span class="badge badge-slate">Manual</span><span class="mt-1 block">Certificate exception; form requirements remain unchanged.</span>@elseif($result['eligible'])<span class="badge badge-green">Available automatically</span>@else Not available @endif
                            @if($approval && $approval->source !== 'manual')<span class="mt-1 block text-xs text-slate-500">Historical approval retained.@if($changed || ! $result['eligible']) Review needed ? current requirements differ.@endif</span>@endif
                        </td>
                    </tr>
                @empty<tr><td colspan="8" class="py-10 text-center">No participants match this review. Collect responses or choose another filter.</td></tr>@endforelse</tbody>
            </table>
        </div>

    <div class="border-t border-slate-200 p-4">{{ $participants->links() }}</div>
</section>
