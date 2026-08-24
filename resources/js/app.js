/*
 * The interface is server-rendered Blade, and most client behaviour lives inline
 * (nonce'd) on the pages that need it. The certificate editor is the exception:
 * it is complex enough to warrant its own unit-tested module, loaded here as a
 * same-origin bundle (allowed by the CSP `script-src 'self'`).
 */
import { initCertificateEditor, initCertificateTemplateEditor } from './certificate-editor';
import { initFormAvailability } from './form-availability';

function boot() {
    initFormAvailability(document);
    initCertificateTemplateEditor(document);

    if (document.querySelector('[data-studio]')) {
        initCertificateEditor(document);
    }

    const selection = document.querySelector('[data-participant-certificate-selection]');
    if (selection) {
        const boxes = Array.from(selection.querySelectorAll('[data-participant-certificate-checkbox]'));
        const selectPage = selection.querySelector('[data-select-page-certificates]');
        const button = selection.querySelector('[data-open-certificate-studio]');
        const count = selection.querySelector('[data-participant-selection-count]');
        const storageKey = `certificate-studio-selection:${selection.action}`;
        try {
            const remembered = JSON.parse(sessionStorage.getItem(storageKey) || '[]');
            if (Array.isArray(remembered)) {
                boxes.forEach((box) => {
                    box.checked = box.hasAttribute('data-auto-selected') || remembered.includes(box.value);
                });
            }
        } catch (_) { /* The selection still works when storage is unavailable. */ }
        const sync = () => {
            const selected = boxes.filter((box) => box.checked && !box.disabled);
            if (button) button.disabled = selected.length === 0;
            if (count) count.textContent = String(selected.length);
            if (selectPage) selectPage.checked = boxes.filter((box) => !box.disabled).length > 0 && boxes.filter((box) => !box.disabled).every((box) => box.checked);
            try { sessionStorage.setItem(storageKey, JSON.stringify(selected.map((box) => box.value))); } catch (_) { /* optional */ }
        };
        boxes.forEach((box) => box.addEventListener('change', sync));
        selectPage?.addEventListener('change', () => { boxes.forEach((box) => { if (!box.disabled) box.checked = selectPage.checked; }); sync(); });
        sync();
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
} else {
    boot();
}
