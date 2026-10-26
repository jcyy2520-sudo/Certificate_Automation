import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { workflowEnvironment } from '../../playwright.workflow.config.js';

let fixture;
test.beforeAll(() => {
    fixture = JSON.parse(execFileSync('php', ['tests/workflow/calm-workspace-fixture.php'], { env: workflowEnvironment, encoding: 'utf8' }));
});

async function login(page, email = 'workflow@example.test', password = 'Workflow-fixture-2026!') {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill(email);
    await page.getByLabel('Password', { exact: true }).fill(password);
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page).toHaveURL(/\/admin$/);
}

async function fitsViewport(page) {
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
}

async function panelFitsViewport(panel) {
    await expect(panel).toHaveAttribute('aria-hidden', 'false');
    await expect.poll(() => panel.evaluate(node => node.getBoundingClientRect().left)).toBeGreaterThanOrEqual(-1);
    await expect.poll(() => panel.evaluate(node => node.getBoundingClientRect().right - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
}

async function panelIsClosed(panel, edge) {
    await expect(panel).toHaveAttribute('aria-hidden', 'true');
    if (edge === 'right') {
        await expect.poll(() => panel.evaluate(node => node.getBoundingClientRect().left - document.documentElement.clientWidth)).toBeGreaterThanOrEqual(-1);
    } else {
        await expect.poll(() => panel.evaluate(node => node.getBoundingClientRect().right)).toBeLessThanOrEqual(1);
    }
}

test('overview links follow the workflow and requirement review opens the real settings', async ({ page }) => {
    await login(page);
    await page.goto(`/admin/webinars/${fixture.webinar}`);
    const workflow = page.getByRole('region', { name: 'Run this webinar' });
    await expect(workflow.getByRole('heading', { level: 3 })).toHaveText(['Forms', 'Responses', 'Eligibility', 'Certificate setup', 'Send']);
    await workflow.getByRole('link', { name: 'Eligibility Settings', exact: true }).click();
    await expect(page).toHaveURL(/responses\?type=registration&view=settings$/);
    await expect(page.getByRole('button', { name: 'Save eligibility settings', exact: true })).toBeVisible();
    await page.goto(`/admin/webinars/${fixture.webinar}`);
    await page.getByRole('link', { name: 'Review requirements', exact: true }).click();
    await expect(page.getByRole('button', { name: 'Save eligibility settings', exact: true })).toBeVisible();
});

test('phone shortcuts preserve navigation and every answer while More keeps accessible drawer behavior', async ({ page }) => {
    test.setTimeout(120000);
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await login(page);
    for (const theme of ['light', 'dark']) {
        await page.evaluate(theme => localStorage.setItem('theme', theme), theme);
        for (const width of [320, 390, 768]) {
            await page.setViewportSize({ width, height: 844 });
            await page.goto(`/admin/webinars/${fixture.webinar}`);
            const navigation = page.getByRole('navigation', { name: 'Common webinar actions', exact: true });
            await expect(navigation).toBeVisible();
            await fitsViewport(page);
            for (const target of await navigation.locator('a, button').all()) {
                const bounds = await target.boundingBox();
                expect(bounds.height).toBeGreaterThanOrEqual(44);
                expect(bounds.width).toBeGreaterThanOrEqual(44);
            }
            await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
            const lastControl = page.locator('main summary').last();
            expect((await lastControl.boundingBox()).y + (await lastControl.boundingBox()).height).toBeLessThanOrEqual((await navigation.boundingBox()).y);
            await page.evaluate(() => {
                window.workflowDocument = true;
                window.workflowNavigation = document.querySelector('[aria-label="Common webinar actions"]');
            });
            await navigation.getByRole('link', { name: 'Form Responses', exact: true }).click();
            await expect(page.locator('.response-data-table')).toBeVisible();
            await expect(navigation.getByRole('link', { name: 'Form Responses', exact: true })).toHaveAttribute('aria-current', 'page');
            expect(await page.evaluate(() => window.workflowDocument && window.workflowNavigation === document.querySelector('[aria-label="Common webinar actions"]'))).toBe(true);
            const laterAnswer = page.locator('tbody tr').first().locator('[data-label="Additional comments"]');
            await expect(laterAnswer).toBeVisible();
            await laterAnswer.getByText('Read full answer', { exact: true }).click();
            await expect(laterAnswer.locator('details p')).toContainText('Complete response content remains available on mobile.');
            await expect(page.getByRole('link', { name: 'Inspect submission', exact: true }).first()).toBeVisible();
            await fitsViewport(page);

            await navigation.getByRole('link', { name: 'Forms', exact: true }).click();
            await expect(page.getByRole('link', { name: 'Preview form', exact: true })).toBeVisible();
            const more = navigation.getByRole('button', { name: 'More webinar navigation', exact: true });
            await more.click();
            const drawer = page.getByRole('dialog', { name: 'Webinar navigation', exact: true });
            await expect(drawer).toBeVisible();
            await page.keyboard.press('Shift+Tab');
            expect(await page.evaluate(() => Boolean(document.activeElement.closest('[data-mobile-webinar-nav-drawer]')))).toBe(true);
            await page.keyboard.press('Escape');
            await expect(more).toBeFocused();
            await expect(more).toHaveAttribute('aria-expanded', 'false');
            await fitsViewport(page);
            if (width === 390) {
                await navigation.getByRole('link', { name: 'Overview', exact: true }).click();
                await expect(page.getByRole('region', { name: 'Run this webinar', exact: true })).toBeVisible();
                await page.screenshot({ path: `storage/framework/testing/workflow-mobile-20261026/overview-${theme}.png`, fullPage: false });
            }
        }
    }
    expect(errors).toEqual([]);
});

test('file storage explains attachment behavior and leads to the full webinar creation choice', async ({ page }) => {
    await login(page);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/admin/webinars/${fixture.uploads}`);
    const navigation = page.getByRole('navigation', { name: 'Common webinar actions', exact: true });
    await expect(navigation.getByRole('link')).toHaveText(['Overview', 'Documents']);
    await expect(page.getByRole('heading', { name: 'This workspace stores files', exact: true })).toBeVisible();
    await expect(page.locator('main')).toContainText('It does not create response tables, import answers or publish a participant form.');
    await page.getByRole('link', { name: 'Create a response and certificate webinar', exact: true }).click();
    await expect(page.getByLabel('Webinar type', { exact: true })).toHaveValue('standard');
    await page.getByLabel('Webinar type', { exact: true }).selectOption('uploads');
    await expect(page.locator('[data-standard-only]').first()).toBeHidden();
    await expect(page.locator('#creation-mode-help')).toContainText('It has no response tables, participant forms or certificate tools.');
    await fitsViewport(page);
});

test('assigned managers get the same ordered guide without owner-only settings', async ({ page }) => {
    await login(page, fixture.managerEmail, fixture.password);
    await page.setViewportSize({ width: 390, height: 844 });
    await page.goto(`/admin/webinars/${fixture.protected}`);
    const workflow = page.getByRole('region', { name: 'Run this webinar' });
    await expect(workflow.getByRole('heading', { level: 3 })).toHaveText(['Forms', 'Responses', 'Eligibility', 'Certificate setup', 'Send']);
    await page.getByRole('button', { name: 'More webinar navigation', exact: true }).click();
    const drawer = page.getByRole('dialog', { name: 'Webinar navigation', exact: true });
    await expect(drawer.getByRole('link', { name: 'Webinar settings', exact: true })).toHaveCount(0);
    await expect(drawer.getByRole('link', { name: 'Participant Eligibility', exact: true })).toBeVisible();
    await page.keyboard.press('Escape');
    await fitsViewport(page);
});

test('Studio keeps its own phone controls and draws both panels without document overflow', async ({ page }) => {
    test.setTimeout(120000);
    await login(page);
    for (const theme of ['light', 'dark']) {
        await page.evaluate(theme => localStorage.setItem('theme', theme), theme);
        for (const width of [320, 390, 768]) {
            await page.setViewportSize({ width, height: 844 });
            await page.goto(`/admin/webinars/${fixture.webinar}`);
            const documentGutter = await page.evaluate(() => getComputedStyle(document.documentElement).scrollbarGutter);
            await page.evaluate(() => { window.studioNavigationSentinel = true; });
            await page.getByRole('region', { name: 'Run this webinar', exact: true }).getByRole('link', { name: 'Open Certificate Studio', exact: true }).click();
            await expect(page.locator('[data-studio]')).toBeVisible();
            expect(await page.evaluate(() => window.studioNavigationSentinel)).toBe(true);
            await expect(page.getByRole('navigation', { name: 'Common webinar actions', exact: true })).toHaveCount(0);
            await fitsViewport(page);
            await page.getByRole('button', { name: 'Open editor', exact: true }).click();
            const editor = page.locator('[data-panel="editor"]');
            await panelFitsViewport(editor);
            await page.getByRole('button', { name: 'Close editor', exact: true }).click();
            await panelIsClosed(editor, 'right');
            await expect(page.getByRole('button', { name: 'Open editor', exact: true })).toBeFocused();
            await page.getByRole('button', { name: 'Open recipients', exact: true }).click();
            const recipients = page.locator('[data-panel="people"]');
            await expect(page.getByRole('button', { name: 'Close recipients', exact: true })).toBeVisible();
            await panelFitsViewport(recipients);
            await page.getByRole('button', { name: 'Close recipients', exact: true }).click();
            await panelIsClosed(recipients, 'left');
            await panelIsClosed(editor, 'right');
            await expect(page.locator('[data-panel-scrim]')).toBeHidden();
            if (width === 390 && theme === 'dark') await page.screenshot({ path: 'storage/framework/testing/workflow-mobile-20261026/studio-dark.png', fullPage: false });
            await fitsViewport(page);
            await page.getByRole('link', { name: 'Back to participants', exact: true }).click();
            await expect(page.locator('body')).toHaveAttribute('data-app-layout', 'webinar');
            await expect.poll(() => page.evaluate(() => getComputedStyle(document.documentElement).scrollbarGutter)).toBe(documentGutter);
            expect(await page.evaluate(() => window.studioNavigationSentinel)).toBe(true);
        }
    }
});
