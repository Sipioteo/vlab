<?php

declare(strict_types=1);

// Vendor libs (illuminate/database 9.x, etc.) target PHP 8.1 and emit
// "implicitly marking parameter as nullable" deprecations by the hundreds
// on PHP 8.4/8.5. They are harmless noise, not real errors, so we silence
// only E_DEPRECATED while keeping real errors visible in dev.
error_reporting(E_ALL & ~E_DEPRECATED);
ini_set('display_errors', '1');

// php -S static-file quirk: serve existing files directly (SPEC §14.2 #9).
if (PHP_SAPI === 'cli-server') {
    $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
    $file = __DIR__ . $path;
    if ($path !== '/' && is_file($file)) {
        return false;
    }
}

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/bootstrap.php';

$app = vlab_create_app();
$app->run();
