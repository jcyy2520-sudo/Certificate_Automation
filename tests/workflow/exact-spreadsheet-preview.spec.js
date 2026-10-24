import { test, expect } from '@playwright/test';

test('anonymous source preview keeps full text and all rows reachable through browser navigation', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto('/admin/webinars/create');
    await page.locator('input[name=title]').fill('Synthetic exact source preview');
    await page.getByRole('button', { name: 'Create webinar', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Overview', exact: true })).toBeVisible();
    const webinarUrl = page.url();
    await page.goto(webinarUrl + '/responses?type=evaluation');
    await page.getByRole('link', { name: 'Import Responses', exact: true }).click();
    const header = '  Complete question wording '.repeat(4) + '\nFinal wording  ';
    const values = Array.from({ length: 7 }, (_, index) => '  Synthetic row ' + (index + 1) + ' É 李 '
        + 'Exact unshortened answer '.repeat(9) + '\nFinal marker  ');
    const quote = value => '"' + value.replaceAll('"', '""') + '"';
    await page.getByLabel('Response file', { exact: false }).setInputFiles({ name: 'anonymous-source.csv',
        mimeType: 'text/csv', buffer: Buffer.from([header, ...values].map(quote).join('\r\n')) });
    await page.getByRole('button', { name: 'Continue to column mapping' }).click();
    const preview = page.locator('#source-preview');
    await expect(preview).toContainText('of 7');
    expect(await preview.locator('thead th').nth(1).textContent()).toBe(header);
    expect(await preview.locator('tbody tr').first().locator('td').nth(1).textContent()).toBe(values[0]);
    await preview.locator('a[rel=next]:visible').first().click();
    await expect(page).toHaveURL(/preview_page=2/);
    await expect(preview.locator('tbody tr')).toHaveCount(2);
    expect(await preview.locator('tbody tr').first().locator('td').nth(1).textContent()).toBe(values[5]);
    expect(await preview.locator('tbody tr').last().locator('td').nth(1).textContent()).toBe(values[6]);
    for (const width of [320, 768, 3840]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        expect(await preview.locator('tbody td').nth(1).evaluate(node => getComputedStyle(node).whiteSpace)).toBe('pre-wrap');
    }
    await page.setViewportSize({ width: 390, height: 844 });
    await preview.screenshot({ path: 'storage/framework/testing/exact-workbooks-20261024/synthetic-source-preview-phone.png' });
    await preview.locator('a[rel=prev]:visible').first().click();
    await expect(page).toHaveURL(/preview_page=1/);
    await expect(preview.locator('tbody tr')).toHaveCount(5);
    await expect(page.locator('select[name="map[email]"]')).toHaveValue('');
});
