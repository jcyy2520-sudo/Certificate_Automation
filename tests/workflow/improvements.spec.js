import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';
import { workflowEnvironment } from '../../playwright.workflow.config.js';

async function login(page) {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await expect(page.locator('[data-app-layout]')).toBeVisible();
}

test('creation mode, uploads and shared dark/light hover surfaces work on a narrow screen', async ({ page }) => {
    await login(page);
    await page.goto('/admin/webinars/create');
    await page.evaluate(() => document.documentElement.classList.add('dark'));
    const header = page.locator('summary').filter({ hasText: 'Title' }).first();
    await header.hover();
    await expect(header).toHaveCSS('background-color', 'rgb(39, 39, 42)');
    await page.screenshot({ path: 'storage/framework/testing/improvements-dark.jpg' });
    await page.evaluate(() => document.documentElement.classList.remove('dark'));
    await header.hover();
    await expect(header).not.toHaveCSS('background-color', 'rgb(39, 39, 42)');
    await page.getByLabel('Webinar type').selectOption('uploads');
    await expect(page.locator('[data-standard-only]').first()).toBeHidden();
    await page.setViewportSize({ width: 390, height: 844 });
    await page.getByRole('textbox', { name: 'Title', exact: true }).fill('Uploaded forms workflow');
    await page.getByRole('button', { name: 'Create webinar', exact: true }).click();
    await expect(page.getByText('No documents yet.', { exact: false })).toBeVisible();
    await page.getByLabel('Document title', { exact: true }).fill('Private agenda');
    await page.locator('#new-document').setInputFiles({ name: 'agenda.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nSynthetic agenda') });
    await page.getByRole('button', { name: 'Upload document', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Private agenda', exact: true })).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
    await page.screenshot({ path: 'storage/framework/testing/improvements-uploads-mobile.jpg' });
    await page.getByText('Replace document', { exact: true }).first().click();
    await page.locator('input[name="file"]').last().setInputFiles({ name: 'new.pdf', mimeType: 'application/pdf', buffer: Buffer.from('%PDF-1.4\nSynthetic new agenda') });
    await page.getByRole('button', { name: 'Replace document', exact: true }).click();
    await page.locator('[data-confirm-accept]').click();
    await expect(page.getByText('Version 2', { exact: false })).toBeVisible();
    await page.getByRole('button', { name: 'Remove document', exact: true }).click();
    await page.locator('[data-confirm-accept]').click();
    await expect(page.getByText('No documents yet.', { exact: false })).toBeVisible();
});

test('message selection counts, confirmation and queue log are separate from certificate generation', async ({ page }) => {
    const fixture = JSON.parse(execFileSync('php', ['tests/workflow/improvements-fixture.php'], { env: workflowEnvironment, encoding: 'utf8' }));
    await login(page);
    await page.goto(`/admin/webinars/${fixture.webinar}/messages`);
    await expect(page.locator('[data-message-submit]')).toBeDisabled();
    await page.getByLabel('Select this page', { exact: false }).check();
    await expect(page.locator('[data-message-count]')).toHaveText('2');
    await page.getByLabel('Message subject').fill('Synthetic permission update');
    await page.getByLabel('Message body').fill('We are waiting for permission. No certificate is attached.');
    await page.locator('[data-message-submit]').click();
    await expect(page.locator('[data-confirm-message]')).toContainText('2 selected recipients');
    await page.locator('[data-confirm-accept]').click();
    await expect(page).toHaveURL(/email-logs/);
    await expect(page.getByText('Message only', { exact: true })).toHaveCount(2);
    await expect(page.getByText('Synthetic permission update', { exact: true })).toHaveCount(2);
    await page.screenshot({ path: 'storage/framework/testing/improvements-message-log.jpg' });
});

test('temporary GET failures recover but failed mutations retain entries without an automatic replay', async ({ page }) => {
    await login(page);
    let requests = 0;
    await page.route('**/admin/webinars/create', async route => {
        if (++requests === 1) return route.fulfill({ status: 503, body: 'Synthetic temporary outage' });
        return route.continue();
    });
    await page.getByRole('link', { name: 'Webinars', exact: true }).first().click();
    await page.getByRole('link', { name: 'New webinar', exact: true }).click();
    await expect(page).toHaveURL(/webinars\/create/);
    expect(requests).toBe(2);
    let mutations = 0;
    await page.route('**/admin/webinars', async route => {
        if (route.request().method() === 'POST') { mutations++; return route.fulfill({ status: 503, body: 'Synthetic uncertain save' }); }
        return route.continue();
    });
    await page.getByLabel('Title', { exact: true }).fill('Preserve my entries');
    await page.getByRole('button', { name: 'Create webinar', exact: true }).click();
    await expect(page.getByText('The request could not be completed (503). Your entries are still here.', { exact: true })).toBeVisible();
    expect(mutations).toBe(1);
    await expect(page.getByLabel('Title', { exact: true })).toHaveValue('Preserve my entries');
});
