import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { workflowEnvironment } from '../../playwright.workflow.config.js';

let fixture;
test.beforeAll(() => {
    fixture = JSON.parse(execFileSync('php', ['tests/workflow/ui-refinement-fixture.php'], { env: workflowEnvironment, encoding: 'utf8' }));
});

async function login(page, email = 'workflow@example.test', password = 'Workflow-fixture-2026!') {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
}

test('new users see navigation labels and a saved collapse preference survives reload', async ({ page }) => {
    await login(page);
    await expect(page.locator('html')).toHaveClass(/rail-open/);
    await page.getByRole('button', { name: 'Toggle sidebar', exact: true }).click();
    await expect(page.locator('html')).not.toHaveClass(/rail-open/);
    await page.reload();
    await expect(page.locator('html')).not.toHaveClass(/rail-open/);
});

test('mobile response records expose every answer and full long values', async ({ page }) => {
    await login(page);
    for (const theme of ['light', 'dark']) {
        await page.evaluate(theme => localStorage.setItem('theme', theme), theme);
        for (const width of [320, 390, 640, 768]) {
            await page.setViewportSize({ width, height: 844 });
            await page.goto(`/admin/webinars/${fixture.webinar}/responses`);
            const lastAnswer = page.locator('tbody tr').first().locator('[data-label="Additional comments"]');
            await expect(lastAnswer).toBeVisible();
            await lastAnswer.getByText('Read full answer', { exact: true }).click();
            await expect(lastAnswer.locator('details')).toHaveAttribute('open', '');
            await expect(lastAnswer.locator('details p')).toContainText('Complete response content remains available on mobile.');
            await expect(page.getByRole('link', { name: 'Inspect submission', exact: true }).first()).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        }
    }
});

