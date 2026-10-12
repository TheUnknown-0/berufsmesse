<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Ankündigungen für die Startseite einer Rolle.
 *
 * Zielgruppen (announcements.target_role): all, student, teacher, admin.
 * „admin“ heißt in der Oberfläche „Verwaltung“ und umfasst alle Rollen mit
 * Verwaltungszugang (admin, school_admin, orga). Aussteller sind keine
 * Zielgruppe; „Alle“ meint die Schulgemeinschaft.
 */
final class Announcements
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Zielgruppe, die eine Rolle sieht; null = die Rolle sieht keine Ankündigungen. */
    public static function audienceFor(?string $role): ?string
    {
        return match ($role) {
            'student' => 'student',
            'teacher' => 'teacher',
            'admin', 'school_admin', 'orga' => 'admin',
            default => null,
        };
    }

    /**
     * Aktive, nicht abgelaufene Ankündigungen einer Schule für eine Rolle.
     *
     * @return list<array{id: int|string, title: string, body: ?string, type: string}>
     */
    public function activeFor(int $schoolId, ?string $role): array
    {
        $audience = self::audienceFor($role);
        if ($audience === null) {
            return [];
        }

        return $this->db->fetchAll(
            'SELECT id, title, body, type FROM announcements
             WHERE school_id = ? AND is_active = 1
               AND target_role IN (\'all\', ?)
               AND (expires_at IS NULL OR expires_at > NOW())
             ORDER BY created_at DESC',
            [$schoolId, $audience],
        );
    }
}
