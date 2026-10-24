/** Optional, page-only presentation choices; response content is never rewritten. */
export function initResponseTableControls(root = document, signal) {
    if (signal?.aborted) return;

    root.querySelectorAll('[data-response-workspace]').forEach((workspace) => {
        const controls = workspace.querySelector('[data-response-table-controls]');
        if (!controls) return;

        const choices = [...controls.querySelectorAll('[data-question-toggle]')];
        const cells = [...workspace.querySelectorAll('[data-question-column]')];
        const options = [...controls.querySelectorAll('[data-question-option]')];
        const count = controls.querySelector('[data-question-count]');
        const search = controls.querySelector('[data-question-search]');
        const empty = controls.querySelector('[data-question-empty]');
        const density = controls.querySelector('[data-response-density-control]');
        const resetButton = controls.querySelector('[data-response-reset]');
        const normalize = value => value.replace(/\s+/gu, ' ').trim().toLocaleLowerCase();
        const headings = options.map(option => normalize(option.textContent));

        const showQuestions = () => {
            const selected = new Map(choices.map(choice => [choice.value, choice.checked]));
            // Compare keys literally, never interpolate imported text into selectors.
            cells.forEach(cell => { cell.hidden = selected.get(cell.dataset.questionColumn) === false; });
            const shown = choices.filter(choice => choice.checked).length;
            if (count) count.textContent = `${shown} of ${choices.length} question columns shown`;
        };
        const searchHeadings = () => {
            const query = normalize(search?.value || '');
            let matches = 0;
            options.forEach((option, index) => {
                option.hidden = !headings[index].includes(query);
                if (!option.hidden) matches++;
            });
            if (empty) empty.hidden = !query || matches > 0;
        };
        const setDensity = () => {
            workspace.dataset.responseDensity = density?.value === 'compact' ? 'compact' : 'comfortable';
        };
        const reset = () => {
            choices.forEach(choice => { choice.checked = true; });
            if (search) search.value = '';
            if (density) density.value = 'comfortable';
            showQuestions();
            searchHeadings();
            setDensity();
        };

        choices.forEach(choice => choice.addEventListener('change', showQuestions, { signal }));
        search?.addEventListener('input', searchHeadings, { signal });
        density?.addEventListener('change', setDensity, { signal });
        resetButton?.addEventListener('click', reset, { signal });
        reset();
        controls.hidden = false;
    });
}
