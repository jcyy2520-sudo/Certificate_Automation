import { expect, test } from '@playwright/test';

test('the availability switch saves without reloading the page', async ({ page }) => {
    await page.route('**/toggle', async (route) => {
        const open = Boolean(route.request().postDataJSON()?.is_open);
        await route.fulfill({
            contentType: 'application/json',
            body: JSON.stringify({
                open,
                accepts_responses: open,
                state: open ? 'Open — accepting responses now' : 'Closed — not accepting responses',
                message: open ? 'Opened. This form is accepting responses now.' : 'Closed. This form is not accepting responses.',
            }),
        });
    });

    await page.goto('/_preview/form-edit.html');
    const originalUrl = page.url();
    const toggle = page.locator('[data-availability-switch]');
    const label = page.locator('[data-availability-label]');

    await toggle.uncheck({ force: true });
    await expect(label).toHaveText('Closed');
    await expect(page).toHaveURL(originalUrl);

    await toggle.check({ force: true });
    await expect(label).toHaveText('Open');
    await expect(page.locator('[data-availability-state-text]')).toHaveText('Open — accepting responses now');
    await expect(page).toHaveURL(originalUrl);
});
