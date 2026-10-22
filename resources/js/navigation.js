const mediaType = 'application/vnd.webinar.navigation+json';
const excluded = /\/(?:login|logout|password|two-factor|unlock|access|export(?:-[a-z]+)?|reconciliation|download|background|status)(?:\/|$)/;
const forbidden = new Set(['script', 'style', 'iframe', 'object', 'embed', 'base', 'link', 'meta']);

// No HTML parser, innerHTML, eval or Trusted Types policy. Values remain text
// and attributes, exactly as escaped by the existing Blade view.
export function buildNode(value, svg = false) {
    if (typeof value === 'string') return document.createTextNode(value);
    if (!value || forbidden.has(value.tag) || !/^[a-z][a-z0-9-]*$/.test(value.tag)) return document.createTextNode('');
    svg = svg || value.tag === 'svg';
    const element = svg ? document.createElementNS('http://www.w3.org/2000/svg', value.tag) : document.createElement(value.tag);
    for (let [name, content] of Object.entries(value.attrs || {})) {
        if (/^on/i.test(name) || ['srcdoc', 'nonce'].includes(name)) continue;
        if (['href', 'src', 'action', 'formaction', 'xlink:href'].includes(name.toLowerCase()) && /^\s*(?:javascript|vbscript):/i.test(content)) continue;
        if (svg && name === 'viewbox') name = 'viewBox';
        if (svg && name === 'preserveaspectratio') name = 'preserveAspectRatio';
        element.setAttribute(name, content);
    }
    const target = element.tagName === 'TEMPLATE' ? element.content : element;
    target.append(...(value.children || []).map((child) => buildNode(child, svg)));
    return element;
}

function eligible(url) {
    return url.origin === location.origin && url.pathname.startsWith('/admin') && !excluded.test(url.pathname);
}

function signature(form) {
    return JSON.stringify([...new FormData(form)].filter(([name]) => !['_token', '_method'].includes(name)).map(([name, value]) =>
        [name, value instanceof File ? [value.name, value.size, value.name ? value.lastModified : 0] : value]));
}

