import { test, expect } from '@playwright/test';

test('full logo keeps the same size when switching between light and dark themes', async ({ page }) => {
    for (const width of [320, 390, 768, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        await page.goto('/admin/login');
        const sizes = [];
        for (const theme of ['light', 'dark']) {
            await page.evaluate(value => document.documentElement.classList.toggle('dark', value === 'dark'), theme);
            await page.evaluate(() => document.fonts.ready);
            const brand = page.locator('[data-brand="full"]');
            const box = await brand.boundingBox();
            sizes.push(box);
            expect(box.x).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(width);
        }
        expect(Math.abs(sizes[0].width - sizes[1].width)).toBeLessThanOrEqual(1);
        expect(Math.abs(sizes[0].height - sizes[1].height)).toBeLessThanOrEqual(1);
    }
});

test('official Certimate artwork, browser icons and sign-in branding fit light and dark mobile layouts', async ({ page }) => {
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    for (const width of [320, 390, 768, 1280]) {
        await page.setViewportSize({ width, height: 900 });
        for (const theme of ['light', 'dark']) {
            await page.goto('/admin/login');
            await page.evaluate(value => {
                localStorage.setItem('theme', value);
                document.documentElement.classList.toggle('dark', value === 'dark');
            }, theme);
            await expect(page).toHaveTitle('Sign in — Certimate');
            const brand = page.locator('[data-brand=full]');
            await expect(brand).toBeVisible();
            const image = brand.locator('img:visible');
            await expect(image).toHaveCount(1);
            await expect(image).toHaveJSProperty('complete', true);
            expect(await image.evaluate(element => element.naturalWidth)).toBeGreaterThan(0);
            expect(await image.evaluate(element => {
                const canvas = document.createElement('canvas');
                canvas.width = element.naturalWidth;
                canvas.height = element.naturalHeight;
                const context = canvas.getContext('2d');
                context.drawImage(element, 0, 0);
                return [[0, 0], [canvas.width - 1, 0], [0, canvas.height - 1], [canvas.width - 1, canvas.height - 1]]
                    .map(([x, y]) => context.getImageData(x, y, 1, 1).data[3]);
            })).toEqual([0, 0, 0, 0]);
            const box = await brand.boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(0);
            expect(box.x + box.width).toBeLessThanOrEqual(width);
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBe(true);
            await page.screenshot({ path: `storage/framework/testing/certimate-login-${width}-${theme}.png`, fullPage: true });
        }
    }
    const manifest = await page.request.get('/site.webmanifest');
    expect(manifest.status()).toBe(200);
    const metadata = await manifest.json();
    expect(metadata.name).toBe('Certimate');
    for (const icon of metadata.icons) {
        const response = await page.request.get(icon.src);
        expect(response.status()).toBe(200);
        expect(response.headers()['content-type']).toContain('image/png');
    }
    for (const path of ['/favicon.ico', '/favicon-16x16.png', '/favicon-32x32.png', '/apple-touch-icon.png']) {
        expect((await page.request.get(path)).status()).toBe(200);
    }
    expect(errors).toEqual([]);
});
