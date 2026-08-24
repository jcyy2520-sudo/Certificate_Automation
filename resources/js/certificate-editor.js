/** Certificate Studio: shared selection, live design, drag, zoom and delivery tracking. */
export function clamp(n, lo, hi) {
    return Math.max(lo, Math.min(hi, n));
}

export function snap(value, target, tolerance = 1.5) {
    return Math.abs(value - target) <= tolerance
        ? { value: target, snapped: true }
        : { value, snapped: false };
}

/** The single authoritative certificate-style editor on the Template page. */
export function initCertificateTemplateEditor(root = document) {
    const designer = root.querySelector('[data-template-designer]');
    if (!designer) return;

    const $ = (selector) => designer.querySelector(selector);
    const $$ = (selector) => Array.from(designer.querySelectorAll(selector));
    const nameEl = $('[data-cert-name]');
    const canvas = $('[data-cert-canvas]');
    const fields = {
        top: $('[data-template-top]'), left: $('[data-template-left]'), font: $('[data-template-font]'),
        size: $('[data-template-size]'), color: $('[data-template-color]'), hex: $('[data-template-hex]'),
        weight: $('[data-template-weight]'), style: $('[data-template-style]'), align: $('[data-template-align-store]'),
    };
    if (!nameEl || !canvas) return;

    const defaults = { top: Number(fields.top.value), left: Number(fields.left.value) };
    const state = {
        top: defaults.top, left: defaults.left, font: fields.font.value, size: Number(fields.size.value),
        color: fields.color.value, weight: fields.weight.value, style: fields.style.value, align: fields.align.value,
    };

    function render() {
        nameEl.style.top = `${state.top}%`; nameEl.style.left = `${state.left}%`;
        nameEl.style.fontSize = `calc(${state.size} / 595 * 100cqh)`;
        nameEl.style.color = state.color;
        nameEl.style.fontWeight = state.weight === 'bold' ? '700' : '400';
        nameEl.style.fontStyle = state.style === 'italic' ? 'italic' : 'normal';
        nameEl.style.textAlign = state.align;
        nameEl.style.fontFamily = fields.font.options[fields.font.selectedIndex]?.dataset.css || '';
        fields.top.value = state.top.toFixed(2); fields.left.value = state.left.toFixed(2);
        fields.weight.value = state.weight; fields.style.value = state.style; fields.align.value = state.align;
        fields.color.value = state.color; fields.hex.value = state.color.toUpperCase();
        $('[data-template-size-readout]').textContent = String(Math.round(state.size));
        $('[data-template-bold]').classList.toggle('is-active', state.weight === 'bold');
        $('[data-template-italic]').classList.toggle('is-active', state.style === 'italic');
        $$('[data-template-align]').forEach((button) => button.classList.toggle('is-active', button.dataset.templateAlign === state.align));
    }

    fields.font.addEventListener('change', () => { state.font = fields.font.value; render(); });
    fields.size.addEventListener('input', () => { state.size = clamp(Number(fields.size.value), 12, 160); render(); });
    fields.color.addEventListener('input', () => { state.color = fields.color.value.toLowerCase(); render(); });
    fields.hex.addEventListener('change', () => {
        if (/^#[0-9a-f]{6}$/i.test(fields.hex.value)) state.color = fields.hex.value.toLowerCase();
        render();
    });
    $('[data-template-bold]').addEventListener('click', () => { state.weight = state.weight === 'bold' ? 'regular' : 'bold'; render(); });
    $('[data-template-italic]').addEventListener('click', () => { state.style = state.style === 'italic' ? 'regular' : 'italic'; render(); });
    $$('[data-template-align]').forEach((button) => button.addEventListener('click', () => { state.align = button.dataset.templateAlign; render(); }));

    function move(top, left) {
        state.top = clamp(state.top + top, 0, 100); state.left = clamp(state.left + left, 0, 100); render();
    }
    $$('[data-template-nudge]').forEach((button) => button.addEventListener('click', () => {
        const moves = { up: [-0.5, 0], down: [0.5, 0], left: [0, -0.5], right: [0, 0.5] };
        move(...moves[button.dataset.templateNudge]);
    }));
    $('[data-template-reset]').addEventListener('click', () => { state.top = defaults.top; state.left = defaults.left; render(); });

    let dragging = false; let startX = 0; let startY = 0; let startTop = 0; let startLeft = 0;
    nameEl.style.cursor = 'move'; nameEl.style.touchAction = 'none'; nameEl.tabIndex = 0;
    nameEl.setAttribute('role', 'button'); nameEl.setAttribute('aria-label', 'Sample participant name. Drag or use arrow keys to position.');
    nameEl.addEventListener('pointerdown', (event) => {
        dragging = true; startX = event.clientX; startY = event.clientY; startTop = state.top; startLeft = state.left;
        nameEl.setPointerCapture(event.pointerId); nameEl.focus(); event.preventDefault();
    });
    nameEl.addEventListener('pointermove', (event) => {
        if (!dragging) return;
        const rect = canvas.getBoundingClientRect();
        const top = snap(startTop + ((event.clientY - startY) / rect.height) * 100, 50);
        const left = snap(startLeft + ((event.clientX - startX) / rect.width) * 100, 50);
        state.top = clamp(top.value, 0, 100); state.left = clamp(left.value, 0, 100); render();
        $('[data-template-guide-v]').classList.toggle('hidden', !left.snapped);
        $('[data-template-guide-h]').classList.toggle('hidden', !top.snapped);
    });
    const stop = (event) => {
        dragging = false; $('[data-template-guide-v]').classList.add('hidden'); $('[data-template-guide-h]').classList.add('hidden');
        try { nameEl.releasePointerCapture(event.pointerId); } catch (_) { /* already released */ }
    };
    nameEl.addEventListener('pointerup', stop); nameEl.addEventListener('pointercancel', stop);
    nameEl.addEventListener('keydown', (event) => {
        const step = event.shiftKey ? 2 : 0.5;
        const moves = { ArrowUp: [-step, 0], ArrowDown: [step, 0], ArrowLeft: [0, -step], ArrowRight: [0, step] };
        if (moves[event.key]) { move(...moves[event.key]); event.preventDefault(); }
    });
    render();
}

export function initCertificateEditor(root = document) {
    const studio = root.querySelector('[data-studio]');
    if (!studio) return;

    const $ = (selector) => root.querySelector(selector);
    const $$ = (selector) => Array.from(root.querySelectorAll(selector));
    const sendForm = $('[data-send-form]');
    const nameEl = $('[data-cert-name]');
    const canvas = $('[data-cert-canvas]');
    if (!sendForm || !nameEl || !canvas) return;

    const certificateRows = $$('[data-certificate-row]');
    const personRows = $$('[data-person-row]');
    const queueChecks = $$('[data-queue-check]');
    const sendChecks = $$('[data-send-participant]');
    const names = {};
    $$('[data-name-store]').forEach((store) => { names[store.dataset.nameStore] = (store.dataset.value || '').trim() || 'Participant'; });

    const hidden = {
        top: $('[data-design-top]'), left: $('[data-design-left]'), font: $('[data-design-font-store]'),
        size: $('[data-design-size-store]'), color: $('[data-design-color-store]'),
        weight: $('[data-design-weight-store]'), style: $('[data-design-style-store]'), align: $('[data-design-align-store]'),
    };
    const controls = {
        font: $('[data-design-font]'), size: $('[data-design-size]'), color: $('[data-design-color]'),
        hex: $('[data-design-hex]'), sizeReadout: $('[data-size-readout]'), bold: $('[data-toggle-bold]'),
        italic: $('[data-toggle-italic]'), align: $$('[data-align]'),
    };

    const defaultDesign = {
        name_top: Number(hidden.top?.value || 62), name_left: Number(hidden.left?.value || 50),
        name_font_family: hidden.font?.value || 'sans', name_font_size: Number(hidden.size?.value || 42),
        accent: hidden.color?.value || '#1d4ed8', name_font_weight: hidden.weight?.value || 'bold',
        name_font_style: hidden.style?.value || 'regular', name_text_align: hidden.align?.value || 'center',
    };
    const designs = {};
    certificateRows.forEach((row) => { designs[row.dataset.certificateRow] = { ...defaultDesign }; });
    let activeId = studio.dataset.initialParticipant || certificateRows[0]?.dataset.certificateRow || null;
    let zoom = 1;

    const selectedIds = () => queueChecks.filter((input) => input.checked && !input.disabled).map((input) => input.dataset.queueCheck);
    const designFor = (id) => designs[id] || { ...defaultDesign };

    function writeDesign(patch, updateUi = true) {
        if (activeId) designs[activeId] = { ...designFor(activeId), ...patch };
        if (updateUi) renderDesign(designFor(activeId));
    }

    function renderDesign(design) {
        if (!design) return;
        nameEl.style.top = `${design.name_top}%`;
        nameEl.style.left = `${design.name_left}%`;
        nameEl.style.fontSize = `calc(${design.name_font_size} / 595 * 100cqh)`;
        nameEl.style.color = design.accent;
        nameEl.style.fontWeight = design.name_font_weight === 'bold' ? '700' : '400';
        nameEl.style.fontStyle = design.name_font_style === 'italic' ? 'italic' : 'normal';
        nameEl.style.textAlign = design.name_text_align;
        const option = Array.from(controls.font?.options || []).find((candidate) => candidate.value === design.name_font_family);
        nameEl.style.fontFamily = option?.dataset.css || '';

        if (controls.font) controls.font.value = design.name_font_family;
        if (controls.size) controls.size.value = String(design.name_font_size);
        if (controls.sizeReadout) controls.sizeReadout.textContent = String(Math.round(design.name_font_size));
        if (controls.color) controls.color.value = design.accent;
        if (controls.hex) controls.hex.value = design.accent.toUpperCase();
        controls.bold?.classList.toggle('is-active', design.name_font_weight === 'bold');
        controls.italic?.classList.toggle('is-active', design.name_font_style === 'italic');
        controls.align.forEach((button) => button.classList.toggle('is-active', button.dataset.align === design.name_text_align));

        if (hidden.top) hidden.top.value = design.name_top.toFixed(2);
        if (hidden.left) hidden.left.value = design.name_left.toFixed(2);
        if (hidden.font) hidden.font.value = design.name_font_family;
        if (hidden.size) hidden.size.value = String(design.name_font_size);
        if (hidden.color) hidden.color.value = design.accent;
        if (hidden.weight) hidden.weight.value = design.name_font_weight;
        if (hidden.style) hidden.style.value = design.name_font_style;
        if (hidden.align) hidden.align.value = design.name_text_align;
    }

    function setActive(id) {
        if (!id || !designs[id]) return;
        activeId = id;
        nameEl.textContent = names[id] || 'Participant';
        certificateRows.forEach((row) => row.classList.toggle('is-active', row.dataset.certificateRow === id));
        personRows.forEach((row) => row.classList.toggle('is-active', row.dataset.personRow === id));
        renderDesign(designFor(id));
        const visibleRows = certificateRows.filter((row) => !row.hidden);
        const index = visibleRows.findIndex((row) => row.dataset.certificateRow === id);
        $$('[data-active-index]').forEach((el) => { el.textContent = String(Math.max(0, index) + 1); });
        const previewLink = $('[data-preview-pdf]');
        if (previewLink) previewLink.href = `${studio.dataset.previewUrl}?name=${encodeURIComponent(names[id] || 'Participant')}`;
        const adjustmentName = $('[data-adjustment-name]');
        if (adjustmentName) adjustmentName.textContent = names[id] || 'Participant';
        const correctionButton = $('[data-open-name-correction]');
        const certificateRow = certificateRows.find((row) => row.dataset.certificateRow === id);
        if (correctionButton) correctionButton.classList.toggle('hidden', certificateRow?.dataset.status !== 'ready');
        personRows.find((row) => row.dataset.personRow === id)?.scrollIntoView({ block: 'nearest' });
        certificateRows.find((row) => row.dataset.certificateRow === id)?.scrollIntoView({ block: 'nearest' });
    }

    $$('[data-preview-select]').forEach((button) => button.addEventListener('click', () => setActive(button.dataset.previewSelect)));

    const adjustment = $('[data-name-adjustment]');
    $('[data-toggle-name-adjustment]')?.addEventListener('click', () => adjustment?.classList.toggle('hidden'));
    $('[data-close-name-adjustment]')?.addEventListener('click', () => adjustment?.classList.add('hidden'));

    const correctionModal = $('[data-name-correction-modal]');
    const correctionForm = $('[data-name-correction-form]');
    const correctionInput = $('[data-name-correction-input]');
    const correctionError = $('[data-name-correction-error]');
    function closeNameCorrection() {
        correctionModal?.classList.add('hidden'); correctionModal?.classList.remove('flex'); correctionModal?.setAttribute('hidden', '');
    }
    $('[data-open-name-correction]')?.addEventListener('click', () => {
        const row = certificateRows.find((candidate) => candidate.dataset.certificateRow === activeId);
        if (!row || row.dataset.status !== 'ready') return;
        correctionForm.action = row.dataset.nameUpdateUrl;
        correctionInput.value = names[activeId] || '';
        correctionError?.classList.add('hidden');
        correctionModal?.classList.remove('hidden'); correctionModal?.classList.add('flex'); correctionModal?.removeAttribute('hidden');
        correctionInput.focus(); correctionInput.select();
    });
    $('[data-cancel-name-correction]')?.addEventListener('click', closeNameCorrection);
    correctionModal?.addEventListener('click', (event) => { if (event.target === correctionModal) closeNameCorrection(); });
    correctionForm?.addEventListener('submit', async (event) => {
        event.preventDefault();
        correctionError?.classList.add('hidden');
        try {
            const response = await fetch(correctionForm.action, {
                method: 'POST', body: new FormData(correctionForm), credentials: 'same-origin', headers: { Accept: 'application/json' },
            });
            const body = await response.json();
            if (!response.ok) throw new Error(body.message || body.errors?.full_name?.[0] || 'The name could not be updated.');
            names[activeId] = body.full_name;
            const store = $(`[data-name-store="${activeId}"]`); if (store) store.dataset.value = body.full_name;
            const cert = certificateRows.find((row) => row.dataset.certificateRow === activeId);
            const person = personRows.find((row) => row.dataset.personRow === activeId);
            [cert, person].forEach((row) => row?.querySelectorAll('[data-participant-name]').forEach((label) => { label.textContent = body.full_name; }));
            setActive(activeId); closeNameCorrection();
            window.notify?.('success', 'Participant name corrected. The certificate preview has been refreshed.', { title: 'Name updated' });
        } catch (error) {
            if (correctionError) { correctionError.textContent = error.message; correctionError.classList.remove('hidden'); }
        }
    });

    function syncSelection() {
        queueChecks.forEach((queueCheck) => {
            const sendCheck = sendChecks.find((input) => input.dataset.sendParticipant === queueCheck.dataset.queueCheck);
            if (sendCheck) sendCheck.checked = queueCheck.checked;
        });
        const count = selectedIds().length;
        $$('[data-selected-count]').forEach((el) => { el.textContent = String(count); });
        const sendButton = $('[data-open-send-confirm]');
        if (sendButton) sendButton.disabled = count === 0;
        const sendLabel = $('[data-send-label]');
        if (sendLabel) sendLabel.textContent = count === 1 ? 'Send certificate' : 'Send certificates';
    }
    queueChecks.forEach((input) => input.addEventListener('change', syncSelection));
    $('[data-select-all]')?.addEventListener('change', (event) => {
        queueChecks.forEach((input) => { if (!input.disabled) input.checked = event.target.checked; });
        syncSelection();
    });

    controls.font?.addEventListener('change', () => writeDesign({ name_font_family: controls.font.value }));
    controls.size?.addEventListener('input', () => writeDesign({ name_font_size: clamp(Number(controls.size.value), 12, 160) }));
    controls.color?.addEventListener('input', () => writeDesign({ accent: controls.color.value.toLowerCase() }));
    controls.hex?.addEventListener('change', () => {
        if (/^#[0-9a-f]{6}$/i.test(controls.hex.value)) writeDesign({ accent: controls.hex.value.toLowerCase() });
        else controls.hex.value = designFor(activeId).accent.toUpperCase();
    });
    controls.bold?.addEventListener('click', () => writeDesign({ name_font_weight: designFor(activeId).name_font_weight === 'bold' ? 'regular' : 'bold' }));
    controls.italic?.addEventListener('click', () => writeDesign({ name_font_style: designFor(activeId).name_font_style === 'italic' ? 'regular' : 'italic' }));
    controls.align.forEach((button) => button.addEventListener('click', () => writeDesign({ name_text_align: button.dataset.align })));

    function move(topDelta, leftDelta) {
        const design = designFor(activeId);
        writeDesign({ name_top: clamp(design.name_top + topDelta, 0, 100), name_left: clamp(design.name_left + leftDelta, 0, 100) });
    }
    $$('[data-nudge]').forEach((button) => button.addEventListener('click', () => {
        const moves = { up: [-0.5, 0], down: [0.5, 0], left: [0, -0.5], right: [0, 0.5] };
        move(...moves[button.dataset.nudge]);
    }));
    $('[data-reset-position]')?.addEventListener('click', () => writeDesign({ name_top: defaultDesign.name_top, name_left: defaultDesign.name_left }));

    // Direct manipulation stays inside the certificate and snaps to both centre guides.
    let dragging = false; let startX = 0; let startY = 0; let startTop = 0; let startLeft = 0;
    nameEl.style.cursor = 'move'; nameEl.style.touchAction = 'none'; nameEl.tabIndex = 0;
    nameEl.setAttribute('role', 'button'); nameEl.setAttribute('aria-label', 'Participant name. Drag or use arrow keys to position.');
    nameEl.addEventListener('pointerdown', (event) => {
        dragging = true; startX = event.clientX; startY = event.clientY;
        startTop = designFor(activeId).name_top; startLeft = designFor(activeId).name_left;
        nameEl.setPointerCapture(event.pointerId); nameEl.focus(); event.preventDefault();
    });
    nameEl.addEventListener('pointermove', (event) => {
        if (!dragging) return;
        const rect = canvas.getBoundingClientRect();
        const top = snap(startTop + ((event.clientY - startY) / rect.height) * 100, 50);
        const left = snap(startLeft + ((event.clientX - startX) / rect.width) * 100, 50);
        writeDesign({ name_top: clamp(top.value, 0, 100), name_left: clamp(left.value, 0, 100) });
        $('[data-guide-v]')?.classList.toggle('hidden', !left.snapped);
        $('[data-guide-h]')?.classList.toggle('hidden', !top.snapped);
    });
    const stopDrag = (event) => {
        dragging = false; $('[data-guide-v]')?.classList.add('hidden'); $('[data-guide-h]')?.classList.add('hidden');
        try { nameEl.releasePointerCapture(event.pointerId); } catch (_) { /* already released */ }
    };
    nameEl.addEventListener('pointerup', stopDrag); nameEl.addEventListener('pointercancel', stopDrag);
    nameEl.addEventListener('keydown', (event) => {
        const step = event.shiftKey ? 2 : 0.5;
        const directions = { ArrowUp: [-step, 0], ArrowDown: [step, 0], ArrowLeft: [0, -step], ArrowRight: [0, step] };
        if (directions[event.key]) { move(...directions[event.key]); event.preventDefault(); }
    });

    function stepCertificate(delta) {
        const visible = certificateRows.filter((row) => !row.hidden);
        let index = visible.findIndex((row) => row.dataset.certificateRow === activeId);
        index = (index + delta + visible.length) % visible.length;
        if (visible[index]) setActive(visible[index].dataset.certificateRow);
    }
    $$('[data-prev-certificate]').forEach((button) => button.addEventListener('click', () => stepCertificate(-1)));
    $$('[data-next-certificate]').forEach((button) => button.addEventListener('click', () => stepCertificate(1)));

    function renderZoom() {
        const scale = $('[data-preview-scale]');
        if (scale) scale.style.transform = `scale(${zoom})`;
        if ($('[data-zoom-readout]')) $('[data-zoom-readout]').textContent = `${Math.round(zoom * 100)}%`;
    }
    $('[data-zoom-in]')?.addEventListener('click', () => { zoom = clamp(zoom + 0.1, 0.4, 2); renderZoom(); });
    $('[data-zoom-out]')?.addEventListener('click', () => { zoom = clamp(zoom - 0.1, 0.4, 2); renderZoom(); });
    $('[data-fit-screen]')?.addEventListener('click', () => { zoom = 0.85; renderZoom(); });
    $('[data-actual-size]')?.addEventListener('click', () => { zoom = 1; renderZoom(); });

    function filterCertificates() {
        const query = ($('[data-certificate-search]')?.value || '').trim().toLowerCase();
        certificateRows.forEach((row) => { row.hidden = !row.dataset.search.includes(query); });
        const visible = certificateRows.filter((row) => !row.hidden);
        if ($('[data-visible-certificate-count]')) $('[data-visible-certificate-count]').textContent = String(visible.length);
        $$('[data-certificate-total]').forEach((el) => { el.textContent = String(visible.length); });
    }
    $('[data-certificate-search]')?.addEventListener('input', filterCertificates);
    let peopleStatus = 'all';
    function filterPeople() {
        const query = ($('[data-people-search]')?.value || '').trim().toLowerCase();
        personRows.forEach((row) => { row.hidden = !row.dataset.search.includes(query) || (peopleStatus !== 'all' && row.dataset.status !== peopleStatus); });
    }
    $('[data-people-search]')?.addEventListener('input', filterPeople);
    $$('[data-status-filter]').forEach((button) => button.addEventListener('click', () => {
        peopleStatus = button.dataset.statusFilter;
        $$('[data-status-filter]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
        filterPeople();
    }));

    // Mobile panels collapse into mutually exclusive drawers.
    const scrim = $('[data-panel-scrim]');
    function closePanels() { $$('[data-panel]').forEach((panel) => panel.classList.remove('is-open')); scrim?.classList.add('hidden'); }
    $$('[data-toggle-panel]').forEach((button) => button.addEventListener('click', () => {
        const panel = $(`[data-panel="${button.dataset.togglePanel}"]`); const opening = !panel?.classList.contains('is-open');
        closePanels(); if (opening) { panel?.classList.add('is-open'); scrim?.classList.remove('hidden'); }
    }));
    scrim?.addEventListener('click', closePanels);

    // Explicit send confirmation with counts; the browser request only queues after acceptance.
    const confirmation = $('[data-send-confirm]');
    $('[data-open-send-confirm]')?.addEventListener('click', () => {
        const count = selectedIds().length;
        if (!count) return;
        $('[data-confirm-count]').textContent = `${count} ${count === 1 ? 'participant' : 'participants'}`;
        $('[data-confirm-total]').textContent = String(count);
        $('[data-confirm-valid]').textContent = String(count);
        $('[data-confirm-skipped]').textContent = '0';
        confirmation?.classList.remove('hidden'); confirmation?.classList.add('flex'); confirmation?.removeAttribute('hidden');
    });
    function closeConfirmation() { confirmation?.classList.add('hidden'); confirmation?.classList.remove('flex'); confirmation?.setAttribute('hidden', ''); }
    $('[data-cancel-send]')?.addEventListener('click', closeConfirmation);
    confirmation?.addEventListener('click', (event) => { if (event.target === confirmation) closeConfirmation(); });
    $('[data-confirm-send]')?.addEventListener('click', () => {
        const selected = selectedIds(); const payload = {};
        selected.forEach((id) => { payload[id] = designFor(id); });
        $('[data-designs-json]').value = JSON.stringify(payload);
        closeConfirmation(); sendForm.requestSubmit();
    });

    $('[data-studio-back]')?.addEventListener('click', (event) => {
        if (document.referrer && new URL(document.referrer).origin === window.location.origin && window.history.length > 1) {
            event.preventDefault(); window.history.back();
        }
    });

    function updateStatus(item) {
        const cert = certificateRows.find((row) => row.dataset.certificateRow === item.participant_id);
        const person = personRows.find((row) => row.dataset.personRow === item.participant_id);
        [cert, person].forEach((row) => {
            if (!row) return; row.dataset.status = item.status;
            const badge = row.querySelector('[data-status-badge]');
            if (badge) {
                badge.className = `certificate-status certificate-status-${item.status}`;
                badge.textContent = item.status_label || item.status;
            }
            const detail = row.querySelector('[data-status-detail]');
            if (detail && item.status_detail) detail.textContent = item.status_detail;
        });
        const number = cert?.querySelector('[data-certificate-number]'); if (number && item.certificate_number) number.textContent = item.certificate_number;
        const failure = person?.querySelector('[data-failure-detail]'); failure?.classList.toggle('hidden', item.status !== 'failed');
        const reason = failure?.querySelector('[data-failure-reason]'); if (reason && item.failed_reason) reason.textContent = item.failed_reason;
    }
    async function pollStatus() {
        if (document.hidden || !studio.dataset.statusUrl) return;
        try {
            const response = await fetch(studio.dataset.statusUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (!response.ok) return;
            const body = await response.json(); body.certificates.forEach(updateStatus);
            const statuses = certificateRows.map((row) => row.dataset.status);
            if ($('[data-summary-sent]')) $('[data-summary-sent]').textContent = String(statuses.filter((s) => s === 'sent').length);
            if ($('[data-summary-failed]')) $('[data-summary-failed]').textContent = String(statuses.filter((s) => s === 'failed').length);
            if ($('[data-summary-pending]')) $('[data-summary-pending]').textContent = String(statuses.filter((s) => ['ready', 'queued', 'sending'].includes(s)).length);
        } catch (_) { /* A later poll can recover from a transient network error. */ }
    }
    window.setInterval(pollStatus, 5000);

    renderDesign(designFor(activeId)); setActive(activeId); syncSelection();
}
