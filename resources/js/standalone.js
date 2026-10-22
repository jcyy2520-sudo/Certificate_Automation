import { initTheme } from './theme';
import { initAccountControls } from './account-controls';
import { initFormFeedback } from './form-feedback';
initTheme();
initFormFeedback();
initAccountControls(new AbortController().signal);
window.addEventListener('pageshow', () => {
    document.querySelectorAll('form[data-submitted]').forEach((form) => form.removeAttribute('data-submitted'));
    document.querySelectorAll('button[aria-busy="true"]').forEach((button) => { button.disabled = false; button.removeAttribute('aria-busy'); });
});

document.querySelectorAll('textarea').forEach((input) => {
    const grow = () => { input.style.height = 'auto'; input.style.height = `${Math.max(96, input.scrollHeight + 2)}px`; };
    input.addEventListener('input', grow); grow();
});
const survey = document.querySelector('[data-survey]:not([data-admin-preview])');
if (survey) {
    const original = JSON.stringify([...new FormData(survey)]);
    let submitting = false;
    survey.addEventListener('submit', (event) => {
        if (event.defaultPrevented) return;
        if (submitting) { event.preventDefault(); return; }
        submitting = true;
        const button = event.submitter;
        if (button) { button.disabled = true; button.setAttribute('aria-busy', 'true'); }
    });
    window.addEventListener('beforeunload', (event) => {
        if (!submitting && JSON.stringify([...new FormData(survey)]) !== original) { event.preventDefault(); event.returnValue = ''; }
    });
    window.addEventListener('pageshow', () => { submitting = false; survey.querySelectorAll('[aria-busy="true"]').forEach((button) => { button.disabled = false; button.removeAttribute('aria-busy'); }); });
}
