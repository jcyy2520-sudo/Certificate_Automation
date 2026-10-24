import { test, expect } from '@playwright/test';

async function webinar(page, title) {
    await page.goto('/admin/login');
    await page.getByLabel('Email', { exact: true }).fill('workflow@example.test');
    await page.getByLabel('Password', { exact: true }).fill('Workflow-fixture-2026!');
    await page.getByRole('button', { name: 'Sign in', exact: true }).click();
    await page.goto('/admin/webinars/create');
    await page.locator('input[name=title]').fill(title);
    await page.getByRole('button', { name: 'Create webinar', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Overview', exact: true })).toBeVisible();
    return page.url();
}

async function upload(page, url, type, text, filename) {
    await page.goto(`${url}/responses?type=${type}`);
    await page.getByRole('link', { name: 'Import Responses', exact: true }).click();
    await page.getByLabel('Response file', { exact: false }).setInputFiles({ name: filename, mimeType: 'text/csv', buffer: Buffer.from(text) });
    await page.getByRole('button', { name: 'Continue to column mapping' }).click();
    await expect(page.getByRole('heading', { name: 'Confirm columns', exact: true })).toBeVisible();
}

async function dryCheck(page, source) {
    await page.locator('input[name=source_description]').fill(source);
    await page.locator('input[name=source_confirmed]').check();
    await page.getByRole('button', { name: 'Run dry check', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review results', exact: true })).toBeVisible();
}

test('guided evaluations save exact unattributed rows and reuse only reviewed mappings', async ({ page }) => {
    test.setTimeout(150000);
    const failures = [];
    page.on('pageerror', error => failures.push(error.message));
    const url = await webinar(page, 'Synthetic guided evaluation');
    const quoted = value => '"' + value.replaceAll('"', '""') + '"';
    const heading = '  Full feedback question É  ';
    const answer = '  Exact synthetic answer "quoted" \\ with whitespace  ';
    const csv = [ [heading, 'Literal rating'], ...Array.from({ length: 8 }, (_, index) => [answer, String(index % 3)]) ].map(row => row.map(quoted).join(',')).join('\r\n');
    await upload(page, url, 'evaluation', csv, 'synthetic-guided.csv');
    await page.locator('select[name=response_mode]').selectOption('unattributed');
    await page.getByRole('button', { name: 'Use this response type', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Unattributed evaluation', exact: true })).toBeVisible();
    await dryCheck(page, 'Synthetic evaluation reviewed source v1');
    await expect(page.getByRole('heading', { name: '8 ready to import', exact: true })).toBeVisible();
    await page.getByText('Saved column mappings', { exact: false }).first().click();
    await page.getByLabel('Save this reviewed mapping as', { exact: true }).fill('Synthetic evaluation mapping');
    await page.getByRole('button', { name: 'Save mapping', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Review results', exact: true })).toBeVisible();
    await page.reload();
    await expect(page.getByRole('heading', { name: '8 ready to import', exact: true })).toBeVisible();
    await page.locator('input[name=review_confirmed]').check();
    await page.getByRole('button', { name: 'Import reviewed responses', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Unattributed evaluations', exact: true })).toBeVisible();
    await expect(page.locator('.response-data-table tbody tr')).toHaveCount(8);
    expect(await page.locator('.response-answer-cell').first().locator('span').textContent()).toBe(answer);
    expect(await page.locator('th[data-question-column] span').first().textContent()).toBe(heading);
    await page.getByRole('link', { name: 'Inspect original row', exact: true }).first().click();
    await expect(page.locator('body')).toContainText(answer.trim());
    await page.goto(`${url}/evaluation-feedback?view=summary`);
    await expect(page.locator('body')).toContainText('8 responses');
    await page.goto(`${url}/responses?type=evaluation`);
    await expect(page.getByText('No responses to show', { exact: true })).toBeVisible();
    await upload(page, url, 'evaluation', csv.replace('"0"', '"5"'), 'synthetic-guided-revised.csv');
    await page.getByText('Saved column mappings', { exact: false }).first().click();
    await page.getByRole('button', { name: 'Apply Synthetic evaluation mapping', exact: true }).click();
    await expect(page.getByRole('heading', { name: 'Unattributed evaluation', exact: true })).toBeVisible();
    await expect(page.locator('input[name=source_description]')).toHaveValue('');
    await expect(page.locator('input[name=source_confirmed]')).not.toBeChecked();
    await dryCheck(page, 'Synthetic evaluation reviewed source v2');
    await page.locator('input[name=review_confirmed]').check();
    await page.getByRole('button', { name: 'Import reviewed responses', exact: true }).click();
    await expect(page.locator('.response-data-table tbody tr')).toHaveCount(8);
    for (const width of [320, 390, 768, 3840]) {
        await page.setViewportSize({ width, height: 900 });
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
        await expect(page.getByRole('link', { name: 'Inspect original row', exact: true }).last()).toBeVisible();
    }
    expect(failures).toEqual([]);
});

test('held-row filters keep reasons, original cells and correction downloads together', async ({ page }) => {
    test.setTimeout(120000);
    const url = await webinar(page, 'Synthetic guided held rows');
    await upload(page, url, 'registration', 'Name,Email,Feedback\nGood Person,good-guided@example.test,Ready\nDuplicate Person,duplicate-guided@example.test,First answer\nDuplicate Person,duplicate-guided@example.test,Second answer\nInvalid Person,invalid-address,Original invalid response', 'synthetic-held.csv');
    await page.locator('input[name=allow_import_time]').check();
    await dryCheck(page, 'Synthetic held registration source');
    await expect(page.getByRole('heading', { name: '1 ready to import', exact: true })).toBeVisible();
    await page.locator('input[name=review_confirmed]').check();
    await page.getByRole('button', { name: 'Import reviewed responses', exact: true }).click();
    await page.getByRole('link', { name: 'Duplicates (2)', exact: true }).click();
    await expect(page.locator('[data-source-values]')).toHaveCount(2);
    await page.locator('[data-source-values] summary').first().click();
    await expect(page.getByText('Original source cells', { exact: true }).first()).toBeVisible();
    await expect(page.getByRole('region', { name: 'Interpreted outcome', exact: true }).first()).toBeVisible();
    const downloadPromise = page.waitForEvent('download');
    await page.getByRole('link', { name: 'Download correction sheet', exact: true }).click();
    const download = await downloadPromise;
    expect(download.suggestedFilename()).toBe('held-response-corrections.csv');
    await page.getByRole('link', { name: 'Invalid values (1)', exact: true }).click();
    await expect(page.locator('[data-source-values]')).toHaveCount(1);
    await page.locator('[data-source-values] summary').click();
    await expect(page.getByText('Original invalid response', { exact: true })).toBeVisible();
    await page.setViewportSize({ width: 320, height: 900 });
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBe(true);
});
