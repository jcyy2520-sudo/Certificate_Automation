import { expect, test } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    await page.goto('/_preview/template.html');
    await expect(page.locator('[data-template-designer]')).toBeVisible();
});

test('the template page is the permanent certificate style editor', async ({ page }) => {
    await expect(page.locator('[data-template-designer]')).toContainText('Set this once');
    await expect(page.locator('[data-template-designer]')).toContainText('Every new certificate starts with this style and position');

    await page.locator('[data-template-size]').evaluate((element) => {
        element.value = '72';
        element.dispatchEvent(new Event('input', { bubbles: true }));
    });

    await expect(page.locator('[data-template-size-readout]')).toHaveText('72');
    await expect(page.locator('[data-cert-name]')).toHaveText('Sample Participant');
});

test('the template name can be positioned without typing coordinates', async ({ page }) => {
    const left = page.locator('[data-template-left]');
    const before = Number(await left.inputValue());

    await page.locator('[data-template-nudge="right"]').click();

    expect(Number(await left.inputValue())).toBeCloseTo(before + 0.5, 1);
});
