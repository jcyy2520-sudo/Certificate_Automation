import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { workflowEnvironment } from '../../playwright.workflow.config.js';

let fixture;
test.beforeAll(() => {
    fixture = JSON.parse(execFileSync('php', ['tests/workflow/ui-refinement-fixture.php'], { env: workflowEnvironment, encoding: 'utf8' }));
});

async function login(page) {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
}

async function fitsPage(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}

async function exactQuestionCells(workspace) {
    return workspace.locator('[data-question-column]').evaluateAll(cells => cells.map(cell => ({
        key: cell.dataset.questionColumn, label: cell.dataset.label || null, text: cell.textContent,
    })));
}

test('optional question controls preserve full answers and fixed cells at phone, tablet and large widths', async ({ page }) => {
    test.setTimeout(90000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);

    for (const width of [320, 390, 768, 3840]) {
        await page.setViewportSize({ width, height: 1000 });
        await page.goto(`/admin/webinars/${fixture.webinar}/responses`);
        const workspace = page.locator('[data-response-workspace]');
        const count = workspace.locator('[data-question-count]');
        const exactBefore = await exactQuestionCells(workspace);
        await expect(count).toHaveText('6 of 6 question columns shown');
        await expect(workspace.getByLabel('Density', { exact: true })).toHaveValue('comfortable');
        await workspace.locator('[data-question-chooser] > summary').click();

        const search = workspace.getByRole('searchbox', { name: 'Search question headings', exact: true });
        await search.fill('learning goals');
        await expect(workspace.locator('[data-question-option]:visible')).toHaveCount(1);
        await expect(workspace.locator('tbody [data-question-column][hidden]')).toHaveCount(0);
        await expect(count).toHaveText('6 of 6 question columns shown');
        await search.fill('no such question heading');
        await expect(workspace.locator('[data-question-empty]')).toBeVisible();
        await expect(workspace.locator('[data-question-option]:visible')).toHaveCount(0);
        await expect(workspace.locator('tbody [data-question-column][hidden]')).toHaveCount(0);
        await search.fill('');

        const first = workspace.getByRole('checkbox', { name: 'Organization', exact: true });
        const last = workspace.getByRole('checkbox', { name: 'Additional comments', exact: true });
        const firstKey = await first.inputValue();
        const lastKey = await last.inputValue();
        await first.uncheck();
        await last.uncheck();
        await expect(count).toHaveText('4 of 6 question columns shown');
        for (const key of [firstKey, lastKey]) {
            const cells = workspace.locator(`[data-question-column="${key}"]`);
            await expect(cells.first()).toHaveAttribute('hidden', '');
            expect(await cells.evaluateAll(nodes => nodes.every(node => getComputedStyle(node).display === 'none'))).toBe(true);
        }
        await expect(workspace.locator('tbody th').first()).toBeVisible();
        await expect(workspace.locator('tbody [data-label="Submitted / source"]').first()).toBeVisible();
        await expect(workspace.locator('tbody [data-label="Status / score"]').first()).toBeVisible();
        await expect(workspace.getByRole('link', { name: 'Inspect submission', exact: true }).first()).toBeVisible();
        const visibleAnswer = workspace.locator('tbody [data-label="Learning goals"]').first();
        const comfortablePadding = await visibleAnswer.evaluate(node => parseFloat(getComputedStyle(node).paddingTop));
        await workspace.getByLabel('Density', { exact: true }).selectOption('compact');
        expect(await visibleAnswer.evaluate(node => parseFloat(getComputedStyle(node).paddingTop))).toBeLessThan(comfortablePadding);
        if (width < 640) {
            expect(await visibleAnswer.evaluate(node => getComputedStyle(node, '::before').content)).toBe('"Learning goals"');
        }
        await fitsPage(page);

        await search.fill('no such question heading');
        await workspace.getByRole('button', { name: 'Reset table', exact: true }).click();
        await expect(count).toHaveText('6 of 6 question columns shown');
        await expect(search).toHaveValue('');
        await expect(workspace.locator('[data-question-empty]')).toBeHidden();
        await expect(workspace.getByLabel('Density', { exact: true })).toHaveValue('comfortable');
        await expect(workspace.locator('tbody [data-question-column][hidden]')).toHaveCount(0);
        expect(await exactQuestionCells(workspace)).toEqual(exactBefore);
        const fullAnswer = workspace.locator('tbody [data-label="Additional comments"]').first();
        await fullAnswer.locator('summary').click();
        await expect(fullAnswer.locator('p')).toContainText('Complete response content remains available on mobile. '.repeat(5).trim());
        await fitsPage(page);
    }
    expect(errors).toEqual([]);
});

test('native keyboard controls remain usable at 200% zoom and return to defaults after navigation', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 1280, height: 1000 });
    await page.goto(`/admin/webinars/${fixture.webinar}/responses`);
    // Chromium CSS zoom exercises 200% layout and native control hit areas.
    // It is browser evidence, not physical-device or assistive-technology proof.
    await page.evaluate(() => { document.documentElement.style.zoom = '2'; });
    const workspace = page.locator('[data-response-workspace]');
    const summary = workspace.locator('[data-question-chooser] > summary');
    await summary.focus();
    await page.keyboard.press('Enter');
    await expect(workspace.locator('[data-question-chooser]')).toHaveAttribute('open', '');
    await page.keyboard.press('Tab');
    const search = workspace.getByRole('searchbox', { name: 'Search question headings', exact: true });
    await expect(search).toBeFocused();
    await page.keyboard.type('Organization');
    await page.keyboard.press('Tab');
    const first = workspace.getByRole('checkbox', { name: 'Organization', exact: true });
    await expect(first).toBeFocused();
    await page.keyboard.press('Space');
    await expect(first).not.toBeChecked();
    await expect(workspace.locator('[data-question-count]')).toHaveText('5 of 6 question columns shown');
    const density = workspace.getByLabel('Density', { exact: true });
    await density.focus();
    await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Enter');
    await expect(density).toHaveValue('compact');
    await fitsPage(page);

    await page.getByRole('link', { name: 'Summary', exact: true }).click();
    await page.getByRole('link', { name: 'Responses', exact: true }).click();
    await expect(workspace.locator('[data-question-count]')).toHaveText('6 of 6 question columns shown');
    await expect(workspace.getByLabel('Density', { exact: true })).toHaveValue('comfortable');
    await expect(workspace.locator('[data-question-search]')).toHaveValue('');
    await expect(workspace.locator('[data-question-column][hidden]')).toHaveCount(0);
    await fitsPage(page);
});
