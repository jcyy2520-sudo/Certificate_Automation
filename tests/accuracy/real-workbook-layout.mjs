// Opt-in only. Real HTML/cells stay in child-process/browser memory. No server,
// traces, screenshots, video, DOM snapshots, raw error logs or real-data fixture.
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { createDecipheriv, createHash, randomBytes } from 'node:crypto';
import fs from 'node:fs/promises';
import path from 'node:path';
import { chromium } from '@playwright/test';

const execute = promisify(execFile);
const root = process.cwd();
const evidence = path.join(root, 'storage/framework/testing/production-final-20261024');
const widths = [320, 390, 768, 1280, 3840];
const origin = 'http://accuracy.test';
const digest = value => createHash('sha256').update(value).digest('hex');
let browser;
let pages = [];
const proof = { status: 'running', widths, themes: ['light', 'dark'], pages: 0,
    pageLayouts: 0, textChecks: 0, labelChecks: 0, disclosureChecks: 0,
    documentOverflow: 0, failures: [], runtimeErrorDigests: [], blockedRequests: 0,
    privacy: { realHtmlOnDisk: false, realScreenshots: false, trace: false, video: false, networkAllowed: false } };

try {
    if (!process.env.REAL_RESPONSE_FILES_DIRECTORY) throw new Error('Explicit real-workbook opt-in is required.');
    await fs.mkdir(evidence, { recursive: true });
    const key = randomBytes(32);
    const result = await execute(process.env.REAL_RESPONSE_PHP_BINARY || 'php', ['artisan', 'test', 'tests/Feature/RealResponseFileAccuracyTest.php', '--no-ansi'], {
        cwd: root, encoding: 'utf8', maxBuffer: 96 * 1024 * 1024,
        env: { ...process.env, TEST_TOKEN: 'real-private-browser-' + randomBytes(6).toString('hex'), REAL_RESPONSE_BROWSER_CAPTURE: '1',
            REAL_RESPONSE_BROWSER_KEY: key.toString('base64'),
            REAL_RESPONSE_ACCURACY_REPORT: path.join(evidence, 'realfiles-browser-source.json') },
    });
    const match = result.stdout.match(/^REAL_WORKBOOK_BROWSER_BUNDLE:([A-Za-z0-9+/=]+)$/m);
    if (!match) throw new Error('The private page pipe was not produced.');
    const packet = Buffer.from(match[1], 'base64');
    const decipher = createDecipheriv('aes-256-gcm', key, packet.subarray(0, 12));
    decipher.setAuthTag(packet.subarray(12, 28));
    decipher.setAAD(Buffer.from('certimate-real-workbook-layout-v1'));
    const plaintext = Buffer.concat([decipher.update(packet.subarray(28)), decipher.final()]);
    pages = JSON.parse(plaintext.toString('utf8'));
    plaintext.fill(0); packet.fill(0); key.fill(0);
    proof.pages = pages.length;
    proof.pageKinds = pages.reduce((counts, item) => ({ ...counts, [item.kind]: (counts[item.kind] || 0) + 1 }), {});
    // Keep the original 51-page inventory intact, then account for every new
    // anonymous response and literal-summary page using source-oracle totals.
    const sourceProof = JSON.parse(await fs.readFile(path.join(evidence, 'realfiles-browser-source.json'), 'utf8'));
    const expectedIds = [];
    for (const [type, lastPreview, responsePages] of [['registration', 48, 9], ['pretest', 26, 4], ['posttest', 25, 4]]) {
        expectedIds.push(type + '-preview-1', type + '-preview-' + lastPreview, type + '-held');
        for (let index = 1; index <= responsePages; index++) expectedIds.push(type + '-responses-' + index);
    }
    for (let index = 1; index <= 25; index++) expectedIds.push('evaluation-preview-' + index);
    if (expectedIds.length !== 51) throw new Error('The original source-page inventory definition changed.');
    const evaluation = sourceProof.files?.evaluation;
    if (evaluation?.unattributed_saved_rows !== 124 || evaluation.rendered_unattributed_rows !== 124
        || evaluation.rendered_unattributed_pages !== 5 || evaluation.unattributed_details_verified !== 124
        || evaluation.exported_unattributed_rows !== 124 || evaluation.individual_completions !== 0
        || evaluation.literal_summaries?.length !== 6) throw new Error('The anonymous source verification inventory is incomplete.');
    for (let index = 1; index <= 5; index++) expectedIds.push('evaluation-unattributed-responses-' + index);
    expectedIds.push('evaluation-unattributed-detail');
    for (let index = 0; index < 6; index++) {
        const summary = evaluation.literal_summaries[index];
        if (summary.column !== index + 1 || summary.source_values !== 124 || summary.distinct_values < 1
            || summary.rendered_pages !== Math.ceil(summary.distinct_values / 25)) throw new Error('A literal-summary source inventory is incomplete.');
        for (let page = 1; page <= summary.rendered_pages; page++) expectedIds.push('evaluation-unattributed-summary-' + summary.column + '-' + page);
    }
    const actualIds = pages.map(item => item.id);
    if (actualIds.length !== expectedIds.length || new Set(actualIds).size !== actualIds.length
        || expectedIds.some(id => !actualIds.includes(id))) throw new Error('The exact source-page inventory is incomplete.');
    proof.originalPages = 51;
    proof.addedUnattributedPages = expectedIds.length - 51;

    const manifest = JSON.parse(await fs.readFile(path.join(root, 'public/build/manifest.json'), 'utf8'));
    const assets = new Set();
    for (const item of Object.values(manifest)) {
        for (const filename of [item.file, ...(item.css || []), ...(item.assets || [])].filter(Boolean)) assets.add('/build/' + filename);
    }
    for (const filename of ['favicon.ico', 'favicon-16x16.png', 'favicon-32x32.png', 'apple-touch-icon.png',
        'android-chrome-192x192.png', 'android-chrome-512x512.png', 'site.webmanifest', 'brand/certimate-mark.webp']) assets.add('/' + filename);
    const mime = { '.js': 'application/javascript', '.css': 'text/css', '.woff2': 'font/woff2', '.png': 'image/png',
        '.webp': 'image/webp', '.ico': 'image/x-icon', '.webmanifest': 'application/manifest+json' };
    const assetBytes = new Map();
    for (const asset of assets) assetBytes.set(asset, await fs.readFile(path.join(root, 'public', asset.slice(1))));
    browser = await chromium.launch({ headless: true });
    const context = await browser.newContext({ serviceWorkers: 'block', acceptDownloads: false });
    await context.route('**/*', async route => {
        const request = route.request();
        const url = new URL(request.url());
        const pageMatch = url.origin === origin && url.pathname.match(/^\/case\/(\d+)$/);
        const headers = { 'Cache-Control': 'no-store', 'Access-Control-Allow-Origin': '*', 'X-Content-Type-Options': 'nosniff' };
        if (request.method() === 'GET' && pageMatch && pages[Number(pageMatch[1])]) {
            return route.fulfill({ status: 200, contentType: 'text/html; charset=utf-8', headers, body: pages[Number(pageMatch[1])].html });
        }
        if (request.method() === 'GET' && ['accuracy.test', 'localhost', '127.0.0.1'].includes(url.hostname) && assetBytes.has(url.pathname)) {
            return route.fulfill({ status: 200, contentType: mime[path.extname(url.pathname)] || 'application/octet-stream', headers, body: assetBytes.get(url.pathname) });
        }
        proof.blockedRequests++;
        return route.abort('blockedbyclient');
    });
    const page = await context.newPage();
    page.on('pageerror', error => proof.runtimeErrorDigests.push(digest(error.message)));
    for (let pageIndex = 0; pageIndex < pages.length; pageIndex++) {
        const source = pages[pageIndex];
        await page.goto(origin + '/case/' + pageIndex, { waitUntil: 'networkidle' });
        await page.evaluate(() => document.fonts.ready);
        const fontsLoaded = await page.evaluate(() => getComputedStyle(document.body).fontFamily.includes('Inter')
            && [...document.fonts].some(font => font.status === 'loaded'));
        if (!fontsLoaded) proof.failures.push({ page: source.id, category: 'font-not-loaded' });
        for (const width of widths) {
            await page.setViewportSize({ width, height: width >= 1920 ? 2160 : 900 });
            for (const theme of proof.themes) {
                const result = await page.evaluate(({ checks, theme, width }) => {
                    document.documentElement.classList.toggle('dark', theme === 'dark');
                    document.documentElement.style.colorScheme = theme;
                    const failures = [];
                    let textChecks = 0, labelChecks = 0, disclosureChecks = 0;
                    const fail = (check, category) => failures.push({ row: check.row, column: check.column, category });
                    for (const check of checks) {
                        let node = document.querySelectorAll(check.selector)[check.index || 0];
                        if (!node) { fail(check, 'missing-cell'); continue; }
                        const cell = node;
                        if (check.content === 'answer') {
                            if (node.dataset.label !== check.label) fail(check, 'source-label');
                            labelChecks++;
                            if (width <= 640) {
                                const before = getComputedStyle(node, '::before');
                                if (before.content === 'none' || before.display === 'none') fail(check, 'hidden-phone-label');
                            }
                            const details = node.querySelector('details');
                            if (details) { details.open = true; disclosureChecks++; }
                            node = details?.querySelector('p') || node.querySelector('span');
                        } else if (check.content === 'held') {
                            node.open = true;
                            node = node.querySelectorAll('dl dd')[check.cell];
                            disclosureChecks++;
                        } else if (check.content === 'literal') {
                            const details = node.querySelector('details');
                            if (details) { details.open = true; disclosureChecks++; }
                            node = details?.querySelector('p') || node.querySelector('span');
                        }
                        if (!node) { fail(check, 'missing-value'); continue; }
                        textChecks++;
                        if (node.textContent !== check.expected) fail(check, 'text-mismatch');
                        const style = getComputedStyle(node);
                        const box = node.getBoundingClientRect();
                        // Desktop headings are replaced by per-cell labels on phones.
                        const hiddenHeading = width <= 640 && node.closest('.response-data-table thead');
                        if (!hiddenHeading && (box.width <= 0 || box.height <= 0 || style.visibility === 'hidden' || style.display === 'none')) fail(check, 'hidden-value');
                        if (!hiddenHeading && /(?:^\s|\s$|[ \t]{2,}|[\r\n])/.test(check.expected)
                            && !['pre', 'pre-wrap', 'break-spaces'].includes(style.whiteSpace)) fail(check, 'collapsed-whitespace');
                        if (!hiddenHeading && (cell.scrollWidth > cell.clientWidth + 2) && ['hidden', 'clip'].includes(getComputedStyle(cell).overflowX)) fail(check, 'clipped-cell');
                    }
                    return { failures, textChecks, labelChecks, disclosureChecks,
                        documentOverflow: document.documentElement.scrollWidth > innerWidth + 1 };
                }, { checks: source.checks, theme, width });
                proof.pageLayouts++;
                proof.textChecks += result.textChecks;
                proof.labelChecks += result.labelChecks;
                proof.disclosureChecks += result.disclosureChecks;
                if (result.documentOverflow) { proof.documentOverflow++; proof.failures.push({ page: source.id, width, theme, category: 'document-overflow' }); }
                for (const failure of result.failures) proof.failures.push({ page: source.id, width, theme, ...failure });
            }
        }
        if ((pageIndex + 1) % 10 === 0) console.log(JSON.stringify({ pagesChecked: pageIndex + 1, layoutsChecked: proof.pageLayouts }));
    }
    await context.close();
    proof.status = proof.failures.length === 0 && proof.runtimeErrorDigests.length === 0 ? 'passed' : 'failed';
} catch (error) {
    // Child-process errors may hold captured stdout: never print/stringify them.
    proof.status = 'failed';
    proof.failureClass = error.name;
    proof.failureDigest = digest(error.message || 'unknown');
} finally {
    if (browser) await browser.close();
    pages.length = 0;
    proof.failureCount = proof.failures.length;
    proof.failures = proof.failures.slice(0, 100);
    await fs.mkdir(evidence, { recursive: true });
    await fs.writeFile(path.join(evidence, 'browser-layout.json'), JSON.stringify(proof, null, 2) + '\n');
    console.log(JSON.stringify({ status: proof.status, pages: proof.pages, layouts: proof.pageLayouts, textChecks: proof.textChecks,
        labelChecks: proof.labelChecks, disclosureChecks: proof.disclosureChecks, failures: proof.failureCount,
        runtimeErrors: proof.runtimeErrorDigests.length, failureClass: proof.failureClass }));
    if (proof.status !== 'passed') process.exitCode = 1;
}
