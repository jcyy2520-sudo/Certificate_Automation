export function initFormAvailability(root = document) {
    root.querySelectorAll('[data-availability-form]').forEach((form) => {
        const panel = form.closest('[data-availability-panel]');
        const toggle = form.querySelector('[data-availability-switch]');
        if (!panel || !toggle) return;

        const label = form.querySelector('[data-availability-label]');
        const state = panel.querySelector('[data-availability-state]');
        const stateText = panel.querySelector('[data-availability-state-text]');
        const dot = panel.querySelector('[data-availability-dot]');
        const notice = panel.querySelector('[data-availability-notice]');
        const message = panel.querySelector('[data-availability-message]');
        const error = form.querySelector('[data-availability-error]');
        const token = root.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

        const render = (result) => {
            toggle.checked = Boolean(result.open);
            if (label) {
                label.textContent = result.open ? 'Open' : 'Closed';
                label.classList.toggle('text-emerald-700', result.open);
                label.classList.toggle('text-slate-500', !result.open);
            }
            if (stateText) stateText.textContent = result.state;
            if (state) {
                state.classList.toggle('text-emerald-700', result.accepts_responses);
                state.classList.toggle('text-slate-500', !result.accepts_responses);
            }
            if (dot) {
                dot.classList.toggle('bg-emerald-500', result.accepts_responses);
                dot.classList.toggle('bg-slate-400', !result.accepts_responses);
            }
            if (message) message.textContent = result.message || '';
            if (notice) notice.classList.toggle('hidden', result.accepts_responses);
        };

        toggle.addEventListener('change', async () => {
            const previous = !toggle.checked;
            const desired = toggle.checked;
            toggle.disabled = true;
            form.setAttribute('aria-busy', 'true');
            if (error) {
                error.textContent = '';
                error.classList.add('hidden');
            }
            if (label) label.textContent = desired ? 'Opening…' : 'Closing…';

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': token,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({ is_open: desired ? 1 : 0 }),
                });

                if (!response.ok) {
                    throw new Error(response.status === 419
                        ? 'Your session expired. Refresh the page and try again.'
                        : 'The availability change could not be saved.');
                }

                render(await response.json());
            } catch (exception) {
                toggle.checked = previous;
                if (label) label.textContent = previous ? 'Open' : 'Closed';
                if (error) {
                    error.textContent = exception instanceof Error ? exception.message : 'The availability change could not be saved.';
                    error.classList.remove('hidden');
                }
            } finally {
                toggle.disabled = false;
                form.removeAttribute('aria-busy');
            }
        });
    });
}
