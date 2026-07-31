<?php

declare(strict_types=1);

/**
 * Boot configuration read from env vars with defaults.
 * Business configuration lives in the `settings` DB table (SPEC §10).
 */

$env = static function (string $key, $default = null) {
    $value = $_ENV[$key] ?? getenv($key);
    if ($value === false || $value === null || $value === '') {
        return $default;
    }
    return $value;
};

$appEnv = (string) $env('APP_ENV', 'local');
$jwtSecret = (string) $env('JWT_SECRET', '');

if ($jwtSecret === '') {
    if ($appEnv === 'production') {
        fwrite(STDERR, "FATAL: JWT_SECRET must be set in production.\n");
        exit(1);
    }
    // Deterministic fallback for local/test only.
    $jwtSecret = 'vlab-dev-secret-not-for-production-0123456789abcdef';
}

$debugRaw = $env('APP_DEBUG', 'true');
$debug = filter_var($debugRaw, FILTER_VALIDATE_BOOLEAN);

return [
    'app' => [
        'name' => 'vlab',
        'version' => '1.0.0',
        'env' => $appEnv,
        'debug' => $debug,
        'url' => (string) $env('APP_URL', 'http://localhost:8081'),
        'frontend_url' => (string) $env('APP_FRONTEND_URL', 'http://localhost:8080'),
    ],
    'jwt' => [
        'secret' => $jwtSecret,
        'algo' => (string) $env('JWT_ALGO', 'HS256'),
        'issuer' => (string) $env('JWT_ISSUER', 'vlab'),
    ],
    'ldap' => [
        'mode_env' => (string) ($env('LDAP_MODE', '') ?? ''),
        'host' => (string) $env('LDAP_HOST', ''),
        'port' => (int) $env('LDAP_PORT', 389),
        'encryption' => (string) $env('LDAP_ENCRYPTION', 'none'),
        'base_dn' => (string) $env('LDAP_BASE_DN', ''),
        'bind_dn' => (string) $env('LDAP_BIND_DN', ''),
        'bind_password' => (string) $env('LDAP_BIND_PASSWORD', ''),
    ],
    'storage' => [
        'path' => (string) $env('STORAGE_PATH', 'storage'),
        'upload_max_bytes' => (int) $env('UPLOAD_MAX_BYTES', 10485760),
    ],
    'log' => [
        'level' => (string) $env('LOG_LEVEL', 'debug'),
    ],
];
