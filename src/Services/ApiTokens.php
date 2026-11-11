<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Tokens der App-Schnittstelle (/api/v1/...) und Einmal-Codes für die
 * Übergabe an eine angemeldete Web-Sitzung.
 *
 * In der Datenbank liegt jeweils nur der SHA-256-Hash. Ein Token gilt
 * TOKEN_DAYS Tage ab der Anmeldung, ein Übergabe-Code HANDOFF_SECONDS
 * Sekunden und nur einmal.
 */
final class ApiTokens
{
    public const TOKEN_PREFIX = 'bm_';
    public const TOKEN_DAYS = 180;
    public const HANDOFF_SECONDS = 60;

    /**
     * Session-Schlüssel der Web-Sitzung, die aus der App geöffnet wurde: ID des
     * App-Tokens. Ändert das Konto dort sein Passwort, bleibt dieses Gerät angemeldet.
     */
    public const SESSION_TOKEN_KEY = 'app_token_id';

    /** Session-Schlüssel: Diese Web-Sitzung läuft in der App (Darstellung ohne Kopfzeile/Seitenleiste). */
    public const SESSION_APP_MODE = 'app_mode';

    /** Session-Schlüssel: Theme der App ("light"/"dark") für die eingebetteten Seiten. */
    public const SESSION_APP_THEME = 'app_theme';

    public function __construct(private readonly Database $db)
    {
    }

    /** Legt ein neues Token an und gibt es im Klartext zurück (nur hier sichtbar). */
    public function issue(int $userId, string $deviceName, ?string $ip = null): string
    {
        $token = self::TOKEN_PREFIX . bin2hex(random_bytes(32));
        $this->db->run(
            'INSERT INTO api_tokens (user_id, token_hash, device_name, expires_at, ip_address)
             VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL ? DAY), ?)',
            [$userId, hash('sha256', $token), mb_substr(trim($deviceName), 0, 100), self::TOKEN_DAYS, $ip],
        );

        return $token;
    }

    /**
     * Prüft ein Token. Liefert die Token-Zeile samt user_id, wenn es gültig,
     * nicht widerrufen und nicht abgelaufen ist, sonst null.
     *
     * @return array<string, mixed>|null
     */
    public function resolve(string $token): ?array
    {
        if (!str_starts_with($token, self::TOKEN_PREFIX) || strlen($token) !== strlen(self::TOKEN_PREFIX) + 64) {
            return null;
        }

        $row = $this->db->fetchOne(
            'SELECT * FROM api_tokens
             WHERE token_hash = ? AND revoked_at IS NULL AND expires_at > NOW()',
            [hash('sha256', $token)],
        );
        if ($row === null) {
            return null;
        }

        // Nur grob mitschreiben: höchstens einmal pro Minute ein UPDATE.
        if ($row['last_used_at'] === null || strtotime((string) $row['last_used_at']) < time() - 60) {
            $this->db->run('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?', [(int) $row['id']]);
        }

        return $row;
    }

    public function revoke(int $tokenId): void
    {
        $this->db->run('UPDATE api_tokens SET revoked_at = NOW() WHERE id = ? AND revoked_at IS NULL', [$tokenId]);
    }

    /**
     * Widerruft alle Tokens eines Kontos, z. B. nach einem Passwortwechsel.
     * $exceptTokenId bleibt gültig (das Gerät, auf dem der Wechsel stattfand).
     */
    public function revokeAllForUser(int $userId, ?int $exceptTokenId = null): void
    {
        $this->db->run(
            'UPDATE api_tokens SET revoked_at = NOW() WHERE user_id = ? AND revoked_at IS NULL AND id <> ?',
            [$userId, $exceptTokenId ?? 0],
        );
    }

    // ---------------------------------------------------------- Übergabe an die Web-Sitzung

    /** Legt einen Einmal-Code für $targetPath an und gibt ihn im Klartext zurück. */
    public function createHandoff(int $userId, string $targetPath, ?int $tokenId = null, ?string $theme = null): string
    {
        $code = bin2hex(random_bytes(32));
        $theme = in_array($theme, ['light', 'dark'], true) ? $theme : null;
        $this->db->run(
            'INSERT INTO app_handoffs (user_id, token_id, code_hash, target_path, theme, expires_at)
             VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))',
            [$userId, $tokenId, hash('sha256', $code), $targetPath, $theme, self::HANDOFF_SECONDS],
        );

        return $code;
    }

    /**
     * Löst einen Code genau einmal ein.
     *
     * @return array{user_id: int, token_id: ?int, target_path: string, theme: ?string}|null
     */
    public function consumeHandoff(string $code): ?array
    {
        if (preg_match('/^[0-9a-f]{64}$/', $code) !== 1) {
            return null;
        }

        return $this->db->transaction(function () use ($code): ?array {
            $row = $this->db->fetchOne(
                'SELECT * FROM app_handoffs
                 WHERE code_hash = ? AND used_at IS NULL AND expires_at > NOW()
                 FOR UPDATE',
                [hash('sha256', $code)],
            );
            if ($row === null) {
                return null;
            }
            $this->db->run('UPDATE app_handoffs SET used_at = NOW() WHERE id = ?', [(int) $row['id']]);

            return [
                'user_id' => (int) $row['user_id'],
                'token_id' => $row['token_id'] !== null ? (int) $row['token_id'] : null,
                'target_path' => (string) $row['target_path'],
                'theme' => $row['theme'],
            ];
        });
    }

    /** Erlaubt nur Pfade innerhalb der Anwendung („/…“, kein „//“, kein Backslash). */
    public static function safePath(mixed $path): ?string
    {
        if (!is_string($path) || $path === '' || !str_starts_with($path, '/')
            || str_starts_with($path, '//') || str_contains($path, '\\')
            || preg_match('/[\x00-\x1f\x7f]/', $path) === 1 || strlen($path) > 500) {
            return null;
        }

        return $path;
    }
}
