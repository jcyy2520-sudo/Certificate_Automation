import { describe, expect, it } from 'vitest';
import { initResponseTableControls } from '../../resources/js/response-table-controls.js';

// Node's real EventTarget/AbortSignal exercise the controller's browser boundary.
// Only element lookup is supplied here; layout/keyboard semantics stay in the
// synthetic browser workflow rather than a new DOM dependency for unit tests.
class NodeFixture extends EventTarget {
    constructor(properties = {}) {
        super();
        Object.assign(this, { hidden: false, checked: true, value: '', textContent: '', dataset: {} }, properties);
        this.childrenBySelector = new Map();
    }

    querySelectorAll(selector) { return this.childrenBySelector.get(selector) || []; }
    querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
    with(selector, ...nodes) { this.childrenBySelector.set(selector, nodes); return this; }
}

function workspaceFixture() {
    const choices = [
        new NodeFixture({ value: 'native:18' }),
        new NodeFixture({ value: 'source-a.b[0]"last' }),
    ];
    const options = [
        new NodeFixture({ textContent: 'Learning GROWTH\ngoals' }).with('[data-question-toggle]', choices[0]),
        new NodeFixture({ textContent: '  Final feedback / 日本語  ' }).with('[data-question-toggle]', choices[1]),
    ];
    const cells = [
        new NodeFixture({ dataset: { questionColumn: 'native:18' }, textContent: 'Learning GROWTH\ngoals' }),
        new NodeFixture({ dataset: { questionColumn: 'source-a.b[0]"last' }, textContent: '  Final feedback / 日本語  ' }),
        new NodeFixture({ dataset: { questionColumn: 'native:18', label: 'Learning GROWTH\ngoals' }, textContent: '  Original answer\nsecond line  ' }),
        new NodeFixture({ dataset: { questionColumn: 'source-a.b[0]"last', label: '  Final feedback / 日本語  ' }, textContent: '0 / false / café / e\u0301 / 🙂' }),
    ];
    const identity = new NodeFixture({ textContent: 'Synthetic participant' });
    const details = new NodeFixture({ textContent: 'Inspect submission' });
    const count = new NodeFixture();
    const search = new NodeFixture();
    const empty = new NodeFixture({ hidden: true });
    const density = new NodeFixture({ value: 'comfortable' });
    const reset = new NodeFixture();
    const controls = new NodeFixture({ hidden: true })
        .with('[data-question-option]', ...options)
        .with('[data-question-toggle]', ...choices)
        .with('[data-question-count]', count)
        .with('[data-question-search]', search)
        .with('[data-question-empty]', empty)
        .with('[data-response-density-control]', density)
        .with('[data-response-reset]', reset);
    const workspace = new NodeFixture()
        .with('[data-response-table-controls]', controls)
        .with('[data-question-column]', ...cells);
    const root = new NodeFixture().with('[data-response-workspace]', workspace);
    return { root, workspace, controls, choices, options, cells, identity, details, count, search, empty, density, reset };
}

function change(node, value) {
    node.checked = value;
    node.dispatchEvent(new Event('change'));
}

function searchFor(fixture, value) {
    fixture.search.value = value;
    fixture.search.dispatchEvent(new Event('input'));
}

