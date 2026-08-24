import { defineConfig, devices } from '@playwright/test';

/*
 * End-to-end tests for the certificate editor. They drive the real browser
 * against the rendered studio snapshot at /_preview/studio.html (written by
 * tests/Feature/ZzPreviewSnapshotTest.php), so no admin login or 2FA is needed.
 *
 * Run with `npm run test:e2e`, which regenerates the snapshot first. An already
 * running dev server on port 8000 is reused.
 */
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    fullyParallel: true,
    use: {
        baseURL: 'http://localhost:8000',
        trace: 'on-first-retry',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: {
        command: 'php artisan serve --port=8000',
        url: 'http://localhost:8000/_preview/studio.html',
        reuseExistingServer: true,
        timeout: 60_000,
    },
});
