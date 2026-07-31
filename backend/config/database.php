<?php

declare(strict_types=1);

$env = static function (string $key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
};

$driver = (string) $env('DB_DRIVER', 'sqlite');
$database = (string) $env('DB_DATABASE', 'database/vlab.sqlite');

if ($driver === 'sqlite' && $database !== ':memory:' && !str_starts_with($database, '/')) {
    // Relative path: resolve against the backend root.
    $database = dirname(__DIR__) . '/' . $database;
}

$dbSettings = [
    'driver' => $driver,
    'database' => $database,
    'prefix' => (string) $env('DB_PREFIX', ''),
];

if ($driver === 'mysql') {
    $dbSettings += [
        'host' => (string) $env('DB_HOST', '127.0.0.1'),
        'port' => (int) $env('DB_PORT', 3306),
        'username' => (string) $env('DB_USERNAME', ''),
        'password' => (string) $env('DB_PASSWORD', ''),
        'charset' => (string) $env('DB_CHARSET', 'utf8mb4'),
        'collation' => 'utf8mb4_unicode_ci',
    ];
} elseif ($driver === 'pgsql') {
    $dbSettings += [
        'host' => (string) $env('DB_HOST', '127.0.0.1'),
        'port' => (int) $env('DB_PORT', 5432),
        'username' => (string) $env('DB_USERNAME', ''),
        'password' => (string) $env('DB_PASSWORD', ''),
        'charset' => 'utf8',
        'schema' => 'public',
    ];
} else {
    $dbSettings['foreign_key_constraints'] = true;
}

return $dbSettings;