describe('optional response table controls', () => {
    it('starts every rendered page with all questions shown and comfortable density', () => {
        const fixture = workspaceFixture();
        fixture.choices[0].checked = false;
        fixture.cells[0].hidden = true;
        fixture.density.value = 'compact';
        fixture.workspace.dataset.responseDensity = 'compact';
        fixture.search.value = 'a restored browser field';

        initResponseTableControls(fixture.root, new AbortController().signal);

        expect(fixture.controls.hidden).toBe(false);
        expect(fixture.choices.map(choice => choice.checked)).toEqual([true, true]);
        expect(fixture.cells.map(cell => cell.hidden)).toEqual([false, false, false, false]);
        expect(fixture.count.textContent).toContain('2 of 2');
        expect(fixture.density.value).toBe('comfortable');
        expect(fixture.workspace.dataset.responseDensity).toBe('comfortable');
        expect(fixture.search.value).toBe('');
    });

    it('hides the first and last question together with their own cells without changing any exact text or label', () => {
        const fixture = workspaceFixture();
        const exactCells = fixture.cells.map(cell => [cell.textContent, cell.dataset.label]);
        initResponseTableControls(fixture.root, new AbortController().signal);

        change(fixture.choices[0], false);
        expect(fixture.cells.map(cell => cell.hidden)).toEqual([true, false, true, false]);
        expect(fixture.count.textContent).toContain('1 of 2');
        change(fixture.choices[1], false);
        expect(fixture.cells.map(cell => cell.hidden)).toEqual([true, true, true, true]);
        expect(fixture.count.textContent).toContain('0 of 2');
        change(fixture.choices[0], true);
        expect(fixture.cells.map(cell => cell.hidden)).toEqual([false, true, false, true]);
        expect(fixture.identity.hidden).toBe(false);
        expect(fixture.details.hidden).toBe(false);
        expect(fixture.cells.map(cell => [cell.textContent, cell.dataset.label])).toEqual(exactCells);
    });

    it('searches headings only and explains no matches without hiding answers or changing selections', () => {
        const fixture = workspaceFixture();
        initResponseTableControls(fixture.root, new AbortController().signal);
        change(fixture.choices[1], false);

        searchFor(fixture, '  growth  ');
        expect(fixture.options.map(option => option.hidden)).toEqual([false, true]);
        expect(fixture.cells.map(cell => cell.hidden)).toEqual([false, true, false, true]);
        expect(fixture.empty.hidden).toBe(true);
        searchFor(fixture, 'Original answer');
        expect(fixture.options.map(option => option.hidden)).toEqual([true, true]);
        expect(fixture.empty.hidden).toBe(false);
        expect(fixture.count.textContent).toContain('1 of 2');
        expect(fixture.choices.map(choice => choice.checked)).toEqual([true, false]);
        searchFor(fixture, '日本語');
        expect(fixture.options.map(option => option.hidden)).toEqual([true, false]);
        searchFor(fixture, '');
        expect(fixture.options.map(option => option.hidden)).toEqual([false, false]);
        expect(fixture.empty.hidden).toBe(true);
    });

    it('resets search, question visibility and compact spacing together', () => {
        const fixture = workspaceFixture();
        initResponseTableControls(fixture.root, new AbortController().signal);
        change(fixture.choices[0], false);
        searchFor(fixture, 'no matching heading');
        fixture.density.value = 'compact';
        fixture.density.dispatchEvent(new Event('change'));
        expect(fixture.workspace.dataset.responseDensity).toBe('compact');

        fixture.reset.dispatchEvent(new Event('click'));

        expect(fixture.choices.map(choice => choice.checked)).toEqual([true, true]);
        expect(fixture.cells.map(cell => cell.hidden)).toEqual([false, false, false, false]);
        expect(fixture.options.map(option => option.hidden)).toEqual([false, false]);
        expect(fixture.count.textContent).toContain('2 of 2');
        expect(fixture.density.value).toBe('comfortable');
        expect(fixture.workspace.dataset.responseDensity).toBe('comfortable');
        expect(fixture.search.value).toBe('');
        expect(fixture.empty.hidden).toBe(true);
    });

    it('scopes identical question keys to their workspace and stops old-page listeners on navigation', () => {
        const first = workspaceFixture();
        const second = workspaceFixture();
        first.root.with('[data-response-workspace]', first.workspace, second.workspace);
        const page = new AbortController();
        initResponseTableControls(first.root, page.signal);

        change(first.choices[0], false);
        expect(second.cells.map(cell => cell.hidden)).toEqual([false, false, false, false]);
        page.abort();
        first.reset.dispatchEvent(new Event('click'));
        change(first.choices[1], false);
        searchFor(first, 'nothing');
        first.density.value = 'compact';
        first.density.dispatchEvent(new Event('change'));

        expect(first.cells.map(cell => cell.hidden)).toEqual([true, false, true, false]);
        expect(first.count.textContent).toContain('1 of 2');
        expect(first.options.map(option => option.hidden)).toEqual([false, false]);
        expect(first.workspace.dataset.responseDensity).toBe('comfortable');
    });

    it('supports a table with no question columns and an empty non-response page', () => {
        const fixture = workspaceFixture();
        fixture.controls.with('[data-question-toggle]').with('[data-question-option]');
        fixture.workspace.with('[data-question-column]');

        expect(() => initResponseTableControls(new NodeFixture(), new AbortController().signal)).not.toThrow();
        initResponseTableControls(fixture.root, new AbortController().signal);

        expect(fixture.count.textContent).toContain('0 of 0');
        expect(fixture.identity.hidden).toBe(false);
        expect(fixture.details.hidden).toBe(false);
    });
});
