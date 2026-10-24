import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { workflowEnvironment } from '../../playwright.workflow.config.js';

let fixture;
test.beforeAll(() => {
    fixture = JSON.parse(execFileSync('php', ['tests/workflow/calm-workspace-fixture.php'], { env: workflowEnvironment, encoding: 'utf8' }));
});

async function login(page) {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
}

async function fitsWidth(locator, width) {
    const box = await locator.boundingBox();
    expect(box).not.toBeNull();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width).toBeLessThanOrEqual(width + 1);
}

test('phone participants expose identity, completion and actions in each readable record', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/admin/webinars/${fixture.webinar}/participants`);
    const row = page.locator('tbody tr').filter({ hasText: 'long-ui-participant-1@example.test' });
    await expect(row.getByRole('link', { name: 'Open', exact: true })).toBeVisible();
    await fitsWidth(row.getByRole('link', { name: 'Open', exact: true }), 390);
    await fitsWidth(row.getByText('long-ui-participant-1@example.test', { exact: true }), 390);
    await expect(row.locator('[data-label="Certificate delivery"]')).toBeVisible();
    await expect(page.getByRole('checkbox', { name: 'Select all eligible participants on this page' })).toBeVisible();
    await page.getByRole('checkbox', { name: 'Select all eligible participants on this page' }).check();
    await expect(page.locator('[data-participant-selection-count]')).toHaveText('8');
    await row.locator('[data-participant-certificate-checkbox]').uncheck();
    await expect(page.locator('[data-participant-selection-count]')).toHaveText('7');
    await expect(page.locator('[data-select-page-certificates]')).toHaveJSProperty('indeterminate', true);
    await page.screenshot({ path: 'storage/framework/testing/calm-workspace-20261024/participants-phone.png', fullPage: false });
});

test('phone delivery history wraps full subjects and details and keeps retry reachable', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 320, height: 740 });
    await page.goto(`/admin/webinars/${fixture.webinar}/email-logs`);
    const row = page.locator('tbody tr').filter({ hasText: fixture.subject });
    const subject = row.getByText(fixture.subject, { exact: true });
    await fitsWidth(subject, 320);
    expect(await subject.evaluate(node => getComputedStyle(node).whiteSpace)).not.toBe('nowrap');
    await fitsWidth(row.getByText(fixture.detail, { exact: true }), 320);
    await fitsWidth(row.getByRole('button', { name: 'Retry message', exact: true }), 320);
});

test('upload-only webinar opens a complete workspace before documents and Excel is offered first', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/admin/webinars/${fixture.uploads}`);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(fixture.uploadsTitle);
    await expect(page.locator('#new-document')).not.toBeVisible();
    await page.getByText('Add a document', { exact: true }).click();
    await expect(page.locator('#new-document')).toBeVisible();
    expect(await page.locator('#new-document').getAttribute('accept')).toMatch(/^\.xlsx,/);
    const workbook = execFileSync('php', ['tests/workflow/response-xlsx.php']);
    await page.getByLabel('Document title', { exact: true }).fill('Excel workbook kept intact');
    await page.locator('#new-document').setInputFiles({ name: 'responses.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', buffer: workbook });
    await page.getByRole('button', { name: 'Upload document', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Download Excel workbook kept intact', exact: true })).toBeVisible();
    const download = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download Excel workbook kept intact', exact: true }).click();
    const result = await download;
    expect(result.suggestedFilename()).toMatch(/\.xlsx$/i);
    await page.screenshot({ path: 'storage/framework/testing/calm-workspace-20261024/documents-phone.png', fullPage: true });
});

