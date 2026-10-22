import { test, expect } from '@playwright/test';

test('manual Studio recipients add, validate, edit and remove without losing the document or certificate design', async ({ page }) => {
    const errors = [];
    page.on('pageerror', (error) => errors.push(error.message));
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto('/admin/webinars/1/certificates/studio');
    await page.evaluate(() => { window.studioDocumentMarker = 'same-studio-document'; });
    const dialog = page.locator('[data-manual-recipient-dialog]');
    await page.getByRole('button', { name: 'Add Recipient Manually', exact: true }).click();
    await expect(dialog).toBeVisible();
    await expect(dialog.getByLabel('Full Name', { exact: true })).toBeFocused();
    await dialog.getByRole('button', { name: 'Add to Studio', exact: true }).click();
    await expect(dialog.locator('[aria-invalid="true"]')).toHaveCount(2);
    await dialog.getByLabel('Full Name', { exact: true }).fill('Studio Browser Exception');
    await dialog.getByLabel('Email Address', { exact: true }).fill('studio-exception@example.test');
    for (const [width, height, theme] of [[1440, 1000, 'light'], [768, 1024, 'light'], [320, 568, 'dark']]) {
        await page.setViewportSize({ width, height });
        await page.evaluate((value) => document.documentElement.classList.toggle('dark', value === 'dark'), theme);
        await expect(dialog.getByRole('button', { name: 'Add to Studio', exact: true })).toBeVisible();
        const box = await dialog.boundingBox();
        expect(box.x).toBeGreaterThanOrEqual(0); expect(box.x + box.width).toBeLessThanOrEqual(width);
        expect(box.y).toBeGreaterThanOrEqual(0); expect(box.y + box.height).toBeLessThanOrEqual(height);
        await page.screenshot({ path: `storage/framework/testing/studio-manual-${width}-${theme}.png` });
    }
    await dialog.getByRole('button', { name: 'Add to Studio', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    await page.setViewportSize({ width: 1440, height: 1000 });
    const row = page.locator('[data-certificate-row]').filter({ hasText: 'Studio Browser Exception' });
    await expect(row).toBeVisible();
    await expect(row.locator('[data-recipient-source-label]')).toHaveText('Manual');
    expect(await page.evaluate(() => window.studioDocumentMarker)).toBe('same-studio-document');
    await row.locator('[data-preview-select]').click();
    await page.getByRole('spinbutton', { name: 'Vertical position', exact: true }).fill('47');
    await page.getByRole('spinbutton', { name: 'Vertical position', exact: true }).blur();
    await row.locator('[data-queue-check]').check();
    await page.getByRole('button', { name: 'Refresh recipients', exact: true }).click();
    await expect(row.locator('[data-queue-check]')).toBeChecked();
    await expect(page.getByRole('spinbutton', { name: 'Vertical position', exact: true })).toHaveValue('47');
    await page.getByRole('button', { name: 'Add Recipient Manually', exact: true }).click();
    await dialog.getByLabel('Full Name', { exact: true }).fill('Studio Browser Exception');
    await dialog.getByLabel('Email Address', { exact: true }).fill('STUDIO-EXCEPTION@example.test');
    await dialog.getByRole('button', { name: 'Add to Studio', exact: true }).click();
    await expect(dialog.locator('#manual-email-error')).toContainText('already added manually');
    await expect(dialog.getByLabel('Email Address', { exact: true })).toHaveValue('STUDIO-EXCEPTION@example.test');
    await page.keyboard.press('Escape');
    await expect(page.getByRole('button', { name: 'Add Recipient Manually', exact: true })).toBeFocused();
    await page.route('**/certificates/studio/manual', (route) => route.abort('failed'));
    await page.getByRole('button', { name: 'Add Recipient Manually', exact: true }).click();
    await dialog.getByLabel('Full Name', { exact: true }).fill('Network Recovery Person');
    await dialog.getByLabel('Email Address', { exact: true }).fill('network-recovery@example.test');
    await dialog.getByRole('button', { name: 'Add to Studio', exact: true }).click();
    await expect(dialog.locator('[data-manual-error]')).toContainText('Connection interrupted');
    await expect(dialog.getByLabel('Full Name', { exact: true })).toHaveValue('Network Recovery Person');
    await expect(dialog.getByRole('button', { name: 'Add to Studio', exact: true })).toBeEnabled();
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await page.unroute('**/certificates/studio/manual');
    await row.getByRole('button', { name: 'Edit recipient', exact: true }).click();
    await dialog.getByLabel('Full Name', { exact: true }).fill('Corrected Browser Exception');
    await dialog.getByLabel('Email Address', { exact: true }).fill('corrected-studio@example.test');
    await dialog.getByRole('button', { name: 'Save changes', exact: true }).click();
    const corrected = page.locator('[data-certificate-row]').filter({ hasText: 'Corrected Browser Exception' });
    await expect(corrected).toBeVisible();
    await expect(corrected).toContainText('corrected-studio@example.test');
    await expect(page.locator('[data-cert-name]')).toHaveText('Corrected Browser Exception');
    await page.locator('[data-open-send-confirm]').click();
    await expect(page.locator('[data-send-confirm]')).toBeVisible();
    await page.locator('[data-cancel-send]').click();
    await expect(corrected.locator('[data-status-badge]')).toHaveText('Ready');
    await page.getByLabel('Recipient source', { exact: true }).selectOption('manual');
    await expect(page.locator('[data-certificate-row][data-source="eligible"]')).toHaveCount(0);
    await page.getByRole('textbox', { name: 'Search recipients', exact: true }).fill('no-such-recipient-579');
    await expect(page.locator('[data-certificate-list]')).toContainText('No recipients match');
    await page.getByRole('textbox', { name: 'Search recipients', exact: true }).fill('corrected-studio@');
    await expect(corrected).toBeVisible();
    await corrected.getByRole('button', { name: 'Remove', exact: true }).click();
    await expect(corrected).toHaveCount(0);
    expect(await page.evaluate(() => window.studioDocumentMarker)).toBe('same-studio-document');
    expect(errors).toEqual([]);
});

test('new form eligibility reaches an already-open Studio through its automatic roster refresh', async ({ page, context }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    const urls = [];
    for (const id of [1, 3]) {
        await page.goto(`/admin/webinars/1/forms/${id}/edit`);
        urls.push(await page.locator('input[readonly]').first().inputValue());
    }
    await page.goto('/admin/webinars/1/responses?view=settings');
    for (const type of ['registration', 'posttest']) await page.locator(`input[name="rules[${type}][enabled]"]`).check();
    for (const type of ['pretest', 'evaluation', 'attendance']) await page.locator(`input[name="rules[${type}][enabled]"]`).uncheck();
    await page.getByRole('button', { name: 'Save eligibility settings' }).click();
    await page.clock.install();
    await page.goto('/admin/webinars/1/certificates/studio');
    await page.evaluate(() => { window.studioDocumentMarker = 'automatic-studio'; });
    const participant = await context.newPage();
    const row = page.locator('[data-certificate-row]').filter({ hasText: 'Live Eligible Browser Person' });
    for (const [index, url] of urls.entries()) {
        await participant.goto(url);
        await participant.getByLabel(/Full name/).fill('Live Eligible Browser Person');
        await participant.getByLabel('Email address', { exact: false }).fill('live-eligibility@example.test');
        await participant.getByRole('radio', { name: 'Very useful', exact: true }).check();
        await participant.locator('input[name="privacy_acknowledged"]').check();
        await participant.getByRole('button', { name: 'Submit', exact: true }).click();
        await expect(participant.getByText('Your response has been recorded', { exact: false })).toBeVisible();
        await page.bringToFront();
        const refreshed = page.waitForResponse((response) => response.url().includes('/studio/recipients') && response.ok());
        await page.clock.fastForward(31000);
        await refreshed;
        if (index === 0) await expect(row).toHaveCount(0);
        else { await expect(row).toBeVisible(); await expect(row.locator('[data-recipient-source-label]')).toHaveText('Eligible'); }
    }
    expect(await page.evaluate(() => window.studioDocumentMarker)).toBe('automatic-studio');
    await participant.close();
});

test('roster pagination after asynchronous Studio entry preserves the open editor', async ({ page }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto('/admin/webinars/1/responses');
    await page.getByRole('link', { name: 'Certificate Studio', exact: true }).filter({ visible: true }).click();
    await expect(page.locator('[data-studio]')).toBeVisible();
    const dialog = page.locator('[data-manual-recipient-dialog]');
    await page.getByRole('button', { name: 'Add Recipient Manually', exact: true }).click();
    await dialog.getByLabel('Full Name', { exact: true }).fill('Pagination Browser Recipient');
    await dialog.getByLabel('Email Address', { exact: true }).fill('pagination-browser@example.test');
    await dialog.getByRole('button', { name: 'Add to Studio', exact: true }).click();
    const row = page.locator('[data-certificate-row]').filter({ hasText: 'pagination-browser@example.test' });
    await expect(row).toBeVisible();
    await row.locator('[data-preview-select]').click();
    await page.getByRole('spinbutton', { name: 'Vertical position', exact: true }).fill('47');
    await page.getByRole('spinbutton', { name: 'Vertical position', exact: true }).blur();
    await page.evaluate(() => {
        window.paginationStudio = document.querySelector('[data-studio]');
        window.paginationRenders = 0;
        document.addEventListener('app:before-render', () => { window.paginationRenders++; });
    });
    // The small fixture does not need 201 identities just to expose pagination.
    // Add its native anchor; both page requests still use the real server roster.
    async function pageLink(number) {
        await page.locator('[data-recipient-pagination]').evaluate((pager, number) => {
            const link = document.createElement('a');
            link.href = `/admin/webinars/1/certificates/studio?page=${number}`;
            link.textContent = `Fixture roster page ${number}`;
            pager.append(link);
        }, number);
        const response = page.waitForResponse((response) => response.request().method() === 'POST'
            && response.url().includes(`/studio/recipients?page=${number}`) && response.ok());
        await page.getByRole('link', { name: `Fixture roster page ${number}`, exact: true }).click();
        await response;
        await expect(page.getByRole('link', { name: `Fixture roster page ${number}`, exact: true })).toHaveCount(0);
        await expect(page.getByRole('dialog', { name: 'Leave unsaved changes?' })).toHaveCount(0);
        expect(await page.evaluate(() => document.querySelector('[data-studio]') === window.paginationStudio)).toBe(true);
        expect(await page.evaluate(() => window.paginationRenders)).toBe(0);
    }
    await pageLink(2);
    await pageLink(1);
    await row.locator('[data-preview-select]').click();
    await expect(page.getByRole('spinbutton', { name: 'Vertical position', exact: true })).toHaveValue('47');
});

test('hanging manual saves and roster refreshes time out and recover without replaying a mutation', async ({ page }) => {
    await page.clock.install();
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto('/admin/webinars/1/certificates/studio');
    const dialog = page.locator('[data-manual-recipient-dialog]');
    let manualRequests = 0;
    let manualRoute;
    await page.route('**/certificates/studio/manual', (route) => { manualRoute = route; manualRequests++; });
    await page.getByRole('button', { name: 'Add Recipient Manually', exact: true }).click();
    await dialog.getByLabel('Full Name', { exact: true }).fill('Timeout Browser Recipient');
    await dialog.getByLabel('Email Address', { exact: true }).fill('timeout-browser@example.test');
    await dialog.getByRole('button', { name: 'Add to Studio', exact: true }).click();
    await expect.poll(() => manualRequests).toBe(1);
    await expect(dialog.getByRole('button', { name: 'Add to Studio', exact: true })).toBeDisabled();
    await page.clock.fastForward(120001);
    await expect(dialog.locator('[data-manual-error]')).toContainText('may have completed');
    await expect(dialog.getByLabel('Full Name', { exact: true })).toHaveValue('Timeout Browser Recipient');
    await expect(dialog.getByRole('button', { name: 'Add to Studio', exact: true })).toBeEnabled();
    await expect(dialog.locator('form')).not.toHaveAttribute('aria-busy');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await expect(dialog).not.toBeVisible();
    expect(manualRequests).toBe(1);
    await manualRoute.abort();
    await page.unroute('**/certificates/studio/manual');

    let rosterRoute;
    let rosterRequests = 0;
    await page.route('**/certificates/studio/recipients*', (route) => { rosterRoute = route; rosterRequests++; });
    await page.getByRole('button', { name: 'Refresh recipients', exact: true }).click();
    await expect.poll(() => rosterRequests).toBe(1);
    // An open dialog pauses background polling so it cannot supersede this read.
    await page.getByRole('button', { name: 'Add Recipient Manually', exact: true }).click();
    await page.clock.fastForward(30001);
    await expect(page.locator('[data-recipient-refresh-error]')).toContainText('took too long');
    await expect(page.locator('[data-certificate-list]')).not.toHaveAttribute('aria-busy');
    await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
    await rosterRoute.abort();
    await page.unroute('**/certificates/studio/recipients*');
    const refreshed = page.waitForResponse((response) => response.url().includes('/studio/recipients') && response.ok());
    await page.getByRole('button', { name: 'Refresh recipients', exact: true }).click();
    await refreshed;
    await expect(page.locator('[data-recipient-refresh-error]')).toBeHidden();
});
