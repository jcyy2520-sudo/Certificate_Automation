import { test, expect } from '@playwright/test';

test('email allowance and delivery filters remain readable across sizes and themes without sending', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    for (const width of [1440, 820, 390]) {
        await page.setViewportSize({ width, height: 900 });
        for (const theme of ['light', 'dark']) {
            await page.goto('/admin/email-logs');
            await page.evaluate(value => {
                localStorage.setItem('theme', value);
                document.documentElement.classList.toggle('dark', value === 'dark');
            }, theme);
            await expect(page.getByRole('heading', { name: 'Email delivery log', exact: true })).toBeVisible();
            await expect(page.getByText('Shared email allowance', { exact: false })).toBeVisible();
            await expect(page.getByText('Account and participant emails share this allowance.', { exact: false })).toBeVisible();
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
        }
    }
    await page.screenshot({ path: 'storage/framework/testing/final-readiness/email-log-mobile-dark.png', fullPage: true });
    await page.getByLabel('Filter by status').selectOption('failed');
    await expect(page).toHaveURL(/status=failed/);
    await expect(page.getByRole('heading', { name: 'Email delivery log', exact: true })).toBeVisible();
    expect(errors).toEqual([]);
});
