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
    // E-Mail-Versand (Anmeldecode, Test-Mail). MAIL_HOST leer = Versand
    // bleibt deaktiviert, Formulare zeigen das entsprechend an.
    'mail' => [
        'host' => $env('MAIL_HOST', ''),
        'port' => (int) $env('MAIL_PORT', '587'),
        'encryption' => $env('MAIL_ENCRYPTION', 'tls'),
        'username' => $env('MAIL_USER', ''),
        'password' => $env('MAIL_PASS', ''),
        'from_address' => $env('MAIL_FROM_ADDRESS', ''),
        'from_name' => $env('MAIL_FROM_NAME', 'Berufsmesse'),
    ],
    // Sicherheitscheck der Server-Umgebung (bin/security-check.php), Abstand
    // in Sekunden. 0 = aus. Braucht ausgehenden Zugriff auf GitHub, php.net,
    // osv.dev und mariadb.org.
    'security_check' => [
        'interval' => (int) $env('SECURITY_CHECK_INTERVAL', '21600'),
    ],
    // Update-Prüfung (Services\UpdateCheck): CHANGELOG.md des Repos, oberste
    // Version = neueste. UPDATE_CHECK_URL=off schaltet die Prüfung ab.
    'updates' => [
        'url' => $env('UPDATE_CHECK_URL', 'https://raw.githubusercontent.com/TheUnknown-0/berufsmesse/main/CHANGELOG.md'),
    ],
];
