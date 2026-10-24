/** The displayed participant page is the complete selection boundary. */
export function initParticipantCertificates(root = document, signal) {
    const form = root.querySelector('[data-participant-certificate-selection]');
    if (!form) return;
    const choices = [...form.querySelectorAll('[data-participant-certificate-checkbox]:not(:disabled)')];
    const selectPage = form.querySelector('[data-select-page-certificates]');
    const count = form.querySelector('[data-participant-selection-count]');
    const submit = form.querySelector('[data-open-certificate-studio]');
    const sync = () => {
        const selected = choices.filter(choice => choice.checked).length;
        if (count) count.textContent = String(selected);
        if (submit) submit.disabled = selected === 0;
        if (selectPage) {
            selectPage.disabled = choices.length === 0;
            selectPage.checked = choices.length > 0 && selected === choices.length;
            selectPage.indeterminate = selected > 0 && selected < choices.length;
        }
    };
    choices.forEach(choice => choice.addEventListener('change', sync, { signal }));
    selectPage?.addEventListener('change', () => {
        choices.forEach(choice => { choice.checked = selectPage.checked; });
        sync();
    }, { signal });
    form.addEventListener('submit', event => {
        if (!choices.some(choice => choice.checked)) event.preventDefault();
    }, { signal });
    sync();
}
