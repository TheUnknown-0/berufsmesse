<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Rate-Limiting für Logins: max. 10 Fehlversuche in 5 Minuten
 * pro Benutzername ODER pro IP-Adresse.
 */
final class LoginThrottle
{
    private const MAX_ATTEMPTS = 10;
    private const WINDOW_MINUTES = 5;

    public function __construct(private readonly Database $db)
    {
    }

    public function isBlocked(string $username, string $ip): bool
    {
        $count = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts
             WHERE attempted_at > (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)
               AND (username = ? OR ip_address = ?)',
            [$username, $ip],
        );

        return $count >= self::MAX_ATTEMPTS;
    }

    public function recordFailure(string $username, string $ip): void
    {
        $this->db->run(
            'INSERT INTO login_attempts (username, ip_address) VALUES (?, ?)',
            [$username, $ip],
        );
        // Gelegentliches Aufräumen alter Einträge
        if (random_int(1, 50) === 1) {
            $this->db->run('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
        }
    }

    public function clear(string $username, string $ip): void
    {
        $this->db->run(
            'DELETE FROM login_attempts WHERE username = ? OR ip_address = ?',
            [$username, $ip],
        );
    }
}
