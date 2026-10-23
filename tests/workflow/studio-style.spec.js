import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { workflowEnvironment } from '../../playwright.workflow.config.js';

test.beforeEach(async ({ page }) => {
    const fixture = JSON.parse(execFileSync('php', ['tests/workflow/studio-style-fixture.php'], { env: workflowEnvironment, encoding: 'utf8' }));
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto(`/admin/webinars/${fixture.webinar}/certificates/studio`);
    await expect(page.locator('[data-certificate-row]')).toHaveCount(0);
    for (const [name, email] of [['Style Recipient One', 'style-one@example.test'], ['Style Recipient Two', 'style-two@example.test']]) {
        await page.getByRole('button', { name: 'Add Recipient Manually', exact: true }).click();
        const dialog = page.locator('[data-manual-recipient-dialog]');
        await dialog.getByLabel('Full Name', { exact: true }).fill(name);
        await dialog.getByLabel('Email Address', { exact: true }).fill(email);
        await dialog.getByRole('button', { name: 'Add to Studio', exact: true }).click();
        await expect(dialog).not.toBeVisible();
    }
    await expect(page.locator('[data-certificate-row]')).toHaveCount(2);
});

test('apply shared style saves once without rebuilding Studio and preserves newer adjustments', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.evaluate(() => {
        window.styleStudio = document.querySelector('[data-studio]');
        window.styleRenders = 0;
        document.addEventListener('app:before-render', () => { window.styleRenders++; });
    });
    const selected = page.locator('[data-queue-check]').first();
    await selected.check();
    const position = page.getByRole('spinbutton', { name: 'Vertical position', exact: true });
    await position.fill('30'); await position.blur();
    let heldRoute;
    let saves = 0;
    await page.route('**/certification/design', route => { heldRoute = route; saves++; });
    await page.getByRole('button', { name: 'Use for every recipient', exact: true }).click();
    await page.locator('[data-confirm-accept]').click();
    await expect.poll(() => saves).toBe(1);
    await expect(page.locator('[data-apply-to-all]')).toBeDisabled();
    // This is a newer per-person tweak, made after the shared save began.
    await position.fill('42'); await position.blur();
    const saved = page.waitForResponse(response => response.url().endsWith('/certification/design') && response.ok());
    await heldRoute.continue();
    const response = await saved;
    expect((await response.body()).length).toBeLessThan(1024);
    await expect(page.locator('[data-style-save-status]')).toContainText('Template style saved');
    await expect(position).toHaveValue('42');
    await expect(selected).toBeChecked();
    await page.screenshot({ path: 'storage/framework/testing/studio-style-inline-save.png' });
    expect(await page.evaluate(() => document.querySelector('[data-studio]') === window.styleStudio)).toBe(true);
    expect(await page.evaluate(() => window.styleRenders)).toBe(0);
    await page.locator('[data-certificate-row]').nth(1).locator('[data-preview-select]').click();
    await expect(position).toHaveValue('30');
    await page.locator('[data-reset-design]').click();
    await expect(position).toHaveValue('30');
    await page.unroute('**/certification/design');
    // Save a clean document state before testing persistence across a reload.
    await page.locator('[data-certificate-row]').first().locator('[data-preview-select]').click();
    await page.locator('[data-reset-design]').click();
    await page.reload();
    await expect(position).toHaveValue('30');
    expect(saves).toBe(1);
    expect(errors).toEqual([]);
});

test('failed shared style save keeps the open editor and permits an explicit retry', async ({ page }) => {
    const position = page.getByRole('spinbutton', { name: 'Vertical position', exact: true });
    await position.fill('35'); await position.blur();
    await page.route('**/certification/design', route => route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ errors: { name_top: ['Synthetic style validation failure.'] } }) }));
    await page.getByRole('button', { name: 'Use for every recipient', exact: true }).click();
    await page.locator('[data-confirm-accept]').click();
    await expect(page.locator('[data-style-save-status]')).toHaveText('Synthetic style validation failure.');
    await expect(position).toHaveValue('35');
    await expect(page.locator('[data-apply-to-all]')).toBeEnabled();
    await page.unroute('**/certification/design');
    await page.getByRole('button', { name: 'Use for every recipient', exact: true }).click();
    await page.locator('[data-confirm-accept]').click();
    await expect(page.locator('[data-style-save-status]')).toContainText('Template style saved');
    await page.reload();
    await expect(position).toHaveValue('35');
});
