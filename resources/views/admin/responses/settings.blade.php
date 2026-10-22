<form id="certificate-requirements" class="panel max-w-3xl p-5" method="POST" action="{{ route('admin.certification.rules', $webinar) }}">@csrf @method('PUT')
    <input type="hidden" name="return_to_responses" value="1">
    <h2 class="section-title">Eligibility Settings</h2>
    <p class="mt-2 text-sm text-slate-500">Select the forms participants must complete. Leave minimum points blank to require completion only. Existing overrides and issued certificates are preserved.</p>
    <p class="mt-2 text-sm text-slate-500">Registration uses the existing verified registration record, including historical registrations. For tests, any submitted attempt meeting the minimum points qualifies. Imported corrections must be explicitly reviewed; superseded attempts do not count.</p>
    <div class="mt-4 divide-y divide-slate-200">
        @foreach(\App\Services\FormResponseService::TYPES + ['attendance' => 'Attendance (existing optional requirement)'] as $key => $label)
            @php $rule = $rules[$key] ?? null; @endphp
            <div class="flex flex-wrap items-center justify-between gap-3 py-4">
                <label class="flex items-center gap-3 text-sm font-medium"><input type="checkbox" name="rules[{{ $key }}][enabled]" value="1" class="survey-check size-5" @checked(old('rules.'.$key.'.enabled', $rule?->is_required))>{{ $label }}</label>
                @if(! in_array($key, ['registration', 'attendance']))
                    <label class="field-label max-w-52">Minimum points (optional)<input class="field" name="rules[{{ $key }}][minimum_score]" type="number" min="0" max="100000" step="0.01" value="{{ old('rules.'.$key.'.minimum_score', $rule?->minimum_score) }}"></label>
                @endif
            </div>
        @endforeach
    </div>
    <p class="my-4 text-sm text-amber-700">Changing requirements re-evaluates eligibility immediately. Studio reflects the current requirements automatically. Manual certificate exceptions remain separate, and issued certificates remain intact.</p>
    <button class="button-primary">Save eligibility settings</button>
</form>
