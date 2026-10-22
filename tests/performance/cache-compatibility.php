<?php

// Verify release caching without loading .env or changing runtime caches/data.
// Run from the application directory: php tests/performance/cache-compatibility.php
require __DIR__.'/../../vendor/autoload.php';

use Symfony\Component\Process\Process;

$base = dirname(__DIR__, 2);
$sandbox = $base.'/storage/framework/testing/cleanup-cache';
foreach ([$sandbox, $sandbox.'/views'] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }
}
$environment = [
    'APP_ENV' => 'testing', 'APP_URL' => 'http://localhost', 'APP_DEBUG' => 'false',
    'APP_KEY' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
    'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:', 'DB_URL' => false,
    'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'TRANSACTIONAL_EMAIL_PROVIDER' => 'log', 'MAIL_MAILER' => 'array', 'BREVO_API_KEY' => '',
    'APP_CONFIG_CACHE' => 'storage/framework/testing/cleanup-cache/config.php', 'APP_ROUTES_CACHE' => 'storage/framework/testing/cleanup-cache/routes.php',
    'APP_PACKAGES_CACHE' => 'storage/framework/testing/cleanup-cache/packages.php', 'APP_SERVICES_CACHE' => 'storage/framework/testing/cleanup-cache/services.php',
    'APP_EVENTS_CACHE' => 'storage/framework/testing/cleanup-cache/events.php', 'VIEW_COMPILED_PATH' => $sandbox.'/views',
];
// Config/route cache commands create a fresh app from bootstrap/app.php. Keep
// that fresh app in this same empty environment directory as well.
file_put_contents($sandbox.'/app.php', '<?php $app = require '.var_export($base.'/bootstrap/app.php', true).';'
    .'$app->useEnvironmentPath(__DIR__); $app->useBootstrapPath(__DIR__); return $app;');
copy($base.'/bootstrap/providers.php', $sandbox.'/providers.php');
try {
    foreach (['config:clear', 'config:cache', 'route:cache', 'view:cache'] as $command) {
        $code = '$app = require '.var_export($sandbox.'/app.php', true).';'
            .'$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);'
            .'exit($kernel->handle(new Symfony\Component\Console\Input\ArrayInput(['
            .var_export($command, true).']), new Symfony\Component\Console\Output\ConsoleOutput));';
        $process = new Process([PHP_BINARY, '-r', 'require '.var_export($base.'/vendor/autoload.php', true).';'.$code], $base, $environment);
        $process->setTimeout(120)->mustRun();
        echo $command.": passed\n";
    }
    $config = require $sandbox.'/config.php';
    if ($config['database']['default'] !== 'sqlite'
        || $config['database']['connections']['sqlite']['database'] !== ':memory:'
        || $config['webinar']['email']['provider'] !== 'log') {
        throw new RuntimeException('The cache check escaped its synthetic configuration.');
    }
    $code = '$app = require '.var_export($sandbox.'/app.php', true).';'
        .'$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);'
        .'foreach (["/" => 302, "/admin/login" => 200, "/admin" => 302, "/cleanup-missing-route" => 404] as $path => $expected) {'
        .'$request = Illuminate\Http\Request::create("http://localhost".$path);'
        .'$response = $kernel->handle($request);'
        .'if ($response->getStatusCode() !== $expected) { throw new RuntimeException("Cached HTTP route failed: ".$path); }'
        .'$kernel->terminate($request, $response); }';
    $process = new Process([PHP_BINARY, '-r', 'require '.var_export($base.'/vendor/autoload.php', true).';'.$code], $base, $environment);
    $process->setTimeout(120)->mustRun();
    echo "Cached HTTP routes: root/login/guest management/404 passed.\n";
    echo "Cached configuration remains SQLite :memory: / log mail. No migration or worker ran.\n";
} finally {
    // Only files created in this dedicated harness directory; no runtime clear.
    foreach (glob($sandbox.'/*.php') as $file) {
        unlink($file);
    }
    foreach (glob($sandbox.'/views/*') as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
}
