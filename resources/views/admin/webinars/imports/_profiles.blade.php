<details class="panel mb-5 p-5">
    <summary class="cursor-pointer text-sm font-semibold">Saved column mappings @if($mappingProfiles->isNotEmpty())({{ $mappingProfiles->count() }})@endif</summary>
    <p class="mt-3 text-sm text-slate-500">Reuse your reviewed choices when a new export has exactly the same columns. Each upload still needs its own source confirmation and dry check.</p>

    @if($mappingProfiles->isNotEmpty())
        <ul class="mt-4 divide-y divide-slate-200 dark:divide-slate-800">
            @foreach($mappingProfiles as $profile)
                @php $profileCheck = $profileChecks[$profile->id]; @endphp
                <li class="flex flex-col gap-3 py-3 sm:flex-row sm:items-start sm:justify-between">
                    <div class="min-w-0">
                        <p class="break-words text-sm font-semibold">{{ $profile->name }}</p>
                        <p id="mapping-profile-note-{{ $profile->id }}" class="mt-1 text-sm text-slate-500">{{ $profileCheck['message'] }}</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap gap-2">
                        @if($profileCheck['compatible'])
                            <form method="POST" action="{{ route('admin.webinars.imports.profiles.apply', [$webinar, $import, $profile]) }}">
                                @csrf
                                <button type="submit" class="button-secondary" aria-describedby="mapping-profile-note-{{ $profile->id }}">Apply<span class="sr-only"> {{ $profile->name }}</span></button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('admin.webinars.imports.profiles.destroy', [$webinar, $import, $profile]) }}"
                              data-confirm="Remove this saved column mapping? Existing uploads and responses are kept."
                              data-confirm-title="Remove saved mapping" data-confirm-action="Remove mapping" data-confirm-tone="neutral">
                            @csrf @method('DELETE')
                            <button type="submit" class="button-secondary">Remove<span class="sr-only"> {{ $profile->name }}</span></button>
                        </form>
                    </div>
                </li>
            @endforeach
        </ul>
    @else
        <p class="mt-3 text-sm text-slate-500">You have no saved mappings for this form type yet.</p>
    @endif

    @if(! empty($import->column_map['review_hash']))
        <form method="POST" action="{{ route('admin.webinars.imports.profiles.store', [$webinar, $import]) }}" class="mt-4 flex flex-col gap-3 border-t border-slate-200 pt-4 dark:border-slate-800 sm:flex-row sm:items-end">
            @csrf
            <div class="min-w-0 flex-1">
                <label for="mapping-profile-name" class="field-label">Save this reviewed mapping as</label>
                <input id="mapping-profile-name" name="name" type="text" maxlength="80" required class="field" placeholder="For example, monthly registration" aria-describedby="mapping-profile-save-note">
                <p id="mapping-profile-save-note" class="mt-1 text-xs text-slate-500">Only column choices and date/score preferences are saved. Response values and source details are excluded.</p>
            </div>
            <button type="submit" class="button-secondary shrink-0">Save mapping</button>
        </form>
    @else
        <p class="mt-4 text-sm text-slate-500">Run the dry check to save the current mapping for future uploads.</p>
    @endif
</details>
