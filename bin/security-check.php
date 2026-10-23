<?php

declare(strict_types=1);

/**
 * Sicherheitsprüfung der Server-Umgebung (PHP, Systempakete, MariaDB).
 * Neue hohe oder kritische Funde gehen als Benachrichtigung an alle
 * Global-Admins, das Ergebnis erscheint im Global-Admin.
 *
 * Aufruf: php bin/security-check.php
 * Läuft im Container regelmäßig im Hintergrund, siehe docker-entrypoint.sh
 * (SECURITY_CHECK_INTERVAL).
 */

use App\Core\Database;
use App\Services\Notifications;
use App\Services\SecurityCheck;
use App\Services\Settings;

// Gleicher Autoloader wie src/bootstrap.php, aber ohne Sitzung und View:
// Die Prüfung braucht nur die Datenbank.
spl_autoload_register(static function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($file)) {
        require $file;
    }
});

$config = require dirname(__DIR__) . '/config/config.php';
date_default_timezone_set('Europe/Berlin');
mb_internal_encoding('UTF-8');

$db = new Database($config['db']['host'], $config['db']['name'], $config['db']['user'], $config['db']['pass']);
$settings = new Settings($db);

$check = new SecurityCheck(
    $db,
    $settings,
    new Notifications($db),
    ($config['app']['base_url'] ?? '') . '/global-admin',
);

$state = $check->run();

printf(
    "[Sicherheitscheck] PHP %s: %d Fund(e), %d neu gemeldet, %d Fehler.\n",
    $state['php_version'],
    count($state['findings']),
    $state['notified'],
    count($state['errors']),
);
foreach ($state['findings'] as $finding) {
    printf("  [%s] %s %s: %s (behoben in %s)\n", $finding['severity'], $finding['component'], $finding['id'], $finding['summary'], $finding['fixed'] ?? '-');
}
foreach ([...$state['notes'], ...$state['errors']] as $line) {
    echo '  ', $line, "\n";
}

exit($state['errors'] === [] ? 0 : 1);
