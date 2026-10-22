import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { workflowEnvironment } from '../../playwright.workflow.config.js';

test('webinar managers see only their webinar audit while admin retains the complete trail', async ({ page, browser }) => {
    const fixture = JSON.parse(execFileSync('php', ['tests/workflow/audit-fixture.php'], { env: workflowEnvironment, encoding: 'utf8' }));
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('audit-manager@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Audit-browser-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto(`/admin/webinars/${fixture.a}/audit`);
    await expect(page.getByRole('heading', { name: 'Audit Trail', exact: true })).toBeVisible();
    await expect(page.locator('main')).toContainText('webinar_a.reviewed');
    await expect(page.locator('main')).not.toContainText('webinar_b.private');
    await expect(page.locator('main')).not.toContainText('account.private_global');
    await expect(page.locator('select[name="webinar"]')).toHaveCount(0);
    await page.getByLabel('Action', { exact: true }).fill('reviewed');
    await page.getByRole('button', { name: 'Apply filters', exact: true }).click();
    await expect(page).toHaveURL(new RegExp(`/webinars/${fixture.a}/audit\\?`));
    for (const width of [1440, 820, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.evaluate(() => document.documentElement.classList.add('dark'));
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    }
    await page.screenshot({ path: 'storage/framework/testing/final-readiness/webinar-audit-mobile-dark.png', fullPage: true });
    expect((await page.request.get(`/admin/webinars/${fixture.b}/audit`)).status()).toBe(404);
    expect((await page.request.get(`/admin/webinars/${fixture.a}/audit?webinar=${fixture.b}`)).status()).toBe(404);
    expect((await page.request.get('/admin/audit-trail')).status()).toBe(403);
    const context = await browser.newContext();
    const admin = await context.newPage();
    await admin.goto('/admin/login');
    await admin.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await admin.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await admin.getByRole('button', { name: 'Sign in', exact: true }).click();
    await admin.goto('/admin/audit-trail');
    await expect(admin.locator('main')).toContainText('webinar_b.private');
    await expect(admin.locator('main')).toContainText('account.private_global');
    await context.close();
    expect(errors).toEqual([]);
});
