import { test, expect } from '@playwright/test';

test('all Studio font options use their bundled PDF faces in every weight and style', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto('/admin/webinars/1/certificates/studio');
    await page.getByRole('button', { name: 'Add Recipient Manually', exact: true }).click();
    const dialog = page.locator('[data-manual-recipient-dialog]');
    await dialog.getByLabel('Full Name', { exact: true }).fill('Synthetic Font Fidelity Recipient');
    await dialog.getByLabel('Email Address', { exact: true }).fill('font-browser@example.test');
    await dialog.getByRole('button', { name: 'Add to Studio', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    const preview = page.locator('[data-cert-name]');
    await expect(preview).toHaveText('Synthetic Font Fidelity Recipient');
    for (const [key, family] of [['sans', 'DejaVu Sans'], ['arial', 'DejaVu Sans'], ['serif', 'DejaVu Serif'], ['times', 'DejaVu Serif'], ['mono', 'DejaVu Sans Mono'], ['montserrat', 'Montserrat'], ['lato', 'Lato'], ['cormorant', 'Cormorant Garamond']]) {
        await page.getByRole('combobox', { name: 'Font', exact: true }).selectOption(key);
        for (const weight of ['regular', 'bold']) {
            await page.getByRole('combobox', { name: 'Font weight', exact: true }).selectOption(weight);
            for (const italic of [false, true]) {
                const toggle = page.locator('[data-toggle-italic]');
                if ((await toggle.getAttribute('aria-pressed') === 'true') !== italic) await toggle.click();
                await expect(preview).toHaveCSS('font-weight', weight === 'bold' ? '700' : '400');
                await expect(preview).toHaveCSS('font-style', italic ? 'italic' : 'normal');
                expect(await preview.evaluate(node => getComputedStyle(node).fontFamily.split(',')[0].replaceAll('"', '').replaceAll("'", ''))).toBe(family);
                expect(await page.evaluate(async ({ family, weight, italic }) => {
                    const font = `${italic ? 'italic' : 'normal'} ${weight === 'bold' ? '700' : '400'} 42px "${family}"`;
                    const faces = await document.fonts.load(font);
                    return faces.length > 0 && faces.every(face => face.status === 'loaded') && document.fonts.check(font);
                }, { family, weight, italic })).toBe(true);
                if (key === 'arial' && weight === 'regular' && !italic) {
                    await page.screenshot({ path: 'storage/framework/testing/font-fidelity/studio-arial.png' });
                }
            }
        }
    }
    expect(errors).toEqual([]);
    // Restore the shared synthetic roster for the following workflow files.
    const recipient = page.locator('[data-certificate-row]').filter({ hasText: 'font-browser@example.test' });
    await recipient.getByRole('button', { name: 'Remove', exact: true }).click();
    await expect(recipient).toHaveCount(0);
});
