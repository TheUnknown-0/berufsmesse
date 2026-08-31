<?php

declare(strict_types=1);

/**
 * Zentrale Konfiguration — liest Umgebungsvariablen mit sinnvollen Defaults.
 */

$env = static fn (string $key, ?string $default = null): ?string => (
    ($v = getenv($key)) !== false && $v !== '' ? $v : $default
);

return [
    'app' => [
        'env' => $env('APP_ENV', 'production'),
        'base_url' => rtrim($env('BASE_URL', '/'), '/'),
        // Secure-Cookie-Flag automatisch bei HTTPS, per Env erzwingbar.
        'secure_cookies' => $env('SECURE_COOKIES') === '1'
            || (($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? 'off') !== 'off'),
    ],
    'db' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'name' => $env('DB_NAME', 'berufsmesse'),
        'user' => $env('DB_USER', 'berufsmesse'),
        'pass' => $env('DB_PASS', ''),
    ],
    'uploads' => [
        'dir' => dirname(__DIR__) . '/uploads',
        'max_logo_bytes' => 2 * 1024 * 1024,
        'max_document_bytes' => 10 * 1024 * 1024,
    ],
];
