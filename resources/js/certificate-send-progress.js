/** No automatic retry: an interrupted mutation has to be reconciled first. */
export async function processCertificatePlan(items, { send, update, stopped, wait = (ms) => new Promise(resolve => setTimeout(resolve, ms)), interval = 2100, now = Date.now }) {
    const results = [];
    for (let index = 0; index < items.length; index++) {
        if (stopped()) break;
        const started = now();
        update({ index, phase: 'sending', item: items[index], results });
        let result;
        try { result = await send(items[index], index); }
        catch (_) { result = { outcome: 'unknown', halt: true, message: 'No completed response. Check Email logs before retrying; this email may have been accepted.' }; }
        if (!['accepted', 'captured', 'failed', 'skipped', 'unknown'].includes(result?.outcome)) {
            result = { outcome: 'unknown', halt: true, message: 'Unexpected response. Check Email logs before retrying.' };
        }
        results.push({ ...result, item: items[index] });
        update({ index, phase: 'completed', item: items[index], results });
        if (result.halt || result.outcome === 'unknown' || stopped()) break;
        if (index + 1 < items.length) await wait(Math.max(0, interval - (now() - started)));
    }
    return results;
}

export function initCertificateSendProgress(root = document, signal) {
    const studio = root.querySelector('[data-studio]');
    const dialog = root.querySelector('[data-send-progress-dialog]');
    if (!studio || !dialog) return;
    const $ = selector => dialog.querySelector(selector);
    let active = false;
    let stop = false;
    let currentForm;
    const labels = { accepted: 'Provider accepted', captured: 'Captured locally (no email sent)', failed: 'Failed', skipped: 'Skipped', unknown: 'Unconfirmed — check Email logs' };
    function syncResendSelection() {
        const checks = [...studio.querySelectorAll('[data-resend-check]')];
        const checked = checks.filter(input => input.checked).length;
        const all = studio.querySelector('[data-resend-all]');
        if (all) { all.checked = checks.length > 0 && checked === checks.length; all.indeterminate = checked > 0 && checked < checks.length; }
        const bulk = studio.querySelector('[data-resend-selected-form]');
        if (bulk) {
            bulk.dataset.confirm = `Resend the corrected certificate and correction message to ${checked} selected eligible recipients? This intentionally sends a new email to each selected person.`;
            const count = bulk.querySelector('[data-resend-count]'); if (count) count.textContent = String(checked);
            const button = bulk.querySelector('button[type="submit"]'); if (button) button.disabled = checked === 0 || active;
        }
    }
    studio.addEventListener('change', event => {
        if (event.target.matches('[data-resend-all]')) studio.querySelectorAll('[data-resend-check]').forEach(input => { input.checked = event.target.checked; });
        if (event.target.matches('[data-resend-check], [data-resend-all]')) syncResendSelection();
    }, { signal });
    root.addEventListener('studio:recipients-refreshed', syncResendSelection, { signal });
    signal?.addEventListener('abort', () => { stop = true; }, { once: true });
    $('[data-progress-stop]').addEventListener('click', () => {
        stop = true; $('[data-progress-current]').textContent = 'Stopping after the current recipient. No further recipients will start.';
    });
    dialog.addEventListener('cancel', event => { if (active) { event.preventDefault(); stop = true; } });
    $('[data-progress-close]').addEventListener('click', () => dialog.close());
    window.addEventListener('beforeunload', event => { if (active) { event.preventDefault(); event.returnValue = ''; } }, { signal });

    async function jsonPost(url, body, mutation = false) {
        const destination = new URL(url, location.href);
        if (destination.origin !== location.origin) throw new Error('Invalid send destination.');
        const controller = new AbortController(); const timer = setTimeout(() => controller.abort(), mutation ? 180000 : 30000);
        try {
            const response = await fetch(destination, { method: 'POST', body, credentials: 'same-origin', signal: controller.signal, headers: { Accept: 'application/json' } });
            const payload = response.headers.get('content-type')?.includes('application/json') ? await response.json() : null;
            if (!response.ok || !payload) {
                if (mutation) throw new Error('The send result is unconfirmed. Check Email logs before retrying.');
                throw new Error(Object.values(payload?.errors || {}).flat().join(' ') || payload?.message || 'The recipient list could not be prepared. No email was sent.');
            }
            return payload;
        } finally { clearTimeout(timer); }
    }
    root.addEventListener('submit', async event => {
        const form = event.target;
        if (event.defaultPrevented || !form.matches('[data-certificate-progress]')) return;
        event.preventDefault();
        if (active) return;
        currentForm = form;
        const data = new FormData(form); const csrf = data.get('_token') || root.querySelector('meta[name="csrf-token"]')?.content;
        data.set('mode', form.dataset.certificateProgress);
        let designs;
        try { designs = JSON.parse(data.get('designs_json') || '{}'); } catch (_) { designs = {}; }
        active = true; stop = false; studio.dataset.sending = '1';
        form.setAttribute('aria-busy', 'true'); form.setAttribute('data-submitted', '1'); form.removeAttribute('data-confirmed');
        $('[data-progress-list]').replaceChildren(); $('[data-progress-summary]').textContent = 'Preparing the recipient list…';
        $('[data-progress-current]').textContent = 'No email starts until the list is verified.';
        $('[data-progress-bar]').value = 0; $('[data-progress-bar]').max = 1;
        $('[data-progress-close]').disabled = true; $('[data-progress-stop]').disabled = false;
        dialog.showModal();
        try {
            const plan = await jsonPost(studio.dataset.sendPlanUrl, data);
            if (!Array.isArray(plan.items) || plan.items.length !== plan.total || !plan.plan_token || !plan.step_url) throw new Error('Invalid send plan. No emails were started.');
            const rows = plan.items.map(item => {
                const li = document.createElement('li'); li.className = 'border-b border-slate-100 py-2 text-sm';
                const name = document.createElement('span'); name.textContent = item.name; name.className = 'font-medium';
                const status = document.createElement('span'); status.textContent = ' — Not started';
                li.append(name, status); $('[data-progress-list]').append(li); return status;
            });
            $('[data-progress-bar]').max = Math.max(1, plan.total);
            const describe = results => {
                const count = outcome => results.filter(result => result.outcome === outcome).length;
                $('[data-progress-summary]').textContent = `${results.length} of ${plan.total} processed · ${count('accepted')} provider accepted · ${count('failed')} failed · ${count('skipped')} skipped · ${count('unknown')} unconfirmed${count('captured') ? ` · ${count('captured')} captured locally` : ''}`;
                $('[data-progress-bar]').value = results.length;
            };
            describe([]);
            const results = await processCertificatePlan(plan.items, {
                interval: Math.max(2100, Number(plan.interval_ms) || 2100), stopped: () => stop || signal?.aborted,
                send: async (item, index) => {
                    const body = new FormData(); body.set('_token', csrf); body.set('plan_token', plan.plan_token); body.set('index', String(index));
                    body.set('designs_json', JSON.stringify(item.mode === 'issue' ? { [item.id]: designs[item.id] } : {}));
                    return jsonPost(plan.step_url, body, true);
                },
                update: ({ index, phase, item, results }) => {
                    if (phase === 'sending') { rows[index].textContent = ' — Sending…'; $('[data-progress-current]').textContent = `Sending ${index + 1} of ${plan.total}: ${item.name}`; }
                    else { const result = results[results.length - 1]; rows[index].textContent = ` — ${labels[result.outcome]}${result.message ? ': ' + result.message : ''}`; describe(results); }
                },
            });
            describe(results);
            $('[data-progress-current]').textContent = results.length === plan.total
                ? 'Processing complete. Provider acceptance is separate from delivery. Review failures and Email logs before any retry.'
                : `Stopped. ${plan.total - results.length} recipient(s) were not started. Nothing will continue in the background.`;
        } catch (error) {
            $('[data-progress-current]').textContent = error.message;
        } finally {
            active = false; delete studio.dataset.sending; currentForm?.removeAttribute('aria-busy'); currentForm?.removeAttribute('data-submitted');
            $('[data-progress-stop]').disabled = true; $('[data-progress-close]').disabled = false;
            if (!signal?.aborted) root.dispatchEvent(new CustomEvent('studio:send-finished'));
            syncResendSelection();
        }
    }, { signal });
    syncResendSelection();
}
