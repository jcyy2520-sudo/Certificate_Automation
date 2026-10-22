import { initFormFeedback } from './form-feedback';

export function initPageUsability(signal) {
    initFormFeedback();
    // Give existing wide data tables one scroll owner and a keyboard entry point.
    document.querySelectorAll('table').forEach((table) => {
        const wrapper = table.parentElement;
        if (!wrapper || !/(?:overflow-x-auto|response-table-scroll)/.test(wrapper.className)) return;
        wrapper.tabIndex = 0;
        wrapper.setAttribute('role', 'region');
        if (!wrapper.hasAttribute('aria-label')) wrapper.setAttribute('aria-label', 'Scrollable data table');
        table.querySelectorAll('thead th').forEach((cell) => { if (!cell.hasAttribute('scope')) cell.scope = 'col'; });
    });
    document.querySelectorAll('textarea').forEach((field) => {
        const grow = () => { field.style.height = 'auto'; field.style.height = `${Math.max(96, field.scrollHeight + 2)}px`; };
        field.addEventListener('input', grow); grow();
    });
    document.querySelectorAll('form').forEach((form) => {
        const search = form.querySelector('input[name="search"], input[name="q"], [data-certificate-search]');
        if (!search) return;
        const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'search-clear'; clear.textContent = 'Clear search';
        clear.setAttribute('aria-label', 'Clear search');
        search.insertAdjacentElement('afterend', clear);
        let timer;
        const sync = () => { clear.hidden = !search.value; };
        const schedule = (event) => {
            sync(); clearTimeout(timer);
            if (event?.isComposing || !/\/(?:filter|search)$/.test(new URL(form.getAttribute('action') || location.href, location.href).pathname)) return;
            timer = setTimeout(() => { if (form.isConnected && !window.webinarNavigation?.pending()) form.requestSubmit(); }, 300);
        };
        search.addEventListener('input', schedule);
        search.addEventListener('compositionend', schedule);
        form.addEventListener('submit', () => clearTimeout(timer));
        clear.addEventListener('click', () => {
            clearTimeout(timer); search.value = ''; sync(); search.dispatchEvent(new Event('input', { bubbles: true })); search.focus();
            clearTimeout(timer);
            if (/\/(?:filter|search)$/.test(new URL(form.getAttribute('action') || location.href, location.href).pathname)) form.requestSubmit();
        });
        signal.addEventListener('abort', () => clearTimeout(timer), { once: true }); sync();
    });
    document.querySelectorAll('[data-certificate-search]').forEach((search) => {
        if (search.form) return;
        const clear = document.createElement('button'); clear.type = 'button'; clear.className = 'search-clear'; clear.textContent = 'Clear search'; clear.setAttribute('aria-label', 'Clear recipient search');
        search.closest('label')?.insertAdjacentElement('afterend', clear);
        const sync = () => { clear.hidden = !search.value; };
        search.addEventListener('input', sync);
        clear.addEventListener('click', () => { search.value = ''; search.dispatchEvent(new Event('input', { bubbles: true })); search.focus(); }); sync();
    });
    // Existing drawers use modal semantics only while open. Keep closed desktop
    // Studio panels out of the tab order on phones, and trap open overlay focus.
    const candidates = () => [...document.querySelectorAll('[data-mobile-rail-drawer], [data-mobile-webinar-nav-drawer], [data-panel]')];
    let opener;
    let previous;
    let lastOutsideFocus = document.activeElement;
    document.addEventListener('focusin', (event) => {
        if (!event.target.closest('dialog, [role="dialog"]')) lastOutsideFocus = event.target;
    }, { signal });
    const isolated = new Map();
    const restoreBackground = () => { isolated.forEach((value, node) => { node.inert = value; }); isolated.clear(); };
    const isolateBackground = (active) => {
        for (let branch = active; branch?.parentElement && branch !== document.body; branch = branch.parentElement) {
            for (const sibling of branch.parentElement.children) {
                if (sibling === branch || sibling.matches('.mobile-nav-overlay, [data-panel-scrim]')) continue;
                if (!isolated.has(sibling)) isolated.set(sibling, sibling.inert);
                sibling.inert = true;
            }
        }
    };
    const syncLayers = () => {
        candidates().forEach((panel) => {
            if (!panel.hasAttribute('data-panel')) return;
            const mobile = innerWidth < 1024;
            if (!mobile) { panel.removeAttribute('role'); panel.removeAttribute('aria-modal'); }
            panel.inert = mobile && !panel.classList.contains('is-open');
            panel.setAttribute('aria-hidden', String(panel.inert));
        });
        const active = document.querySelector('[data-confirm-modal]:not([hidden]), [data-send-confirm]:not([hidden]), [data-name-correction-modal]:not([hidden])')
            || candidates().find((panel) => innerWidth < 1024 && (panel.getAttribute('aria-hidden') === 'false' || panel.classList.contains('is-open')));
        if (document.documentElement.classList.contains('overlay-open') !== Boolean(active)) document.documentElement.classList.toggle('overlay-open', Boolean(active));
        if (active && previous !== active) {
            restoreBackground();
            opener = lastOutsideFocus;
            active.setAttribute('role', 'dialog'); active.setAttribute('aria-modal', 'true');
            if (!active.hasAttribute('aria-label') && !active.hasAttribute('aria-labelledby')) active.setAttribute('aria-label', active.hasAttribute('data-panel') ? 'Certificate controls' : 'Confirmation');
            active.querySelector('button:not(:disabled), input:not(:disabled), select:not(:disabled), a[href]')?.focus();
        }
        if (!active && previous) {
            restoreBackground();
            previous.removeAttribute('aria-modal');
            if (opener?.isConnected) opener.focus();
        }
        if (active) isolateBackground(active);
        previous = active;
    };
    const observer = new MutationObserver(syncLayers);
    candidates().concat([...document.querySelectorAll('[data-confirm-modal], [data-send-confirm], [data-name-correction-modal]')]).forEach((node) => observer.observe(node, { attributes: true, attributeFilter: ['class', 'hidden'] }));
    // Drawer classes live on html.
    observer.observe(document.documentElement, { attributes: true, attributeFilter: ['class'] });
    window.addEventListener('resize', syncLayers, { signal });
    document.addEventListener('keydown', (event) => {
        if (!previous || event.key !== 'Tab') return;
        const nodes = [...previous.querySelectorAll('a[href],button:not(:disabled),input:not(:disabled):not([type="hidden"]),select:not(:disabled),textarea:not(:disabled),[tabindex="0"]')].filter((node) => node.getClientRects().length && !node.closest('[inert]'));
        if (!nodes.length) { event.preventDefault(); return; }
        if (event.shiftKey && (document.activeElement === nodes[0] || !previous.contains(document.activeElement))) { event.preventDefault(); nodes.at(-1).focus(); }
        else if (!event.shiftKey && (document.activeElement === nodes.at(-1) || !previous.contains(document.activeElement))) { event.preventDefault(); nodes[0].focus(); }
    }, { signal });
    signal.addEventListener('abort', () => { observer.disconnect(); restoreBackground(); document.documentElement.classList.remove('overlay-open'); });
    syncLayers();
}
