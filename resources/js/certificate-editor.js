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

    // One row in the left rail is both the certificate and the person, so these
    // two lists address the same elements. They stay separate because status
    // polling and selection each speak in their own terms.
    const certificateRows = $$('[data-certificate-row]');
    const personRows = $$('[data-person-row]');
    const queueChecks = $$('[data-queue-check]');
    const sendChecks = $$('[data-send-participant]');
    const sentRows = $$('[data-sent-row]');
    const names = {};
    const emails = {};
    $$('[data-name-store]').forEach((store) => { names[store.dataset.nameStore] = (store.dataset.value || '').trim() || 'Participant'; });
    $$('[data-email-store]').forEach((store) => { emails[store.dataset.emailStore] = (store.dataset.value || '').trim(); });

    const hidden = {
        top: $('[data-design-top]'), left: $('[data-design-left]'), font: $('[data-design-font-store]'),
        size: $('[data-design-size-store]'), color: $('[data-design-color-store]'),
        weight: $('[data-design-weight-store]'), style: $('[data-design-style-store]'), align: $('[data-design-align-store]'),
    };
    const controls = {
        font: $('[data-design-font]'), sizeNumber: $('[data-design-size-number]'),
        color: $('[data-design-color]'), hex: $('[data-design-hex]'),
        weight: $('[data-design-weight]'), bold: $('[data-toggle-bold]'), italic: $('[data-toggle-italic]'),
        topInput: $('[data-design-top-input]'), leftInput: $('[data-design-left-input]'),
        align: $$('[data-align]'),
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

    /* ---- Undo / redo ------------------------------------------------
     * Snapshots are taken before a change lands. Continuous controls (the
     * slider, the colour picker, a drag) record one entry per gesture rather
     * than one per pixel, so a single undo reverses what the eye saw as a
     * single action. */
    const undoStack = [];
    const redoStack = [];
    let gesture = null;

    function renderHistoryButtons() {
        const undoButton = $('[data-undo]');
        const redoButton = $('[data-redo]');
        if (undoButton) undoButton.disabled = undoStack.length === 0;
        if (redoButton) redoButton.disabled = redoStack.length === 0;
    }

    function pushHistory() {
        if (!activeId) return;
        undoStack.push({ id: activeId, design: { ...designFor(activeId) } });
        if (undoStack.length > 50) undoStack.shift();
        redoStack.length = 0;
        renderHistoryButtons();
    }

    /** Record one entry for a continuous gesture, not one per event. */
    function beginGesture(key) {
        if (gesture === key) return;
        gesture = key;
        pushHistory();
    }
    const endGesture = () => { gesture = null; };

    function restore(from, to) {
        const entry = from.pop();
        if (!entry) return;
        to.push({ id: entry.id, design: { ...designFor(entry.id) } });
        designs[entry.id] = { ...entry.design };
        if (entry.id === activeId) renderDesign(designs[entry.id]);
        else setActive(entry.id);
        renderHistoryButtons();
    }
    $('[data-undo]')?.addEventListener('click', () => restore(undoStack, redoStack));
    $('[data-redo]')?.addEventListener('click', () => restore(redoStack, undoStack));

    function writeDesign(patch, updateUi = true) {
        if (activeId) designs[activeId] = { ...designFor(activeId), ...patch };
        if (updateUi) renderDesign(designFor(activeId));
    }

    /** Whether this recipient still carries the template's own style. */
    function matchesTemplate(design) {
        return Object.keys(defaultDesign).every((key) => String(design[key]) === String(defaultDesign[key]));
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
        if (controls.sizeNumber && document.activeElement !== controls.sizeNumber) controls.sizeNumber.value = String(Math.round(design.name_font_size));
        if (controls.color) controls.color.value = design.accent;
        if (controls.hex && document.activeElement !== controls.hex) controls.hex.value = design.accent.toUpperCase();
        if (controls.weight) controls.weight.value = design.name_font_weight;
        // Typing in a position field must not have its own value rewritten underneath.
        if (controls.topInput && document.activeElement !== controls.topInput) controls.topInput.value = String(Number(design.name_top.toFixed(1)));
        if (controls.leftInput && document.activeElement !== controls.leftInput) controls.leftInput.value = String(Number(design.name_left.toFixed(1)));
        controls.bold?.classList.toggle('is-active', design.name_font_weight === 'bold');
        controls.italic?.classList.toggle('is-active', design.name_font_style === 'italic');
        controls.align.forEach((button) => button.classList.toggle('is-active', button.dataset.align === design.name_text_align));

        const adjusted = !matchesTemplate(design);
        const label = $('[data-adjust-label]');
        const dot = $('[data-adjust-dot]');
        if (label) label.textContent = adjusted ? 'Adjusted for this recipient' : 'Template style';
        if (dot) dot.className = `size-2 shrink-0 rounded-full ${adjusted ? 'bg-accent-600' : 'bg-slate-300'}`;

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
        $$('[data-adjustment-name]').forEach((el) => { el.textContent = names[id] || 'Participant'; });
        $$('[data-adjustment-email]').forEach((el) => {
            el.textContent = emails[id] || 'No email address';
            el.title = emails[id] || 'This recipient has no email address yet';
        });
        // Correcting a name only affects certificates that have not been issued.
        const certificateRow = certificateRows.find((row) => row.dataset.certificateRow === id);
        const correctable = certificateRow?.dataset.status === 'ready';
        $$('[data-open-name-correction]').forEach((button) => {
            button.disabled = !correctable;
            button.title = correctable ? 'Correct name' : 'This certificate has already been issued with its current name.';
        });
        certificateRow?.scrollIntoView({ block: 'nearest' });
    }

    $$('[data-preview-select]').forEach((button) => button.addEventListener('click', () => setActive(button.dataset.previewSelect)));

    const correctionModal = $('[data-name-correction-modal]');
    const correctionForm = $('[data-name-correction-form]');
    const correctionInput = $('[data-name-correction-input]');
    const correctionError = $('[data-name-correction-error]');
    function closeNameCorrection() {
        correctionModal?.classList.add('hidden'); correctionModal?.classList.remove('flex'); correctionModal?.setAttribute('hidden', '');
    }
    $$('[data-open-name-correction]').forEach((button) => button.addEventListener('click', () => {
        const row = certificateRows.find((candidate) => candidate.dataset.certificateRow === activeId);
        if (!row || row.dataset.status !== 'ready') return;
        correctionForm.action = row.dataset.nameUpdateUrl;
        correctionInput.value = names[activeId] || '';
        correctionError?.classList.add('hidden');
        correctionModal?.classList.remove('hidden'); correctionModal?.classList.add('flex'); correctionModal?.removeAttribute('hidden');
        correctionInput.focus(); correctionInput.select();
    }));
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

    /* ---- Text controls ---------------------------------------------- */
    controls.font?.addEventListener('change', () => { pushHistory(); writeDesign({ name_font_family: controls.font.value }); });
    controls.weight?.addEventListener('change', () => { pushHistory(); writeDesign({ name_font_weight: controls.weight.value }); });
    controls.sizeNumber?.addEventListener('change', () => {
        pushHistory();
        writeDesign({ name_font_size: clamp(Number(controls.sizeNumber.value) || defaultDesign.name_font_size, 12, 160) });
    });
    controls.color?.addEventListener('input', () => {
        beginGesture('color');
        writeDesign({ accent: controls.color.value.toLowerCase() });
    });
    controls.color?.addEventListener('change', endGesture);
    controls.hex?.addEventListener('change', () => {
        if (/^#[0-9a-f]{6}$/i.test(controls.hex.value)) { pushHistory(); writeDesign({ accent: controls.hex.value.toLowerCase() }); }
        else controls.hex.value = designFor(activeId).accent.toUpperCase();
    });
    controls.bold?.addEventListener('click', () => { pushHistory(); writeDesign({ name_font_weight: designFor(activeId).name_font_weight === 'bold' ? 'regular' : 'bold' }); });
    controls.italic?.addEventListener('click', () => { pushHistory(); writeDesign({ name_font_style: designFor(activeId).name_font_style === 'italic' ? 'regular' : 'italic' }); });
    controls.align.forEach((button) => button.addEventListener('click', () => { pushHistory(); writeDesign({ name_text_align: button.dataset.align }); }));

    const readPosition = (input, fallback) => clamp(Number(input.value.replace(',', '.')) || fallback, 0, 100);
    controls.topInput?.addEventListener('change', () => { pushHistory(); writeDesign({ name_top: readPosition(controls.topInput, defaultDesign.name_top) }); });
    controls.leftInput?.addEventListener('change', () => { pushHistory(); writeDesign({ name_left: readPosition(controls.leftInput, defaultDesign.name_left) }); });

    // Placement presets: thirds rather than hard edges, so the name never lands
    // flush against the artwork's trim.
    const alignPresets = {
        left: { name_left: 20, name_text_align: 'left' },
        'center-h': { name_left: 50, name_text_align: 'center' },
        right: { name_left: 80, name_text_align: 'right' },
        top: { name_top: 20 },
        middle: { name_top: 50 },
        bottom: { name_top: 80 },
    };
    $$('[data-align-preset]').forEach((button) => button.addEventListener('click', () => {
        const preset = alignPresets[button.dataset.alignPreset];
        if (!preset) return;
        pushHistory();
        writeDesign(preset);
    }));

    function move(topDelta, leftDelta) {
        const design = designFor(activeId);
        writeDesign({ name_top: clamp(design.name_top + topDelta, 0, 100), name_left: clamp(design.name_left + leftDelta, 0, 100) });
    }
    $('[data-reset-design]')?.addEventListener('click', () => { pushHistory(); writeDesign({ ...defaultDesign }); });

    /* ---- Make the style being edited the webinar-wide template --------
     * Posts through the same audited endpoint as the Template &
     * requirements page, then returns straight back to the studio. */
    const applyAllForm = $('#apply-all-form');
    $('[data-apply-to-all]')?.addEventListener('click', () => {
        if (!applyAllForm || !activeId) return;
        const design = designFor(activeId);
        const set = (selector, value) => {
            const input = applyAllForm.querySelector(selector);
            if (input) input.value = String(value ?? '');
        };
        set('[data-apply-top]', Number(design.name_top).toFixed(2));
        set('[data-apply-left]', Number(design.name_left).toFixed(2));
        set('[data-apply-size]', Math.round(design.name_font_size));
        set('[data-apply-font]', design.name_font_family);
        set('[data-apply-color]', design.accent);
        set('[data-apply-weight]', design.name_font_weight);
        set('[data-apply-style]', design.name_font_style);
        set('[data-apply-align]', design.name_text_align);
        applyAllForm.requestSubmit();
    });

    // Direct manipulation stays inside the certificate and snaps to both centre guides.
    let dragging = false; let startX = 0; let startY = 0; let startTop = 0; let startLeft = 0;
    nameEl.style.cursor = 'move'; nameEl.style.touchAction = 'none'; nameEl.tabIndex = 0;
    nameEl.setAttribute('role', 'button'); nameEl.setAttribute('aria-label', 'Participant name. Drag or use arrow keys to position.');
    nameEl.addEventListener('pointerdown', (event) => {
        dragging = true; startX = event.clientX; startY = event.clientY;
        startTop = designFor(activeId).name_top; startLeft = designFor(activeId).name_left;
        pushHistory();
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
        if (directions[event.key]) { beginGesture('keys'); move(...directions[event.key]); event.preventDefault(); }
    });
    nameEl.addEventListener('keyup', endGesture);

    function stepCertificate(delta) {
        const visible = certificateRows.filter((row) => !row.hidden);
        let index = visible.findIndex((row) => row.dataset.certificateRow === activeId);
        index = (index + delta + visible.length) % visible.length;
        if (visible[index]) setActive(visible[index].dataset.certificateRow);
    }
    $$('[data-prev-certificate]').forEach((button) => button.addEventListener('click', () => stepCertificate(-1)));
    $$('[data-next-certificate]').forEach((button) => button.addEventListener('click', () => stepCertificate(1)));

    /* ---- Zoom -------------------------------------------------------- */
    /** The largest scale at which the whole certificate still fits the viewport. */
    function fitZoom() {
        const viewport = $('[data-preview-viewport]');
        const scaleEl = $('[data-preview-scale]');
        if (!viewport || !scaleEl || !scaleEl.offsetWidth || !scaleEl.offsetHeight) return 1;
        return clamp(Math.min(
            (viewport.clientWidth - 64) / scaleEl.offsetWidth,
            (viewport.clientHeight - 96) / scaleEl.offsetHeight,
        ), 0.25, 2);
    }
    function renderZoom() {
        const scale = $('[data-preview-scale]');
        if (scale) scale.style.transform = `scale(${zoom})`;
        const readout = $('[data-zoom-readout]');
        if (readout) readout.textContent = `${Math.round(zoom * 100)}%`;
        const select = $('[data-zoom-select]');
        // Only reflect an exact preset; a dragged zoom leaves the box on "Fit".
        if (select) {
            const match = Array.from(select.options).find((option) => Math.abs(Number(option.value) - zoom) < 0.001);
            select.value = match ? match.value : 'fit';
        }
    }
    function setZoom(next) { zoom = clamp(next, 0.25, 2); renderZoom(); }
    $('[data-zoom-select]')?.addEventListener('change', (event) => {
        setZoom(event.target.value === 'fit' ? fitZoom() : Number(event.target.value));
    });

    function filterRows() {
        const query = ($('[data-certificate-search]')?.value || '').trim().toLowerCase();
        certificateRows.forEach((row) => { row.hidden = !row.dataset.search.includes(query); });
        sentRows.forEach((row) => { row.hidden = !row.dataset.search.includes(query); });
        // The counter describes whichever list the organizer is looking at.
        const pool = activeTab === 'sent' ? sentRows : certificateRows;
        const visible = pool.filter((row) => !row.hidden);
        const visibleCount = $('[data-visible-certificate-count]');
        if (visibleCount) visibleCount.textContent = String(visible.length);
        $$('[data-certificate-total]').forEach((el) => { el.textContent = String(certificateRows.filter((row) => !row.hidden).length); });
    }
    $('[data-certificate-search]')?.addEventListener('input', filterRows);

    /* ---- To send / Sent tabs ------------------------------------------- */
    let activeTab = 'working';
    function setRecipientTab(next) {
        activeTab = next;
        $$('[data-recipient-tab]').forEach((button) => {
            const on = button.dataset.recipientTab === next;
            button.classList.toggle('is-active', on);
            button.setAttribute('aria-selected', String(on));
        });
        $('[data-tab-panel="working"]')?.classList.toggle('hidden', next !== 'working');
        $('[data-tab-panel="sent"]')?.classList.toggle('hidden', next !== 'sent');
        filterRows();
    }
    $$('[data-recipient-tab]').forEach((button) => button.addEventListener('click', () => setRecipientTab(button.dataset.recipientTab)));

    // Mobile panels collapse into mutually exclusive drawers.
    const scrim = $('[data-panel-scrim]');
    function closePanels() { $$('[data-panel]').forEach((panel) => panel.classList.remove('is-open')); scrim?.classList.add('hidden'); }
    $$('[data-toggle-panel]').forEach((button) => button.addEventListener('click', () => {
        const panel = $(`[data-panel="${button.dataset.togglePanel}"]`); const opening = !panel?.classList.contains('is-open');
        closePanels(); if (opening) { panel?.classList.add('is-open'); scrim?.classList.remove('hidden'); }
    }));
    scrim?.addEventListener('click', closePanels);

    document.addEventListener('keydown', (event) => { if (event.key === 'Escape') closePanels(); });

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

    function updateStatus(item) {
        const cert = certificateRows.find((row) => row.dataset.certificateRow === item.participant_id);
        const person = personRows.find((row) => row.dataset.personRow === item.participant_id);
        const shortLabel = { ready: 'Ready', queued: 'Queued', sending: 'Sending', sent: 'Sent', failed: 'Failed', missing_email: 'No email', not_sent: '—' }[item.status] || item.status;
        [cert, person].forEach((row) => {
            if (!row) return; row.dataset.status = item.status;
            const label = item.status_label || item.status;
            // The chip is fixed-width, so the full provider wording lives on
            // hover while the short word keeps the row compact.
            const badge = row.querySelector('[data-status-badge]');
            if (badge) { badge.textContent = shortLabel; badge.title = label; }
            row.title = label;
        });
        const number = cert?.querySelector('[data-certificate-number]'); if (number && item.certificate_number) number.textContent = item.certificate_number;
        const failure = person?.querySelector('[data-failure-detail]'); failure?.classList.toggle('hidden', item.status !== 'failed');
        const reason = failure?.querySelector('[data-failure-reason]'); if (reason && item.failed_reason) reason.textContent = item.failed_reason;
    }
    /* ---- Live delivery tracking --------------------------------------- */
    // Polling backs off while nothing changes: 5s while deliveries move,
    // doubling up to 30s once the pipeline is quiet. A 304 from the ETag
    // counts as "quiet" and costs the server nothing.
    let pollDelay = 5000;
    let lastSignature = '';
    let lastEtag = '';
    function schedulePoll() { window.setTimeout(runPoll, pollDelay); }
    async function runPoll() {
        if (!studio.dataset.statusUrl) return;
        if (document.hidden) { schedulePoll(); return; }
        try {
            const headers = { Accept: 'application/json' };
            if (lastEtag) headers['If-None-Match'] = lastEtag;
            const response = await fetch(studio.dataset.statusUrl, { headers, credentials: 'same-origin' });
            if (response.status === 304) {
                pollDelay = Math.min(Math.round(pollDelay * 2), 30000);
                schedulePoll();
                return;
            }
            if (!response.ok) { schedulePoll(); return; }
            const etag = response.headers.get('ETag');
            if (etag) lastEtag = etag;
            const body = await response.json();
            body.certificates.forEach(updateStatus);
            const signature = JSON.stringify(body.certificates.map((item) => [item.participant_id, item.status]));
            const statuses = certificateRows.map((row) => row.dataset.status);
            if ($('[data-summary-sent]')) $('[data-summary-sent]').textContent = String(statuses.filter((s) => s === 'sent').length);
            if ($('[data-summary-failed]')) $('[data-summary-failed]').textContent = String(statuses.filter((s) => s === 'failed').length);
            if ($('[data-summary-pending]')) $('[data-summary-pending]').textContent = String(statuses.filter((s) => ['ready', 'queued', 'sending'].includes(s)).length);
            pollDelay = signature === lastSignature ? Math.min(Math.round(pollDelay * 2), 30000) : 5000;
            lastSignature = signature;
        } catch (_) { /* A later poll can recover from a transient network error. */ }
        schedulePoll();
    }
    schedulePoll();

    renderDesign(designFor(activeId)); setActive(activeId); syncSelection(); renderHistoryButtons(); renderZoom();
}
