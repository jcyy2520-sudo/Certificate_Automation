<?php

// Snapshot tests serve generated fixtures/assets only. Never dispatch Laravel,
// execute public/index.php, or load the developer's environment or database.
$root = realpath(__DIR__.'/../../public');
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = is_string($path) ? rawurldecode($path) : '';
$file = ! str_contains($path, "\0") ? realpath($root.$path) : false;
if ($file === false || ! is_file($file)
    || ! str_starts_with($file, $root.DIRECTORY_SEPARATOR)
    || ! in_array(strtolower(pathinfo($file, PATHINFO_EXTENSION)), ['html', 'js', 'css', 'json', 'png', 'jpg', 'jpeg', 'svg', 'ico', 'woff', 'woff2', 'ttf'], true)) {
    http_response_code(404);
    header('Content-Type: text/plain');
    echo 'Static test fixture not found.';

    return true;
}

return false;
