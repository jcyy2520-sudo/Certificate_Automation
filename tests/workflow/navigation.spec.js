import { test, expect } from '@playwright/test';

async function login(page) {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.locator('[data-app-layout]')).toBeVisible();
}

test('navigation uses fetch, retains the document and sidebar, supports history and recovers from offline', async ({ page }) => {
    await page.addInitScript(() => {
        window.navigationIntervals = new Set();
        const set = window.setInterval.bind(window), clear = window.clearInterval.bind(window);
        window.setInterval = (...args) => { const id = set(...args); window.navigationIntervals.add(id); return id; };
        window.clearInterval = (id) => { window.navigationIntervals.delete(id); clear(id); };
    });
    const errors = []; const documents = []; const requests = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    await page.goto('/admin/webinars/1/responses');
    await page.evaluate(() => { window.navigationSentinel = 'same-document'; window.sidebarSentinel = document.querySelector('.webinar-nav'); });
    page.on('request', request => { if (request.isNavigationRequest() && request.frame() === page.mainFrame()) documents.push(request.url()); requests.push(request); });
    await page.getByRole('link', { name: 'Summary', exact: true }).click();
    await expect(page).toHaveURL(/view=summary/);
    await expect(page.locator('[data-response-summary]')).toBeVisible();
    expect(await page.evaluate(() => window.navigationIntervals.size)).toBe(1);
    expect(await page.evaluate(() => window.navigationSentinel)).toBe('same-document');
    expect(await page.evaluate(() => document.querySelector('.webinar-nav') === window.sidebarSentinel)).toBe(true);
    await page.goBack();
    await expect(page.locator('.response-data-table')).toBeVisible();
    expect(await page.evaluate(() => window.navigationIntervals.size)).toBe(0);
    await page.goForward();
    await expect(page.locator('[data-response-summary]')).toBeVisible();
    expect(await page.evaluate(() => window.navigationIntervals.size)).toBe(1);
    await page.getByRole('link', { name: 'Eligibility Settings', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save eligibility settings' })).toBeVisible();
    await page.getByRole('button', { name: 'Save eligibility settings' }).click();
    await expect(page.locator('html')).not.toHaveClass(/is-navigating/);
    await page.getByRole('link', { name: 'Summary', exact: true }).click();
    await expect(page.locator('[data-response-summary]')).toBeVisible();
    await page.context().setOffline(true);
    await page.getByRole('link', { name: 'Responses', exact: true }).click();
    await expect(page.getByRole('alert').filter({ hasText: /Failed to fetch|Unable to connect/ })).toBeVisible();
    await expect(page.locator('[data-response-summary]')).toBeVisible();
    await page.context().setOffline(false);
    await page.getByRole('link', { name: 'Responses', exact: true }).click();
    await expect(page.locator('.response-data-table')).toBeVisible();
    expect(documents).toEqual([]);
    expect(requests.some(request => request.headers()['x-frontend-navigation'] === '1')).toBe(true);
    expect(errors).toEqual([]);
});

test('unsaved edits, browser Back cancellation, duplicate-save guard and validation recovery', async ({ page }) => {
    await login(page);
    await page.goto('/admin/webinars');
    await page.getByRole('link', { name: 'New webinar', exact: true }).click();
    await expect(page).toHaveURL(/create/);
    await page.locator('input[name="title"]').fill('Unsaved browser draft');
    await page.getByRole('link', { name: 'Webinars', exact: true }).filter({ visible: true }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Leave unsaved changes?' });
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Stay on this page' }).click();
    await expect(page.locator('input[name="title"]')).toHaveValue('Unsaved browser draft');
    await page.goBack();
    await expect(dialog).toBeVisible();
    await dialog.getByRole('button', { name: 'Stay on this page' }).click();
    await expect(page).toHaveURL(/create/);
    await page.waitForFunction(() => !window.webinarNavigation.pending());
    await expect(dialog).not.toBeVisible();
    await page.locator('input[name="title"]').fill('');
    await expect(page.locator('input[name="title"]')).toHaveValue('');
    await page.locator('form').filter({ has: page.locator('input[name="title"]') }).evaluate(form => { form.noValidate = true; form.requestSubmit(); });
    await expect(page.locator('[data-validation-summary]')).toContainText('title');
    await expect(page.locator('input[name="title"]')).toHaveValue('');
    await page.locator('input[name="title"]').fill('Navigation verified webinar');
    await page.locator('input[name="data_retention_days"]').fill('30');
    const posts = [];
    page.on('request', request => { if (request.method() === 'POST' && /\/admin\/webinars$/.test(request.url())) posts.push(request); });
    await page.locator('form').filter({ has: page.locator('input[name="title"]') }).evaluate(form => { form.requestSubmit(); form.requestSubmit(); });
    await expect(page).toHaveURL(/\/admin\/webinars\/\d+$/);
    expect(posts).toHaveLength(1);
});

test('all management screens fit narrow phones through wide desktop, with keyboard-safe drawers and immediate themes', async ({ page }) => {
    // This scenario performs 220 full HTTP navigations across ten layouts.
    test.setTimeout(300000);
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    await login(page);
    const routes = ['/admin', '/admin/webinars', '/admin/webinars/create', '/admin/profile', '/admin/users', '/admin/email-logs', '/admin/confirm-password', '/admin/security/two-factor', '/admin/webinars/1', '/admin/webinars/1/edit', '/admin/webinars/1/forms/1/edit', '/admin/webinars/1/forms/2/edit', '/admin/webinars/1/responses', '/admin/webinars/1/responses?view=summary', '/admin/webinars/1/responses?view=eligibility', '/admin/webinars/1/responses?view=settings', '/admin/webinars/1/imports/upload', '/admin/webinars/1/imports', '/admin/webinars/1/certification', '/admin/webinars/1/reports', '/admin/webinars/1/email-logs', '/admin/webinars/1/certificates/studio'];
    for (const theme of ['light', 'dark']) {
        await page.evaluate(theme => localStorage.setItem('theme', theme), theme);
        for (const width of [320, 390, 768, 1280, 1920]) {
            await page.setViewportSize({ width, height: 900 });
            for (const route of routes) {
                const response = await page.goto(route);
                expect(response.status(), route).toBeLessThan(400);
                await expect(page.locator('[data-app-layout]')).toBeVisible();
                expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), `${route} at ${width}px in ${theme}`).toBe(true);
            }
        }
    }
    await page.evaluate(() => localStorage.setItem('theme', 'light'));
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto('/admin/webinars/1/responses');
    const toggle = page.getByRole('button', { name: 'Open webinar navigation' });
    await toggle.click();
    const drawer = page.getByRole('dialog', { name: 'Webinar navigation' });
    await expect(drawer).toBeVisible();
    await page.keyboard.press('Shift+Tab');
    expect(await page.evaluate(() => Boolean(document.activeElement.closest('[data-mobile-webinar-nav-drawer]')))).toBe(true);
    await page.keyboard.press('Escape'); await expect(toggle).toBeFocused();
    await toggle.click();
    await drawer.getByRole('link', { name: 'Certificate Setup', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Certificate Setup', exact: true })).toBeVisible();
    await expect(toggle).toHaveAttribute('aria-expanded', 'false');
    await page.getByRole('button', { name: 'Toggle theme' }).filter({ visible: true }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.screenshot({ path: 'storage/framework/testing/frontend-phone-dark.png', fullPage: true });
    expect(errors).toEqual([]);
});


test('account, recovery and error screens fit a narrow phone and use the shared theme', async ({ page }) => {
    const errors = []; page.on('pageerror', error => errors.push(error.message));
    await page.setViewportSize({ width: 320, height: 640 });
    for (const url of ['/admin/login', '/request-account', '/account-status', '/forgot-password', '/reset-password', '/page-that-does-not-exist']) {
        await page.goto(url);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), url).toBe(true);
        await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    }
    await page.goto('/admin/login');
    await page.getByRole('button', { name: 'Show password', exact: true }).click();
    await expect(page.getByLabel('Password', { exact: true })).toHaveAttribute('type', 'text');
    await page.getByRole('button', { name: 'Hide password', exact: true }).click();
    await page.getByRole('button', { name: 'Toggle theme' }).click();
    await expect(page.locator('html')).toHaveClass(/dark/);
    await page.reload(); await expect(page.locator('html')).toHaveClass(/dark/);
    await page.screenshot({ path: 'storage/framework/testing/frontend-auth-phone-dark.png', fullPage: true });
    expect(errors).toEqual([]);
});