test('populated lists, forms, uploads and Studio fit phones through wide desktop in both themes', async ({ page }) => {
    test.setTimeout(240000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    const routes = ['/admin/webinars', `/admin/webinars/${fixture.webinar}/responses`, `/admin/webinars/${fixture.webinar}/participants`, `/admin/webinars/${fixture.webinar}/certification`, `/admin/webinars/${fixture.webinar}/documents`, `/admin/webinars/${fixture.webinar}/certificates/studio`];
    for (const theme of ['light', 'dark']) {
        await page.evaluate(theme => localStorage.setItem('theme', theme), theme);
        for (const width of [320, 390, 640, 768, 1024, 1280, 1920]) {
            await page.setViewportSize({ width, height: 900 });
            for (const route of routes) {
                expect((await page.goto(route)).status(), `${route} ${width} ${theme}`).toBe(200);
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${route} ${width} ${theme}`).toBe(true);
                if ((width === 390 || width === 1280) && !route.endsWith('participants')) {
                    const name = route.split('/').at(-1);
                    await page.screenshot({ path: `storage/framework/testing/ui-refresh-20261024/${name}-${width}-${theme}.png`, fullPage: name !== 'responses' });
                }
            }
        }
    }
    expect(errors).toEqual([]);
});

test('admin lock interrupts an open manager workspace, blocks owners and restores only existing permissions', async ({ page, browser }) => {
    await login(page);
    const managerContext = await browser.newContext({ baseURL: 'http://localhost:8011' });
    const ownerContext = await browser.newContext({ baseURL: 'http://localhost:8011' });
    try {
        const managerPage = await managerContext.newPage();
        await login(managerPage, fixture.managerEmail, fixture.password);
        await managerPage.goto(`/admin/webinars/${fixture.protected}`);
        await expect(managerPage.getByRole('heading', { level: 1 })).toHaveText(fixture.protectedTitle);
        await page.goto('/admin/webinars');
        const row = page.locator(`[data-webinar-row="${fixture.protected}"]`);
        await row.getByRole('button', { name: /^Lock webinar:/ }).click();
        const confirmation = page.locator('[data-confirm-modal]');
        await expect(confirmation).toBeVisible();
        await confirmation.getByRole('button', { name: 'Lock webinar', exact: true }).click();
        await expect(page.locator(`[data-webinar-row="${fixture.protected}"]`)).toContainText('Admin locked');

        await managerPage.getByRole('link', { name: 'Uploaded documents', exact: true }).filter({ visible: true }).first().click();
        await expect(managerPage.getByRole('alert').filter({ hasText: 'restricted by an administrator' })).toBeVisible();
        const blocked = await managerPage.goto(`/admin/webinars/${fixture.protected}/certificates/studio`);
        expect(blocked.status()).toBe(423);
        await expect(managerPage.getByRole('heading', { name: 'Webinar access restricted' })).toBeVisible();
        await managerPage.getByRole('link', { name: 'Back to webinars', exact: true }).click();
        await expect(managerPage.locator(`[data-webinar-row="${fixture.protected}"]`)).toContainText('Admin locked');
        expect(await managerPage.locator(`[data-webinar-row="${fixture.protected}"] a`).count()).toBe(0);
        const token = await managerPage.locator('meta[name="csrf-token"]').getAttribute('content');
        const unauthorized = await managerPage.request.patch(`/admin/webinars/${fixture.protected}/administrator-lock`, { data: { locked: false }, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } });
        expect(unauthorized.status()).toBe(403);
        const ownerPage = await ownerContext.newPage();
        await login(ownerPage, fixture.ownerEmail, fixture.password);
        expect((await ownerPage.goto(`/admin/webinars/${fixture.protected}`)).status()).toBe(423);

        await page.locator(`[data-webinar-row="${fixture.protected}"]`).getByRole('button', { name: /^Unlock webinar:/ }).click();
        await confirmation.getByRole('button', { name: 'Unlock webinar', exact: true }).click();
        await expect(page.locator(`[data-webinar-row="${fixture.protected}"]`)).not.toContainText('Admin locked');
        expect((await managerPage.goto(`/admin/webinars/${fixture.protected}/documents`)).status()).toBe(200);
        expect((await managerPage.request.get('/admin/users')).status()).toBe(403);
        expect((await ownerPage.goto(`/admin/webinars/${fixture.protected}`)).status()).toBe(200);
    } finally {
        await managerContext.close();
        await ownerContext.close();
    }
});

for (const screen of ['Studio', 'form']) {
    test(`an already-open ${screen} explains administrator restrictions on its independent requests`, async ({ page, browser }) => {
        test.setTimeout(120000);
        await login(page);
        const token = await page.locator('meta[name="csrf-token"]').getAttribute('content');
        const setLocked = async locked => {
            const response = await page.request.patch(`/admin/webinars/${fixture.protected}/administrator-lock`, {
                data: { locked }, headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            });
            expect(response.status()).toBe(200);
        };
        const context = await browser.newContext({ baseURL: 'http://localhost:8011' });
        try {
            await setLocked(false);
            const manager = await context.newPage();
            await login(manager, fixture.managerEmail, fixture.password);
            const path = screen === 'Studio' ? 'certificates/studio' : `forms/${fixture.protectedForm}/edit`;
            expect((await manager.goto(`/admin/webinars/${fixture.protected}/${path}`)).status()).toBe(200);
            await manager.bringToFront();
            await setLocked(true);
            if (screen === 'Studio') {
                await expect(manager.locator('[data-recipient-refresh-error]')).toContainText('restricted by an administrator', { timeout: 15000 });
                await manager.locator('[data-refresh-recipients]').click();
                await expect(manager.locator('[data-recipient-refresh-error]')).toContainText('restricted by an administrator');
            } else {
                const toggle = manager.locator('[data-availability-switch]');
                const wasChecked = await toggle.isChecked();
                await manager.locator('[data-availability-form] label').click();
                await expect(manager.locator('[data-availability-error]')).toContainText('restricted by an administrator');
                await expect(toggle).toBeChecked({ checked: wasChecked });
            }
        } finally {
            await context.close();
            await setLocked(false);
        }
    });
}
