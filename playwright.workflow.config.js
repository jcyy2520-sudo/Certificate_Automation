import { defineConfig, devices } from '@playwright/test';
import path from 'node:path';

export const workflowEnvironment = {
    ...process.env,
    APP_ENV: 'testing', APP_DEBUG: 'true', APP_URL: 'http://localhost:8011',
    APP_KEY: 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
    DB_CONNECTION: 'sqlite', DB_DATABASE: path.resolve('storage/app/private/workflow-browser.sqlite'),
    DB_URL: '',
    SESSION_DRIVER: 'file', SESSION_COOKIE: 'webinar_workflow_test', SESSION_SECURE_COOKIE: 'false',
    CACHE_STORE: 'array', QUEUE_CONNECTION: 'database', TRANSACTIONAL_EMAIL_PROVIDER: 'log', MAIL_MAILER: 'log',
    MAIL_FROM_ADDRESS: 'organizer@example.test', MAIL_FROM_NAME: 'Webinar Organizing Team',
    SECURITY_REQUIRE_ADMIN_TWO_FACTOR: 'false', SECURITY_REQUIRE_SENSITIVE_ACTION_PASSWORD_CONFIRMATION: 'false',
};

export default defineConfig({
    testDir: './tests/workflow', workers: 1, timeout: 60000,
    outputDir: './storage/framework/testing/workflow-results',
    globalSetup: './tests/workflow/setup.js',
    use: { baseURL: 'http://localhost:8011', trace: 'retain-on-failure' },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
    webServer: { command: 'php artisan serve --port=8011', url: 'http://localhost:8011/admin/login', reuseExistingServer: false, env: workflowEnvironment },
});
