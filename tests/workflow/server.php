<?php

// Refuse to bootstrap Laravel if a browser test loses its isolated environment.
// `artisan serve` reloads .env and filters its child process environment.
$root = dirname(__DIR__, 2);
$expected = str_replace('\\', '/', $root.'/storage/app/private/workflow-browser.sqlite');
if (getenv('APP_ENV') !== 'testing'
    || getenv('DB_CONNECTION') !== 'sqlite'
    || str_replace('\\', '/', (string) getenv('DB_DATABASE')) !== $expected
    || getenv('DB_URL') !== ''
    || getenv('BREVO_API_KEY') !== ''
    || getenv('TRANSACTIONAL_EMAIL_PROVIDER') !== 'log'
    || getenv('ACCOUNT_EMAIL_PROVIDER') !== 'log'
    || getenv('CERTIFICATE_EMAIL_PROVIDER') !== 'log'
    || getenv('MAIL_MAILER') !== 'log'
    || getenv('FILESYSTEM_DISK') !== 'local' || getenv('CERTIFICATE_DISK') !== 'local' || getenv('WEBINAR_DOCUMENT_DISK') !== 'local'
    || str_replace('\\', '/', (string) getenv('LARAVEL_STORAGE_PATH')) !== str_replace('\\', '/', $root.'/storage/framework/testing/workflow-runtime')
    || is_file($root.'/bootstrap/cache/config.php')) {
    http_response_code(503);
    exit('Browser test isolation guard refused to start.');
}

// Playwright waits for this server before running globalSetup. Prepare the
// guarded test-only storage tree before Laravel resolves its compiled views.
foreach (['app/private', 'app/public', 'framework/cache/data', 'framework/sessions', 'framework/views', 'logs'] as $directory) {
    $target = $root.'/storage/framework/testing/workflow-runtime/'.$directory;
    if (! is_dir($target) && ! mkdir($target, 0777, true) && ! is_dir($target)) {
        http_response_code(503);
        exit('Browser test storage could not be prepared.');
    }
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($root.'/public'.rawurldecode($path));
if ($file !== false && str_starts_with($file, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require $root.'/public/index.php';
