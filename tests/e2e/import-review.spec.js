import { expect, test } from '@playwright/test';

test('a confirmed action submits once', async ({ page }) => {
    await page.goto('/_preview/import-map.html');
    let submissions = 0;
    await page.route('**/synthetic-confirmation-check', async (route) => {
        submissions++;
        await route.fulfill({ status: 200, contentType: 'text/html', body: '<p>Confirmed action received</p>' });
    });
    await page.evaluate(() => {
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = '/synthetic-confirmation-check';
        form.dataset.confirm = 'Submit this synthetic check?';
        form.innerHTML = '<button type="submit">Synthetic confirmation</button>';
        document.body.append(form);
    });
    await page.getByRole('button', { name: 'Synthetic confirmation' }).click();
    await page.locator('[data-confirm-accept]').click();
    await expect(page.getByText('Confirmed action received')).toBeVisible();
    expect(submissions).toBe(1);
});

for (const width of [320, 375, 768, 1280]) {
    test(`import review exposes interpreted values and controls at ${width}px`, async ({ page }) => {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/_preview/import-map.html');
        await expect(page.locator('[name="map[middle_name]"]')).toHaveValue('2');
        await expect(page.getByRole('button', { name: 'Run dry check' })).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1)).toBe(false);
        await page.goto('/_preview/import-review.html');
        await expect(page.getByRole('heading', { name: 'Interpreted certificate names and results', exact: true })).toBeVisible();
        await expect(page.getByRole('cell').filter({ hasText: 'Renée Santos Mañalac Jr.' }).first()).toBeVisible();
        await page.getByText('Why rows need review', { exact: true }).click();
        await expect(page.getByText('The response has no email address.', { exact: false })).toBeVisible();
        await expect(page.locator('[name="map[middle_name]"]')).toHaveValue('2');
        await expect(page.locator('[name="review_confirmed"]')).not.toBeChecked();
        await expect(page.getByRole('button', { name: 'Import reviewed responses', exact: true })).toBeVisible();
        await expect(page.getByRole('link', { name: 'Back to columns', exact: true })).toBeVisible();
        const overflows = await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth + 1);
        expect(overflows).toBe(false);
    });
}

test('long certificate names fit inside the preview without changing the saved font size', async ({ page }) => {
    await page.goto('/_preview/studio.html');
    const fontsLoaded = await page.evaluate(async () => {
        const fonts = await Promise.all([
            document.fonts.load('42px "DejaVu Sans"'),
            document.fonts.load('bold italic 42px "DejaVu Serif"'),
            document.fonts.load('italic 42px "DejaVu Sans Mono"'),
        ]);
        await document.fonts.ready;
        return fonts.every((faces) => faces.length > 0 && faces.every((face) => face.status === 'loaded'));
    });
    expect(fontsLoaded).toBe(true);
    const name = page.locator('[data-cert-name]');
    const fullName = 'Maria Alexandra Cristina Isabel Santos de los Reyes Fernández Villanueva Jr.';
    await name.evaluate((element, value) => { element.textContent = value; }, fullName);
    await page.locator('[data-design-size-number]').fill('90');
    await page.locator('[data-design-size-number]').blur();
    await expect(page.locator('[data-design-size-store]')).toHaveValue('90');
    await expect(name).toHaveText(fullName);
    const measured = await name.evaluate((element) => {
        const range = document.createRange();
        range.selectNodeContents(element);
        const text = range.getBoundingClientRect();
        const canvas = element.closest('[data-cert-canvas]').getBoundingClientRect();
        return { left: text.left >= canvas.left, right: text.right <= canvas.right, top: text.top >= canvas.top, bottom: text.bottom <= canvas.bottom };
    });
    expect(measured).toEqual({ left: true, right: true, top: true, bottom: true });
});