export function initNavigation(initializePage) {
    let request = null;
    let mutating = false;
    let pending = false;
    let restoringHistory = false;
    let ignoreNextPop = false;
    let leaveDecision = null;
    let queuedNavigation = null;
    let dirtyForms = new Map();
    let editorDirty = false;
    let bypassUnload = false;
    let currentUrl = location.href;
    let index = history.state?.webinarIndex ?? 0;
    history.replaceState({ webinarIndex: index, scroll: [scrollX, scrollY] }, '', location.href);
    history.scrollRestoration = 'manual';

    function captureForms() {
        editorDirty = false;
        dirtyForms = new Map([...document.forms].filter((form) => {
            if (form.method.toLowerCase() === 'get' || /\/(?:filter|search)$/.test(new URL(form.getAttribute('action') || location.href, location.href).pathname)) return false;
            return form.querySelector('textarea, input:not([type="hidden"]):not([readonly]):not([type="checkbox"]):not([type="radio"]):not([type="submit"]), select');
        }).map((form) => [form, signature(form)]));
    }
    function isDirty(except) {
        return editorDirty || [...dirtyForms].some(([form, original]) => form !== except && form.isConnected && signature(form) !== original);
    }
    window.addEventListener('beforeunload', (event) => {
        if (!bypassUnload && (mutating || isDirty())) { event.preventDefault(); event.returnValue = ''; }
    });
    document.addEventListener('app:dirty', (event) => { editorDirty = event.detail ?? true; });

    function confirmLeave(copy = {}) {
        if (!isDirty()) return Promise.resolve(true);
        if (leaveDecision) return leaveDecision;
        leaveDecision = new Promise((resolve) => {
            const dialog = document.createElement('dialog');
            dialog.className = 'navigation-dialog';
            dialog.setAttribute('aria-labelledby', 'unsaved-title');
            const title = document.createElement('h2'); title.id = 'unsaved-title'; title.textContent = copy.title || 'Leave unsaved changes?';
            const text = document.createElement('p'); text.textContent = copy.message || 'Changes on this page have not been saved. Stay here to finish, or discard them and continue.';
            const actions = document.createElement('div'); actions.className = 'mt-5 flex flex-wrap justify-end gap-2';
            for (const [label, result, css] of [['Stay on this page', false, 'button-secondary'], [copy.action || 'Discard changes', true, 'button-danger-solid']]) {
                const button = document.createElement('button'); button.type = 'button'; button.className = css; button.textContent = label;
                button.addEventListener('click', () => { dialog.close(); dialog.remove(); resolve(result); }); actions.append(button);
            }
            dialog.addEventListener('cancel', (event) => { event.preventDefault(); dialog.close(); dialog.remove(); resolve(false); }, { once: true });
            dialog.append(title, text, actions); document.body.append(dialog); dialog.showModal(); actions.firstChild.focus();
        });
        return leaveDecision.finally(() => { leaveDecision = null; });
    }

    function progress(active) {
        pending = active;
        document.documentElement.classList.toggle('is-navigating', active);
        document.querySelector('main, [data-studio]')?.setAttribute('aria-busy', String(active));
    }
    function announce(message) {
        let live = document.querySelector('[data-navigation-status]');
        if (!live) { live = document.createElement('div'); live.className = 'sr-only'; live.dataset.navigationStatus = ''; live.setAttribute('role', 'status'); document.body.append(live); }
        live.textContent = message;
    }
    const assets = () => [...document.querySelectorAll('link[rel="stylesheet"], script[type="module"][src]')].map((node) => node.href || node.src);
    const initialAssets = assets();

    async function navigate(url, options = {}) {
        const { form, submitter, historyMode = 'push', scroll, background = false } = options;
        if (mutating) { form?.removeAttribute('data-submitted'); form?.removeAttribute('data-confirmed'); return false; }
        if (!form && !background && !(await confirmLeave())) return false;
        const originalBaseline = form && dirtyForms.get(form);
        const otherEdits = form && [...dirtyForms].some(([other, original]) => other !== form && other.isConnected && signature(other) !== original);
        if (otherEdits && !(await confirmLeave({ title: 'Save and discard other edits?', message: 'Other forms on this page have unsaved changes. This save refreshes the page and discards those other edits. Stay here to finish those changes first.', action: 'Save and discard other edits' }))) {
            form.removeAttribute('data-submitted'); form.removeAttribute('data-confirmed'); return false;
        }
        if (background && (pending || isDirty() || document.querySelector('dialog[open], [data-confirm-modal]:not([hidden])'))) return;
        request?.abort();
        const controller = new AbortController(); request = controller;
        const isMutation = form && form.method.toLowerCase() !== 'get';
        const activeSearch = form?.contains(document.activeElement) && document.activeElement.matches('input[name="search"], input[name="q"]') ? document.activeElement : null;
        const submittedSearch = activeSearch?.value;
        mutating = Boolean(isMutation);
        let body;
        if (form) {
            body = new FormData(form);
            if (submitter?.name) body.append(submitter.name, submitter.value);
            if (!isMutation) {
                url = new URL(url);
                url.search = new URLSearchParams([...body].filter(([, value]) => typeof value === 'string')).toString();
                body = undefined;
            } else if (form.enctype !== 'multipart/form-data') {
                body = new URLSearchParams([...body].filter(([, value]) => typeof value === 'string'));
            }
            form.setAttribute('aria-busy', 'true');
        }
        const disabled = form ? [...form.querySelectorAll('button[type="submit"], button:not([type]), input[type="submit"]')].filter((button) => !button.disabled) : [];
        disabled.forEach((button) => { button.disabled = true; button.setAttribute('aria-busy', 'true'); });
        if (!background) { progress(true); announce(isMutation ? 'Saving changes…' : 'Loading page…'); }
        const timeout = setTimeout(() => controller.abort('timeout'), isMutation ? 120000 : 30000);
        let completed = false;
        try {
            const response = await fetch(url, {
                method: isMutation ? 'POST' : 'GET', body, credentials: 'same-origin', cache: 'no-store', signal: controller.signal,
                headers: { Accept: 'text/html', 'X-Frontend-Navigation': '1' },
            });
            if (!response.ok) throw new Error(response.status === 419 || response.status === 401
                ? 'Your session expired. Your entries are still here. Open sign-in in another tab, then try again.'
                : response.status === 403 ? 'You no longer have access to this action. Your entries are still here.'
                    : `The request could not be completed (${response.status}). Your entries are still here.`);
            if (!response.headers.get('content-type')?.includes(mediaType)) {
                // Only safe GET transitions may fall back. Never replay a mutation.
                if (!isMutation) { bypassUnload = true; location.assign(response.url); return; }
                throw new Error('The server returned an unexpected response. Check the saved state before trying again.');
            }
            const payload = await response.json();
            if (controller.signal.aborted) return;
            if (payload.boundary || JSON.stringify(payload.assets) !== JSON.stringify(initialAssets)) {
                bypassUnload = true; location.assign(response.url); return;
            }
            const nextBody = buildNode(payload.body);
            const validationFailed = Boolean(nextBody.querySelector('[data-validation-summary]'));
            const replacementForm = form && [...nextBody.querySelectorAll('form')].find((next) => next.getAttribute('action') === form.getAttribute('action'));
            if (validationFailed && replacementForm) {
                form.querySelectorAll('input[type="file"]').forEach((input) => {
                    const restored = [...replacementForm.querySelectorAll('input[type="file"]')].find((next) => next.name === input.name);
                    if (restored && input.files?.length) restored.files = input.files;
                });
            }
            // Build first, then stop observers/listeners before detaching the old page.
            document.dispatchEvent(new Event('app:before-render'));
            for (const node of nextBody.querySelectorAll('[data-persist-key]')) {
                const existing = [...document.querySelectorAll('[data-persist-key]')].find((item) => item.dataset.persistKey === node.dataset.persistKey);
                if (!existing) continue;
                const scrollTop = existing.scrollTop;
                const navScroll = [...existing.querySelectorAll('nav')].map((nav) => nav.scrollTop);
                existing.replaceChildren(...node.childNodes);
                node.replaceWith(existing);
                existing.scrollTop = scrollTop;
                existing.querySelectorAll('nav').forEach((nav, position) => { nav.scrollTop = navScroll[position] || 0; });
            }
            const oldScroll = [scrollX, scrollY];
            if (historyMode !== 'pop') history.replaceState({ webinarIndex: index, scroll: oldScroll }, '', currentUrl);
            document.body.className = nextBody.className;
            document.body.dataset.appLayout = nextBody.dataset.appLayout;
            document.body.replaceChildren(...nextBody.childNodes);
            document.documentElement.classList.remove('mobile-navigation-open', 'mobile-rail-open', 'mobile-webinar-nav-open');
            document.title = payload.title;
            document.querySelector('meta[name="csrf-token"]')?.setAttribute('content', payload.csrf);
            currentUrl = payload.url + (new URL(url, location.href).hash || '');
            if (historyMode === 'push' && currentUrl !== location.href) history.pushState({ webinarIndex: ++index, scroll: [0, 0] }, '', currentUrl);
            else if (historyMode !== 'pop') history.replaceState({ webinarIndex: index, scroll: oldScroll }, '', currentUrl);
            initializePage(); captureForms();
            if (validationFailed && replacementForm && originalBaseline !== undefined) dirtyForms.set(replacementForm, originalBaseline);
            const restoredSearch = activeSearch && [...document.querySelectorAll('input[name="search"], input[name="q"]')].find((input) => input.name === activeSearch.name);
            if (restoredSearch) {
                restoredSearch.value = activeSearch.value;
                restoredSearch.focus({ preventScroll: true });
                try { restoredSearch.setSelectionRange(activeSearch.selectionStart, activeSearch.selectionEnd); } catch (_) { /* Not a text selection control. */ }
                if (activeSearch.value !== submittedSearch) restoredSearch.dispatchEvent(new Event('input', { bubbles: true }));
            }
            if (!background) {
                const focus = document.querySelector('[aria-invalid="true"], [data-validation-summary], main, [data-studio]');
                if (focus && !restoredSearch) { focus.setAttribute('tabindex', '-1'); focus.focus({ preventScroll: true }); }
            }
            window.scrollTo(...(scroll || (background || form ? oldScroll : [0, 0])));
            const hash = location.hash && document.getElementById(decodeURIComponent(location.hash.slice(1)));
            if (hash) hash.scrollIntoView();
            announce(background ? 'Summary updated.' : `${document.title} loaded.`);
            document.dispatchEvent(new Event('app:navigated'));
            completed = !validationFailed;
            return true;
        } catch (error) {
            if (controller.signal.aborted && controller.signal.reason !== 'timeout') return;
            window.notify?.('error', controller.signal.reason === 'timeout'
                ? (isMutation ? 'This request is taking too long. Check its status before retrying; it may have completed.' : 'Loading took too long. Try the link again.')
                : error.message || 'Unable to connect. Your entries are still here. Try again when connected.');
            announce('Request failed. Your current page is still available.');
            return false;
        } finally {
            clearTimeout(timeout);
            if (request === controller) { request = null; mutating = false; progress(false); }
            if (form) { form.removeAttribute('aria-busy'); form.removeAttribute('data-submitted'); form.removeAttribute('data-confirmed'); }
            disabled.forEach((button) => { button.disabled = false; button.removeAttribute('aria-busy'); });
            if (isMutation) {
                const destination = queuedNavigation; queuedNavigation = null;
                if (completed && destination) void navigate(destination);
            }
        }
    }
    document.addEventListener('click', async (event) => {
        if (event.defaultPrevented || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        const link = event.target.closest('a[href]');
        if (!link || link.hasAttribute('download') || link.hasAttribute('data-full-navigation') || (link.target && link.target !== '_self')) return;
        const url = new URL(link.href);
        if (!eligible(url) || /\/preview(?:\/|$)/.test(url.pathname) || (url.pathname === location.pathname && url.search === location.search && url.hash)) return;
        event.preventDefault();
        if (mutating) { queuedNavigation = url; announce('This page will open when the current save finishes.'); return; }
        await navigate(url);
    });
    window.addEventListener('submit', (event) => {
        if (event.defaultPrevented || event.submitter?.hasAttribute('data-preview-submit')) return;
        const form = event.target;
        const url = new URL(event.submitter?.hasAttribute('formaction') ? event.submitter.formAction : (form.getAttribute('action') || location.href), location.href);
        if (!eligible(url) || (form.target && form.target !== '_self') || form.hasAttribute('data-full-navigation')) { bypassUnload = true; return; }
        event.preventDefault();
        void navigate(url, { form, submitter: event.submitter, historyMode: form.method === 'get' ? 'push' : 'replace' });
    });
    window.addEventListener('popstate', async (event) => {
        if (ignoreNextPop) { ignoreNextPop = false; requestAnimationFrame(() => { restoringHistory = false; }); return; }
        const target = location.href;
        const nextIndex = event.state?.webinarIndex ?? index;
        if (mutating || !(await confirmLeave())) {
            const delta = index - nextIndex;
            if (delta) {
                restoringHistory = true;
                ignoreNextPop = true; history.go(delta);
            }
            else history.replaceState({ webinarIndex: index }, '', currentUrl);
            return;
        }
        // The guard already ran, so avoid a second prompt.
        dirtyForms.clear(); editorDirty = false;
        const success = await navigate(new URL(target), { historyMode: 'pop', scroll: event.state?.scroll || [0, 0] });
        if (success) index = nextIndex;
        else { history.replaceState({ webinarIndex: index }, '', currentUrl); }
    });
    // Server responses are revalidated; private pages and drafts are never cached.
    let scrollTimer;
    window.addEventListener('scroll', () => {
        clearTimeout(scrollTimer);
        scrollTimer = setTimeout(() => { if (!pending && !restoringHistory && location.href === currentUrl) history.replaceState({ webinarIndex: index, scroll: [scrollX, scrollY] }, '', currentUrl); }, 150);
    }, { passive: true });
    window.webinarNavigation = { refresh: () => navigate(new URL(location.href), { historyMode: 'replace', background: true }), pending: () => pending || mutating || restoringHistory };
    captureForms();
}
