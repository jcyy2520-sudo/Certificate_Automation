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

test('the size field updates the persisted design value', async ({ page }) => {
    await page.locator('[data-design-size-number]').fill('90');
    await page.locator('[data-design-size-number]').blur();
    await expect(page.locator('[data-design-size-store]')).toHaveValue('90');
});

test('typing an exact position moves the name there', async ({ page }) => {
    await page.locator('[data-design-top-input]').fill('25');
    await page.locator('[data-design-top-input]').blur();
    await expect(page.locator('[data-design-top]')).toHaveValue('25.00');
});

test('an alignment preset repositions and re-aligns the name', async ({ page }) => {
    await page.locator('[data-align-preset="right"]').click();
    await expect(page.locator('[data-design-left]')).toHaveValue('80.00');
    await expect(page.locator('[data-design-align-store]')).toHaveValue('right');
});

test('undo reverses a change and redo reapplies it', async ({ page }) => {
    const left = page.locator('[data-design-left]');
    const undo = page.locator('[data-undo]');
    const redo = page.locator('[data-redo]');

    await expect(undo).toBeDisabled();
    const before = await left.inputValue();

    await page.locator('[data-align-preset="right"]').click();
    const after = await left.inputValue();
    expect(after).not.toBe(before);

    await expect(undo).toBeEnabled();
    await undo.click();
    await expect(left).toHaveValue(before);

    await expect(redo).toBeEnabled();
    await redo.click();
    await expect(left).toHaveValue(after);
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
    const rows = page.locator('[data-certificate-row]');
    // Read the name from its own element: the row also renders an avatar
    // initial, so the first line of its text is not the participant.
    const secondName = (await rows.nth(1).locator('[data-participant-name]').innerText()).trim();
    await rows.nth(1).locator('[data-preview-select]').click();
    await expect(page.locator('[data-cert-name]')).toHaveText(secondName);
});

test('a recipient row shows a compact status chip without squeezing the recipient', async ({ page }) => {
    const row = page.locator('[data-certificate-row]').first();

    await expect(row.locator('[data-participant-name]')).toBeVisible();
    await expect(row).toHaveAttribute('title', /.+/);

    // The status is now visible to sighted admins — the whole point is that a
    // disabled checkbox explains itself ("Sent" vs "Failed"). It must stay a
    // small fixed-width chip whose wording never grows with the full label,
    // and the full wording remains available on hover.
    const badge = row.locator('[data-status-badge]');
    const box = await badge.boundingBox();
    expect(box.width).toBeGreaterThanOrEqual(30);
    expect(box.width).toBeLessThanOrEqual(64);
    await expect(badge).toHaveAttribute('title', /.+/);

    // Short word on the chip, long label on hover.
    expect((await badge.innerText()).length).toBeLessThanOrEqual(8);
});

test('the name and email keep their room whatever the status says', async ({ page }) => {
    const row = page.locator('[data-certificate-row]').first();
    const name = row.locator('[data-participant-name]');

    const before = (await name.boundingBox()).width;

    // The widest label the delivery states can produce.
    await row.locator('[data-status-badge]').evaluate((el) => {
        el.textContent = 'Accepted by provider';
        el.closest('[data-certificate-row]').dataset.status = 'sent';
    });

    expect((await name.boundingBox()).width).toBeCloseTo(before, 0);
});

test('the editor rail explains per-recipient tweaks versus the webinar-wide template', async ({ page }) => {
    const rail = page.locator('[data-name-adjustment]');

    // The rail is a permanent part of the layout: individual tweaks ride along
    // with the send, and one button promotes the style to every recipient.
    await expect(rail).toBeVisible();
    await expect(rail).toContainText('Use for every recipient');
    await expect(rail).toContainText('applied automatically when you send');
});

test('the editor rail highlights the active recipient email', async ({ page }) => {
    const rows = page.locator('[data-certificate-row]');
    const emailBox = page.locator('[data-adjustment-email]').first();

    // The address sits beside the name being spell-checked, highlighted,
    // because a typo there silently delivers someone else's certificate.
    await expect(emailBox).toBeVisible();
    await expect(emailBox).toContainText('@');

    // It follows whichever recipient is on the canvas.
    const secondEmail = await page.locator('[data-email-store]').nth(1).getAttribute('data-value');
    await rows.nth(1).locator('[data-preview-select]').click();
    await expect(emailBox).toHaveText(secondEmail || 'No email address');
});

test('the recipient panel switches between To send and Sent tabs', async ({ page }) => {
    const working = page.locator('[data-tab-panel="working"]');
    const sent = page.locator('[data-tab-panel="sent"]');

    await expect(working).toBeVisible();
    await expect(sent).toBeHidden();

    await page.locator('[data-recipient-tab="sent"]').click();
    await expect(sent).toBeVisible();
    await expect(working).toBeHidden();
    await expect(page.locator('[data-sent-empty]')).toContainText('Nothing sent yet');

    await page.locator('[data-recipient-tab="working"]').click();
    await expect(working).toBeVisible();
    await expect(sent).toBeHidden();
});

test('the style being edited can be applied to every recipient', async ({ page }) => {
    await page.locator('[data-design-top-input]').fill('30');
    await page.locator('[data-apply-to-all]').click();

    // The hidden form carries the exact edited values through confirmation.
    const form = page.locator('#apply-all-form');
    await expect(form.locator('[data-apply-top]')).toHaveValue(/^30(\.00)?$/);
    await expect(page.locator('[data-confirm-modal] [data-confirm-title]')).toHaveText('Apply style to every recipient');

    // Cancelling must leave the page unchanged — nothing is posted.
    await page.locator('[data-confirm-cancel]').click();
    await expect(page.locator('[data-confirm-modal]')).toBeHidden();
});

test('the state pill distinguishes template style from an adjustment', async ({ page }) => {
    await expect(page.locator('[data-adjust-label]')).toHaveText('Template style');
    await page.locator('[data-align-preset="bottom"]').click();
    await expect(page.locator('[data-adjust-label]')).toHaveText('Adjusted for this recipient');
});

test('resetting returns the recipient to the template style', async ({ page }) => {
    await page.locator('[data-align-preset="left"]').click();
    await expect(page.locator('[data-adjust-label]')).toHaveText('Adjusted for this recipient');

    await page.locator('[data-reset-design]').click();
    await expect(page.locator('[data-adjust-label]')).toHaveText('Template style');
});

test('correcting a name starts from the participant record', async ({ page }) => {
    const currentName = (await page.locator('[data-cert-name]').innerText()).trim();

    await page.getByRole('button', { name: 'Correct name' }).click();
    await expect(page.locator('[data-name-correction-modal]')).toBeVisible();
    await expect(page.locator('[data-name-correction-input]')).toHaveValue(currentName);
    await expect(page.locator('[data-name-correction-modal]')).toContainText('participant record');
});

test('searching narrows the recipient list', async ({ page }) => {
    const rows = page.locator('[data-certificate-row]');
    const total = await rows.count();

    const firstName = (await rows.first().locator('[data-participant-name]').innerText()).trim();
    await page.locator('[data-certificate-search]').fill(firstName);

    const visible = await rows.evaluateAll((nodes) => nodes.filter((node) => !node.hidden).length);
    expect(visible).toBeLessThan(total);
    expect(visible).toBeGreaterThan(0);
});
