import { test, expect } from '@playwright/test';

test('name correction stays bound to its opened recipient across roster updates and newer dialogs', async ({ page, context }) => {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto('/admin/webinars/1/forms/1/edit');
    const registrationUrl = await page.locator('input[readonly]').first().inputValue();
    await page.goto('/admin/webinars/1/responses?view=settings');
    await page.locator('input[name="rules[registration][enabled]"]').check();
    for (const type of ['pretest', 'posttest', 'evaluation', 'attendance']) {
        await page.locator(`input[name="rules[${type}][enabled]"]`).uncheck();
    }
    await page.getByRole('button', { name: 'Save eligibility settings' }).click();
    await expect(page.locator('html')).not.toHaveClass(/is-navigating/);

    const participant = await context.newPage();
    for (const [name, email] of [['Correction Original A', 'correction-a@example.test'], ['Correction Original B', 'correction-b@example.test']]) {
        await participant.goto(registrationUrl);
        await participant.getByLabel(/Full name/).fill(name);
        await participant.getByLabel('Email address', { exact: false }).fill(email);
        await participant.getByRole('radio', { name: 'Very useful', exact: true }).check();
        await participant.locator('input[name="privacy_acknowledged"]').check();
        await participant.getByRole('button', { name: 'Submit', exact: true }).click();
        await expect(participant.getByText('Your response has been recorded', { exact: false })).toBeVisible();
    }
    await participant.close();
    await page.goto('/admin/webinars/1/certificates/studio');
    const original = page.locator('[data-certificate-row]').filter({ hasText: 'correction-a@example.test' });
    const other = page.locator('[data-certificate-row]').filter({ hasText: 'correction-b@example.test' });
    await original.locator('[data-preview-select]').click();
    await page.locator('[data-open-name-correction]').click();
    await page.locator('[data-name-correction-input]').fill('Correction Saved A');
    const otherId = await other.getAttribute('data-certificate-row');
    // Exercise the real roster-refresh listener before submit. An in-flight
    // roster read may change the active preview while this modal stays open.
    await page.evaluate((focusId) => document.dispatchEvent(new CustomEvent('studio:recipients-refreshed', { detail: { focusId } })), otherId);
    await expect(page.locator('[data-cert-name]')).toHaveText('Correction Original B');

    let releaseResponse;
    const heldResponse = new Promise((resolve) => { releaseResponse = resolve; });
    let requestArrived;
    const pendingRequest = new Promise((resolve) => { requestArrived = resolve; });
    await page.route('**/participants/*/name', async (route) => {
        const response = await route.fetch();
        requestArrived();
        await heldResponse;
        await route.fulfill({ response });
    });
    await page.getByRole('button', { name: 'Save corrected name', exact: true }).click();
    await pendingRequest;
    await page.keyboard.press('Escape');
    await other.locator('[data-preview-select]').click();
    await expect(page.locator('[data-cert-name]')).toHaveText('Correction Original B');
    await page.locator('[data-open-name-correction]').click();
    await page.locator('[data-name-correction-input]').fill('Correction Draft B');
    const newerAction = await page.locator('[data-name-correction-form]').getAttribute('action');
    let releaseNewer;
    const newerResponse = new Promise((resolve) => { releaseNewer = resolve; });
    let newerArrived;
    const pendingNewer = new Promise((resolve) => { newerArrived = resolve; });
    await page.route(newerAction, async (route) => {
        newerArrived();
        await newerResponse;
        await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Newer correction needs review.' }) });
    });
    await page.getByRole('button', { name: 'Save corrected name', exact: true }).click();
    await pendingNewer;
    releaseResponse();
    await expect(original.locator('[data-participant-name]')).toHaveText('Correction Saved A');
    await expect(other.locator('[data-participant-name]')).toHaveText('Correction Original B');
    await expect(page.locator('[data-cert-name]')).toHaveText('Correction Original B');
    await expect(page.locator('[data-name-correction-modal]')).toBeVisible();
    await expect(page.locator('[data-name-correction-input]')).toHaveValue('Correction Draft B');
    await expect(page.getByRole('button', { name: 'Save corrected name', exact: true })).toBeDisabled();
    releaseNewer();
    await expect(page.locator('[data-name-correction-error]')).toHaveText('Newer correction needs review.');
    await expect(page.getByRole('button', { name: 'Save corrected name', exact: true })).toBeEnabled();
    await page.keyboard.press('Escape');
    await page.unroute('**/participants/*/name');
    await page.unroute(newerAction);
    await original.locator('[data-preview-select]').click();
    await expect(page.locator('[data-cert-name]')).toHaveText('Correction Saved A');

    // A late failure for a closed session must not write into a newer modal.
    const originalAction = await original.getAttribute('data-name-update-url');
    let releaseFailure;
    const heldFailure = new Promise((resolve) => { releaseFailure = resolve; });
    let failureArrived;
    const pendingFailure = new Promise((resolve) => { failureArrived = resolve; });
    await page.route(originalAction, async (route) => {
        failureArrived();
        await heldFailure;
        await route.fulfill({ status: 422, contentType: 'application/json', body: JSON.stringify({ message: 'Old correction failed.' }) });
    });
    await page.locator('[data-open-name-correction]').click();
    await page.getByRole('button', { name: 'Save corrected name', exact: true }).click();
    await pendingFailure;
    await page.keyboard.press('Escape');
    await other.locator('[data-preview-select]').click();
    await page.locator('[data-open-name-correction]').click();
    await page.locator('[data-name-correction-input]').fill('Untouched newer draft');
    const failedResponse = page.waitForResponse((response) => response.url() === originalAction && response.status() === 422);
    releaseFailure();
    await (await failedResponse).finished();
    await page.evaluate(() => new Promise((resolve) => requestAnimationFrame(resolve)));
    await expect(page.locator('[data-name-correction-modal]')).toBeVisible();
    await expect(page.locator('[data-name-correction-input]')).toHaveValue('Untouched newer draft');
    await expect(page.locator('[data-name-correction-error]')).toBeHidden();
    await page.keyboard.press('Escape');
    await page.reload();
    await expect(page.locator('[data-certificate-row]').filter({ hasText: 'correction-a@example.test' }).locator('[data-participant-name]')).toHaveText('Correction Saved A');
    await expect(page.locator('[data-certificate-row]').filter({ hasText: 'correction-b@example.test' }).locator('[data-participant-name]')).toHaveText('Correction Original B');
});
