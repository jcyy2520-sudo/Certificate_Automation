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
    || is_file($root.'/bootstrap/cache/config.php')) {
    http_response_code(503);
    exit('Browser test isolation guard refused to start.');
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$file = realpath($root.'/public'.rawurldecode($path));
if ($file !== false && str_starts_with($file, realpath($root.'/public').DIRECTORY_SEPARATOR) && is_file($file)) {
    return false;
}

require $root.'/public/index.php';
