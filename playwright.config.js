import { defineConfig, devices } from '@playwright/test';

/*
 * End-to-end tests for the certificate editor. They drive the real browser
 * against the rendered studio snapshot at /_preview/studio.html (written by
 * tests/Feature/ZzPreviewSnapshotTest.php), so no admin login or 2FA is needed.
 *
 * Run with `npm run test:e2e`, which regenerates the snapshot first. A dedicated
 * static server refuses PHP/dynamic routes and never reuses the dev server.
 */
export default defineConfig({
    testDir: './tests/e2e',
    timeout: 30_000,
    fullyParallel: true,
    use: {
        baseURL: 'http://localhost:8012',
        trace: 'on-first-retry',
    },
    projects: [
        { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    ],
    webServer: {
        command: 'php -S 127.0.0.1:8012 -t public tests/e2e/static-router.php',
        url: 'http://localhost:8012/_preview/studio.html',
        reuseExistingServer: false,
        timeout: 60_000,
    },
});
