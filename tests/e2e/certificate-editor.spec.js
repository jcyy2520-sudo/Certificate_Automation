import { expect, test } from '@playwright/test';

test.beforeEach(async ({ page }) => {
    await page.goto('/_preview/studio.html');
    await expect(page.locator('[data-cert-name]')).toBeVisible();
});

test('dragging the name updates its saved position', async ({ page }) => {
    const name = page.locator('[data-cert-name]');
    const canvas = page.locator('[data-cert-canvas]');
    const top = page.locator('[data-design-top]');

    const before = Number(await top.inputValue());
    const nameBox = await name.boundingBox();
    const canvasBox = await canvas.boundingBox();

    await page.mouse.move(nameBox.x + nameBox.width / 2, nameBox.y + nameBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(canvasBox.x + canvasBox.width / 2, canvasBox.y + canvasBox.height * 0.85, { steps: 10 });
    await page.mouse.up();

    expect(Number(await top.inputValue())).toBeGreaterThan(before);
});

test('the arrow keys nudge the name a small step', async ({ page }) => {
    const name = page.locator('[data-cert-name]');
    const top = page.locator('[data-design-top]');

    await name.focus();
    const before = Number(await top.inputValue());
    await name.press('ArrowDown');
    expect(Number(await top.inputValue())).toBeCloseTo(before + 0.5, 1);
});

test('the size slider updates the readout and the persisted design value', async ({ page }) => {
    await page.getByRole('button', { name: 'Adjust this name' }).click();
    await page.locator('[data-design-size]').evaluate((el) => {
        el.value = '90';
        el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await expect(page.locator('[data-size-readout]')).toHaveText('90');
    await expect(page.locator('[data-design-size-store]')).toHaveValue('90');
});

test('the fine adjustment buttons move the name', async ({ page }) => {
    await page.getByRole('button', { name: 'Adjust this name' }).click();
    const left = page.locator('[data-design-left]');
    const before = Number(await left.inputValue());
    await page.locator('[data-nudge="right"]').click();
    expect(Number(await left.inputValue())).toBeCloseTo(before + 0.5, 1);
});

test('select all enables sending and counts everyone ready', async ({ page }) => {
    await page.locator('[data-select-all]').check();
    const ready = await page.locator('[data-queue-check]:not([disabled])').count();
    await expect(page.locator('[data-open-send-confirm]')).toBeEnabled();
    await expect(page.locator('[data-selected-count]')).toHaveText(String(ready));
});

test('sending always opens the explicit queue confirmation', async ({ page }) => {
    const firstReady = page.locator('[data-queue-check]:not([disabled])').first();
    await firstReady.check();
    await expect(page.locator('[data-open-send-confirm]')).toBeEnabled();

    await page.getByRole('button', { name: 'Adjust this name' }).click();
    await page.locator('[data-design-color]').evaluate((el) => {
        el.value = '#b91c1c';
        el.dispatchEvent(new Event('input', { bubbles: true }));
    });

    await page.locator('[data-open-send-confirm]').click();
    await expect(page.locator('[data-send-confirm]')).toBeVisible();
    await expect(page.locator('[data-confirm-count]')).toContainText('1 participant');
    await page.locator('[data-cancel-send]').click();
    await expect(page.locator('[data-send-confirm]')).toBeHidden();
});

test('choosing a participant shows their name on the certificate', async ({ page }) => {
    const buttons = page.locator('[data-certificate-row] [data-preview-select]');
    const secondName = (await buttons.nth(1).innerText()).split('\n')[0].trim();
    await buttons.nth(1).click();
    await expect(page.locator('[data-cert-name]')).toHaveText(secondName);
});

test('name adjustment is an exceptional tool, not a second template editor', async ({ page }) => {
    const adjustment = page.locator('[data-name-adjustment]');

    await expect(adjustment).toBeHidden();
    await page.getByRole('button', { name: 'Adjust this name' }).click();
    await expect(adjustment).toBeVisible();
    await expect(adjustment).toContainText('Exceptional adjustment');
    await page.getByRole('button', { name: 'Done' }).click();
    await expect(adjustment).toBeHidden();
});

test('correcting a name starts from the participant record', async ({ page }) => {
    const currentName = (await page.locator('[data-cert-name]').innerText()).trim();

    await page.getByRole('button', { name: 'Correct name' }).click();
    await expect(page.locator('[data-name-correction-modal]')).toBeVisible();
    await expect(page.locator('[data-name-correction-input]')).toHaveValue(currentName);
    await expect(page.locator('[data-name-correction-modal]')).toContainText('participant record');
});
