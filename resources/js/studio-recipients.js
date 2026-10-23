import { buildNode } from './navigation';
import { webinarRestrictionMessage } from './webinar-access';

/** Refresh only the roster; keep the open certificate and per-person designs. */
export function initStudioRecipients(root = document, signal) {
    const studio = root.querySelector('[data-studio]');
    const dialog = root.querySelector('[data-manual-recipient-dialog]');
    if (!studio || !dialog) return;
    const $ = (selector) => root.querySelector(selector);
    const form = $('[data-manual-recipient-form]');
    const submit = $('[data-manual-submit]');
    const search = $('[data-certificate-search]');
    const source = $('[data-recipient-source]');
    const csrf = form.querySelector('[name="_token"]').value;
    let editing = null;
    let opener;
    let busy = false;
    let request;
    let generation = 0;
    let timer;
    let poll;
    let highlight = '';
    let listUrl = new URL(location.href);

    const errorBox = $('[data-manual-error]');
    function clearErrors() {
        errorBox.hidden = true;
        $('[data-manual-password-confirm]').hidden = true;
        for (const [key, id] of [['full_name', 'manual-name-error'], ['email', 'manual-email-error']]) {
            $(`#${id}`).hidden = true; form.elements[key].removeAttribute('aria-invalid');
        }
    }
    function showErrors(body) {
        clearErrors();
        errorBox.textContent = body.message || 'The request could not be completed. Your entries are still here.';
        errorBox.hidden = false;
        if (body.confirmationUrl) $('[data-manual-password-confirm]').hidden = false;
        for (const [key, id] of [['full_name', 'manual-name-error'], ['email', 'manual-email-error']]) {
            const messages = body.errors?.[key];
            if (messages) { $(`#${id}`).textContent = messages[0]; $(`#${id}`).hidden = false; form.elements[key].setAttribute('aria-invalid', 'true'); }
        }
        (form.querySelector('[aria-invalid="true"]') || errorBox).focus();
    }
    function open(id = null, button = document.activeElement) {
        if (busy) return;
        editing = id; opener = button; clearErrors(); form.reset();
        const row = id && $(`[data-certificate-row="${id}"]`);
        if (id && row?.dataset.manualEditable !== '1') return;
        form.elements.full_name.value = id ? $(`[data-name-store="${id}"]`).dataset.value : '';
        form.elements.email.value = id ? $(`[data-email-store="${id}"]`).dataset.value : '';
        $('#manual-recipient-title').textContent = id ? 'Edit manual recipient' : 'Add Recipient Manually';
        submit.textContent = id ? 'Save changes' : 'Add to Studio';
        // Close mobile drawers before the native modal takes focus.
        studio.querySelectorAll('[data-panel]').forEach((panel) => panel.classList.remove('is-open'));
        $('[data-panel-scrim]')?.classList.add('hidden');
        dialog.showModal(); form.elements.full_name.focus();
    }
    function close() { if (!busy) { dialog.close(); form.reset(); opener?.focus(); } }
    $('[data-manual-cancel]').addEventListener('click', close);
    dialog.addEventListener('cancel', (event) => { event.preventDefault(); close(); });

    async function refresh({ focusId, announce = false } = {}) {
        request?.abort(); const controller = new AbortController(); request = controller;
        const timeout = setTimeout(() => controller.abort('timeout'), 30000);
        const current = ++generation;
        const params = new FormData(); params.set('_token', csrf);
        params.set('search', search?.value || ''); params.set('source', source?.value || ''); params.set('highlight', highlight);
        const url = new URL(studio.dataset.recipientsUrl);
        url.search = listUrl.search;
        studio.querySelector('[data-certificate-list]')?.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, { method: 'POST', body: params, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'text/html', 'X-Frontend-Navigation': '1' } });
            if (!response.ok || !response.headers.get('content-type')?.includes('application/vnd.webinar.navigation+json')) {
                throw new Error(await webinarRestrictionMessage(response) || 'Recipient list could not be refreshed. Try Refresh recipients.');
            }
            const body = await response.json();
            if (body.boundary || !body.body) throw new Error('Your session or webinar access needs attention. Sign in or unlock the webinar, then refresh recipients.');
            if (current !== generation || signal?.aborted) return;
            const next = buildNode(body.body);
            const selected = new Set([...root.querySelectorAll('[data-queue-check]:checked')].map((input) => input.dataset.queueCheck));
            for (const selector of ['[data-certificate-list]', '[data-studio-send-inputs]', '[data-recipient-pagination]', '[data-tab-panel="sent"]', '[data-tab-panel="incomplete"]']) {
                const existing = $(selector); const replacement = next.querySelector(selector);
                if (existing && replacement) existing.replaceChildren(...replacement.childNodes);
            }
            root.querySelectorAll('[data-queue-check]').forEach((input) => { input.checked = !input.disabled && selected.has(input.dataset.queueCheck); });
            root.dispatchEvent(new CustomEvent('studio:recipients-refreshed', { detail: { focusId } }));
            for (const selector of ['[data-recipient-sent-total]', '[data-recipient-incomplete-total]', '[data-bulk-ready-count]']) {
                if ($(selector) && next.querySelector(selector)) $(selector).textContent = next.querySelector(selector).textContent;
            }
            const bulk = $('[data-bulk-send]');
            if (bulk) {
                const count = Number(next.querySelector('[data-bulk-ready-count]')?.textContent || 0);
                bulk.classList.toggle('sm:inline-flex', count > 0);
                bulk.dataset.confirm = `Send certificates to every ready recipient (${count} total)? The saved template style is used. Held import rows must be resolved before bulk sending.`;
            }
            const total = next.querySelector('[data-recipient-working-total]');
            if (total && $('[data-recipient-working-total]')) $('[data-recipient-working-total]').textContent = total.textContent;
            if (announce) window.notify?.('success', 'Recipient list refreshed.');
            const error = $('[data-recipient-refresh-error]'); if (error) error.hidden = true;
        } catch (error) {
            if (current !== generation || signal?.aborted) return;
            if (controller.signal.reason === 'timeout') error = new Error('Refreshing recipients took too long. Your certificate adjustments are still here. Try Refresh recipients.');
            if (error.name !== 'AbortError') {
                const message = $('[data-recipient-refresh-error]'); if (message) { message.textContent = error.message; message.hidden = false; }
                if (focusId) throw error;
            }
        } finally { clearTimeout(timeout); if (current === generation) $('[data-certificate-list]')?.removeAttribute('aria-busy'); }
    }
    async function mutate(url, method, data) {
        // PHP parses multipart bodies on POST; use Laravel's native method override.
        if (method !== 'POST') data.set('_method', method);
        const controller = new AbortController();
        const abort = () => controller.abort();
        if (signal?.aborted) abort();
        else signal?.addEventListener('abort', abort, { once: true });
        const timeout = setTimeout(() => controller.abort('timeout'), 120000);
        try {
            const response = await fetch(url, { method: 'POST', body: data, credentials: 'same-origin', headers: { Accept: 'application/json' }, signal: controller.signal });
            if (response.redirected && new URL(response.url).pathname.endsWith('/confirm-password')) {
                throw { message: 'Confirm your password in a separate tab to complete this action. Your entries and certificate adjustments stay here.', confirmationUrl: response.url };
            }
            const body = response.headers.get('content-type')?.includes('application/json') ? await response.json() : {};
            if (!response.ok || !body.message) throw body;
            return body;
        } catch (error) {
            if (controller.signal.reason === 'timeout') throw { message: 'This request is taking too long and may have completed. Your entries are still here. Cancel, then use Refresh recipients to check the saved state before retrying.' };
            if (error instanceof Error) throw { message: 'Connection interrupted. Check the recipient list before retrying; your entries are still here.' };
            throw error;
        } finally {
            clearTimeout(timeout); signal?.removeEventListener('abort', abort);
        }
    }
    form.addEventListener('submit', async (event) => {
        event.preventDefault(); if (busy || event.isComposing) return;
        busy = true; submit.disabled = true; form.setAttribute('aria-busy', 'true'); clearErrors();
        let saved = false;
        try {
            const row = editing && $(`[data-certificate-row="${editing}"]`);
            const body = await mutate(row ? row.dataset.manualUpdateUrl : studio.dataset.manualUrl, row ? 'PUT' : 'POST', new FormData(form));
            saved = true; const id = body.participant_id || editing; highlight = id;
            listUrl = new URL(location.href); listUrl.search = '';
            if (search) search.value = ''; if (source) source.value = '';
            dialog.close(); form.reset(); opener?.focus();
            window.notify?.('success', body.message);
            await refresh({ focusId: id });
        } catch (body) {
            if (!saved) showErrors(body);
            else window.notify?.('warning', 'Recipient saved. Refresh recipients to load the updated list.');
        } finally { busy = false; submit.disabled = false; form.removeAttribute('aria-busy'); }
    });
    // Studio-owned pagination runs before the persistent document navigation
    // handler, including after this page was installed by async navigation.
    studio.addEventListener('click', async (event) => {
        const add = event.target.closest('[data-add-recipient]'); if (add) { open(null, add); return; }
        const edit = event.target.closest('[data-edit-manual]'); if (edit) { open(edit.dataset.editManual, edit); return; }
        const remove = event.target.closest('[data-remove-manual]');
        if (remove && !busy) {
            busy = true; remove.disabled = true;
            try {
                const row = remove.closest('[data-certificate-row]'); const data = new FormData(); data.set('_token', csrf);
                const body = await mutate(row.dataset.manualUpdateUrl, 'DELETE', data);
                await refresh(); window.notify?.('success', body.message);
            } catch (body) {
                window.notify?.('error', body.errors?.recipient?.[0] || body.message || 'Recipient could not be removed. Refresh the list before retrying.');
                if (body.confirmationUrl) $('[data-recipient-password-confirm]').hidden = false;
            }
            finally { busy = false; remove.disabled = false; }
        }
        const page = event.target.closest('[data-recipient-pagination] a');
        if (page) { event.preventDefault(); event.stopPropagation(); listUrl = new URL(page.href); refresh(); }
        if (event.target.closest('[data-refresh-recipients]')) refresh({ announce: true });
    }, { signal });
    function filter(event) {
        clearTimeout(timer); if (event?.isComposing) return;
        listUrl.searchParams.delete('page'); timer = setTimeout(() => refresh(), 300);
    }
    root.addEventListener('studio:send-finished', () => { if (!busy) void refresh(); }, { signal });
    search?.addEventListener('input', filter); search?.addEventListener('compositionend', filter); source?.addEventListener('change', filter);
    function schedule() { poll = setTimeout(async () => {
        if (!document.hidden && !dialog.open && !busy && !studio.dataset.sending && !root.querySelector('[data-send-confirm]:not([hidden]), [data-name-correction-modal]:not([hidden]), [data-confirm-modal]:not([hidden])')) await refresh();
        if (!signal?.aborted) schedule();
    }, 30000); }
    schedule();
    signal?.addEventListener('abort', () => { request?.abort(); clearTimeout(timer); clearTimeout(poll); dialog.close(); }, { once: true });
    if (new URL(location.href).searchParams.has('add_recipient')) open();
}