test('public forms give prompts accessible names and phone-sized inputs and theme controls', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/admin/webinars/${fixture.webinar}/forms/${fixture.form}/preview`);
    const field = page.getByRole('textbox', { name: /Learning goals/ });
    await expect(field).toBeVisible();
    expect(await field.evaluate(node => parseFloat(getComputedStyle(node).fontSize))).toBeGreaterThanOrEqual(16);
    const toggle = await page.getByRole('button', { name: 'Toggle theme', exact: true }).boundingBox();
    expect(toggle.height).toBeGreaterThanOrEqual(44);
});

test('short desktop navigation scrolls independently and keeps sign out available', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 1280, height: 480 });
    const navigation = page.locator('.rail nav');
    expect(await navigation.evaluate(node => getComputedStyle(node).overflowY)).toBe('auto');
    const signOut = page.locator('.rail').getByRole('button', { name: 'Sign out', exact: true });
    const box = await signOut.boundingBox();
    expect(box.y + box.height).toBeLessThanOrEqual(480);
});

test('4K data layouts use the available width and retain readable shared controls', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 3840, height: 2160 });
    await page.goto(`/admin/webinars/${fixture.webinar}/responses`);
    const main = await page.locator('#main-content').boundingBox();
    expect(main.width).toBeGreaterThan(3400);
    const answer = page.locator('tbody tr').first().locator('[data-label="Additional comments"]');
    await fitsWidth(answer, 3840);
    expect(await page.locator('tbody td').first().evaluate(node => parseFloat(getComputedStyle(node).fontSize))).toBeGreaterThanOrEqual(17);
    expect(await page.locator('.response-table-scroll').evaluate(node => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
    await page.screenshot({ path: 'storage/framework/testing/calm-workspace-20261024/responses-4k.png', fullPage: false });
});

test('tablet Studio keeps a usable canvas, accessible drawers and responsive Fit zoom', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 1024, height: 768 });
    await page.goto(`/admin/webinars/${fixture.webinar}/certificates/studio`);
    await expect(page.getByRole('button', { name: 'Open recipients', exact: true })).toBeVisible();
    expect((await page.locator('[data-preview-viewport]').boundingBox()).width).toBeGreaterThan(700);
    await page.getByRole('button', { name: 'Open recipients', exact: true }).click();
    const drawer = page.getByRole('dialog', { name: 'Certificate controls' });
    await expect(drawer).toBeVisible();
    await expect(drawer.locator('[data-queue-check]').first()).toHaveAccessibleName(/Select Synthetic Participant/);
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Open recipients', exact: true })).toBeFocused();
    await page.locator('[data-zoom-select]').selectOption('fit');
    await page.setViewportSize({ width: 1920, height: 1080 });
    await expect(page.locator('[data-zoom-select]')).toHaveValue('fit');
    await expect(page.locator('[data-panel="people"]')).not.toHaveAttribute('inert', '');
    const canvas = await page.locator('[data-preview-scale]').boundingBox();
    const viewport = await page.locator('[data-preview-viewport]').boundingBox();
    expect(canvas.width).toBeLessThanOrEqual(viewport.width);
    expect(canvas.height).toBeLessThanOrEqual(viewport.height);
});

test('a failed document replacement reopens its own fields and preserves its attempted title', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/admin/webinars/${fixture.uploads}`);
    await page.getByText('Add a document', { exact: true }).click();
    await page.locator('.document-upload').getByLabel('Document title', { exact: true }).fill('Replacement validation record');
    await page.locator('#new-document').setInputFiles({ name: 'original.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nSynthetic private original') });
    await page.getByRole('button', { name: 'Upload document', exact: true }).click();
    const record = page.getByRole('article').filter({ hasText: 'Replacement validation record' });
    await record.locator('summary').filter({ hasText: 'Replace document' }).click();
    await record.getByLabel('Document title', { exact: true }).fill('Preserve this attempted replacement title');
    await record.getByLabel('Replacement file', { exact: true }).setInputFiles({ name: 'legacy.xls', mimeType: 'application/vnd.ms-excel', buffer: Buffer.from('Synthetic unsupported legacy workbook') });
    await record.getByRole('button', { name: 'Replace document', exact: true }).click();
    await page.locator('[data-confirm-accept]').click();
    const restored = page.getByRole('article').filter({ hasText: 'Replacement validation record' });
    await expect(restored.locator('details')).toHaveAttribute('open', '', { timeout: 3000 });
    await expect(restored.getByLabel('Document title', { exact: true })).toHaveValue('Preserve this attempted replacement title');
    await expect(restored.getByLabel('Replacement file', { exact: true })).toHaveAttribute('aria-invalid', 'true');
    await expect(restored.getByText(/Upload an Excel/)).toBeVisible();
    await expect(page.locator('#new-document')).not.toBeVisible();
});
