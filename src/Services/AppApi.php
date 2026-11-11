<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Session;

/**
 * Einstieg der App-Schnittstelle (/api/v1/...), aufgerufen aus bootstrap.php.
 *
 * Die Anfrage läuft mit einer Sitzung ohne Cookie (Session::startStateless).
 * Ein gültiges Bearer-Token trägt das Konto dort ein, sodass die vorhandenen
 * Guards (requireLogin, requireSchool, Auth::can) unverändert greifen.
 *
 * Das Seitenpasswort gilt als erfüllt: Ohne Token kommt man nur an den Login,
 * und der prüft das Seitenpasswort selbst (Api\V1Controller::login).
 */
final class AppApi
{
    public const PREFIX = '/api/v1/';

    /** Session-Schlüssel mit der ID des Tokens dieser Anfrage. */
    public const TOKEN_ID_KEY = 'api_token_id';

    /** @param array<string, mixed> $config */
    public static function isAppApiRequest(array $config): bool
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = (string) ($config['app']['base_url'] ?? '');
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return str_starts_with('/' . ltrim($path, '/'), self::PREFIX);
    }

    public static function authenticate(Session $session, Database $db): void
    {
        $session->set('site_authenticated', true);

        $token = self::bearerToken();
        if ($token === null) {
            return;
        }

        $row = (new ApiTokens($db))->resolve($token);
        if ($row === null) {
            return;
        }

        $user = $db->fetchOne('SELECT id, school_id FROM users WHERE id = ?', [(int) $row['user_id']]);
        if ($user === null) {
            return;
        }

        $session->set('user_id', (int) $user['id']);
        $session->set('user_school_id', $user['school_id'] !== null ? (int) $user['school_id'] : null);
        $session->set(self::TOKEN_ID_KEY, (int) $row['id']);
    }

    /** Token aus „Authorization: Bearer …“ (Apache reicht den Header je nach Setup unterschiedlich durch). */
    public static function bearerToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($header === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $name => $value) {
                if (strcasecmp((string) $name, 'Authorization') === 0) {
                    $header = $value;
                    break;
                }
            }
        }

        if (!is_string($header) || preg_match('/^Bearer\s+(\S+)$/i', trim($header), $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}
