@props(['columns'])
<div class="response-table-controls" data-response-table-controls hidden>
    <div class="response-table-toolbar">
        @if(count($columns))
            <details class="response-question-chooser" data-question-chooser>
                <summary class="button-secondary">Choose questions</summary>
                <div class="response-question-panel">
                    <label class="field-label">Search question headings
                        <input class="field" type="search" data-question-search autocomplete="off" placeholder="Find a question heading">
                    </label>
                    <p class="mt-2 text-sm text-slate-500">Search this list, then choose the question columns to show.</p>
                    <fieldset class="mt-3 min-w-0">
                        <legend class="sr-only">Question columns shown</legend>
                        <div class="response-question-options">
                            @foreach($columns as $key => $column)
                                <label class="response-question-option" data-question-option>
                                    <input type="checkbox" class="size-4 shrink-0" data-question-toggle value="{{ $key }}" checked autocomplete="off">
                                    <span class="min-w-0 whitespace-pre-wrap break-words">{{ $column['label'] }}</span>
                                </label>
                            @endforeach
                        </div>
                    </fieldset>
                    <p class="mt-3 text-sm text-slate-600" data-question-empty role="status" hidden>No question headings match your search.</p>
                </div>
            </details>
        @endif
        <label class="field-label response-density-control">Density
            <select class="field" data-response-density-control autocomplete="off" aria-label="Density">
                <option value="comfortable" selected>Comfortable</option>
                <option value="compact">Compact</option>
            </select>
        </label>
        <div class="response-table-display-status">
            <p class="text-sm text-slate-600" data-question-count role="status" aria-live="polite" aria-atomic="true">{{ count($columns) }} of {{ count($columns) }} question columns shown</p>
            <button class="button-secondary" type="button" data-response-reset>Reset table</button>
        </div>
    </div>
</div>
