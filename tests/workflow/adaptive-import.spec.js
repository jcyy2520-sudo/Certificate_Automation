import { test, expect } from '@playwright/test';
import { execFileSync } from 'node:child_process';

test('adaptive XLSX preview, extra columns, full answers and responsive records work over real HTTP', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto('/admin/webinars/create');
    await page.locator('input[name=title]').fill('Adaptive import browser webinar');
    await page.getByRole('button', { name: 'Create webinar', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Overview', exact: true })).toBeVisible();
    const webinarUrl = page.url();
    await page.goto(webinarUrl + '/responses');
    await page.getByRole('link', { name: 'Import Responses', exact: true }).click();
    await page.getByLabel('Response file', { exact: false }).setInputFiles({
        name: 'adaptive-responses.xlsx', mimeType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        buffer: execFileSync('php', ['tests/workflow/response-xlsx.php']),
    });
    await page.getByRole('button', { name: 'Continue to column mapping' }).click();
    await expect(page.getByRole('region', { name: 'Detected file structure' })).toContainText('XLSX · 1 response rows · 30 columns');
    await page.getByText('Review detected columns and mapping status', { exact: true }).click();
    await expect(page.getByRole('region', { name: 'Detected file structure' })).toContainText('Additional imported field');
    await page.locator('input[name=allow_import_time]').check();
    await page.locator('input[name=source_description]').fill('Adaptive browser response workbook v1');
    await page.locator('input[name=source_confirmed]').check();
    await page.getByRole('button', { name: 'Run dry check', exact: true }).click();
    await expect(page.getByText('Dry run complete', { exact: false })).toBeVisible();
    await page.locator('input[name=review_confirmed]').check();
    await page.getByRole('button', { name: 'Import reviewed responses', exact: true }).click();
    await page.goto(webinarUrl + '/responses');
    await expect(page.locator('.response-table tbody')).toContainText('North campus');
    await expect(page.getByRole('columnheader', { name: 'Extra question 25', exact: true })).toBeAttached();
    await expect(page.locator('.response-answer-cell').filter({ hasText: '0' }).first()).toBeAttached();
    const scroll = page.locator('.response-table-scroll');
    expect(await scroll.evaluate(el => el.scrollWidth > el.clientWidth)).toBe(true);
    await page.locator('.response-value summary').click();
    await expect(page.locator('.response-value p')).toContainText('Final answer marker.');
    await page.locator('.response-value summary').click();
    await page.screenshot({ path: 'storage/framework/testing/adaptive-desktop-light.png', fullPage: true });
    await page.setViewportSize({ width: 768, height: 1024 });
    await page.getByRole('button', { name: 'Toggle theme', exact: true }).filter({ visible: true }).click();
    await page.screenshot({ path: 'storage/framework/testing/adaptive-tablet-dark.png', fullPage: true });
    for (const width of [390, 320]) {
        await page.setViewportSize({ width, height: 844 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await expect(page.getByRole('link', { name: 'Inspect submission', exact: true })).toBeVisible();
        await expect(page.locator('.response-answer-cell').nth(2)).not.toBeVisible();
        await page.screenshot({ path: `storage/framework/testing/adaptive-mobile-${width}-dark.png`, fullPage: true });
    }
    await page.getByRole('link', { name: 'Inspect submission', exact: true }).click();
    await expect(page.getByText('Final answer marker.', { exact: false }).first()).toBeVisible();
    await expect(page.getByText('Answer 25', { exact: true }).first()).toBeVisible();
    await page.goto(webinarUrl + '/responses?view=summary');
    await expect(page.locator('body')).toContainText('Question type is unconfirmed');
    await page.goto(webinarUrl + '/responses');
    await page.locator('input[name=search]').fill('no matches here');
    await page.getByRole('button', { name: 'Apply', exact: true }).click();
    await expect(page.getByText('No responses to show', { exact: true })).toBeVisible();
    await page.getByRole('button', { name: 'Clear', exact: true }).click();
    await expect(page.getByRole('link', { name: 'Inspect submission', exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});
