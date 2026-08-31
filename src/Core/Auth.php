<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Authentifizierung & Autorisierung.
 *
 * Rollenlogik:
 *  - admin:        globaler Administrator, hat überall alle Rechte.
 *  - school_admin: alle Rechte, aber nur innerhalb der eigenen Schule.
 *  - orga/teacher: Rechte ausschließlich über granulare Berechtigungen
 *                  (user_permissions + Gruppen + Rollen-Defaults).
 *  - student:      keine Admin-Rechte, Zugriff über rollen-spezifische Seiten.
 *  - exhibitor:    global (school_id NULL); Zugriff auf Schulen, in denen
 *                  ein verknüpftes Unternehmen ausstellt.
 */
final class Auth
{
    /** @var array<string, mixed>|null|false false = noch nicht geladen */
    private array|null|false $user = false;

    /** @var list<string>|null Cache der granularen Berechtigungen. */
    private ?array $permissions = null;

    public function __construct(
        private readonly Session $session,
        private readonly Database $db,
    ) {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    /** @return array<string, mixed>|null */
    public function user(): ?array
    {
        if ($this->user === false) {
            $id = $this->session->get('user_id');
            $this->user = is_int($id) || is_string($id)
                ? $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [(int) $id])
                : null;
        }

        return $this->user;
    }

    public function id(): ?int
    {
        $user = $this->user();

        return $user === null ? null : (int) $user['id'];
    }

    public function role(): ?string
    {
        return $this->user()['role'] ?? null;
    }

    public function isAdmin(): bool
    {
        return $this->role() === 'admin';
    }

    /** Startet eine Session für den übergebenen Benutzer (nach erfolgreichem Login). */
    public function loginAs(array $user): void
    {
        $this->session->regenerate();
        $this->session->set('user_id', (int) $user['id']);
        // Unveränderliche Heimat-Schule für spätere Zugriffsprüfungen
        $this->session->set('user_school_id', $user['school_id'] !== null ? (int) $user['school_id'] : null);
        $this->user = false;
        $this->permissions = null;
    }

    public function logout(): void
    {
        $this->session->destroy();
        $this->user = false;
        $this->permissions = null;
    }

    /**
     * Prüft eine granulare Berechtigung im Kontext einer Schule.
     * admin: immer wahr. school_admin: wahr in der eigenen Schule.
     * Sonst: Berechtigung muss explizit vorliegen (direkt, per Gruppe
     * oder als Rollen-Default) UND die Schule muss die eigene sein.
     */
    public function can(string $permission, ?int $schoolId = null): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if ($user['role'] === 'admin') {
            return true;
        }

        $ownSchool = $user['school_id'] !== null ? (int) $user['school_id'] : null;
        if ($schoolId !== null && $ownSchool !== $schoolId) {
            return false;
        }

        if ($user['role'] === 'school_admin') {
            return true;
        }

        return in_array($permission, $this->permissions(), true);
    }

    /** @return list<string> Effektive granulare Berechtigungen des Nutzers. */
    public function permissions(): array
    {
        if ($this->permissions !== null) {
            return $this->permissions;
        }

        $user = $this->user();
        if ($user === null) {
            return $this->permissions = [];
        }

        $direct = $this->db->fetchAll(
            'SELECT permission FROM user_permissions WHERE user_id = ?',
            [(int) $user['id']],
        );
        $viaGroups = $this->db->fetchAll(
            'SELECT pgi.permission
             FROM user_permission_groups upg
             JOIN permission_group_items pgi ON pgi.group_id = upg.group_id
             WHERE upg.user_id = ?',
            [(int) $user['id']],
        );

        $keys = array_merge(
            array_column($direct, 'permission'),
            array_column($viaGroups, 'permission'),
            Permissions::defaultsForRole((string) $user['role']),
        );

        // Nur noch gültige Keys berücksichtigen (Altlasten ignorieren)
        return $this->permissions = array_values(array_unique(
            array_filter($keys, Permissions::exists(...)),
        ));
    }

    /**
     * Darf der Nutzer die gegebene Schule betreten?
     * admin: immer. exhibitor: wenn ein verknüpftes, aktives Unternehmen in
     * einer Edition dieser Schule ausstellt. Sonst: eigene Schule.
     */
    public function hasSchoolAccess(int $schoolId): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if ($user['role'] === 'admin') {
            return true;
        }

        if ($user['role'] === 'exhibitor') {
            return (bool) $this->db->fetchValue(
                'SELECT 1
                 FROM exhibitor_users eu
                 JOIN exhibitors e ON e.id = eu.exhibitor_id
                 JOIN messe_editions me ON me.id = e.edition_id
                 WHERE eu.user_id = ? AND eu.status = \'active\' AND eu.invite_accepted = 1
                   AND me.school_id = ?
                 LIMIT 1',
                [(int) $user['id'], $schoolId],
            );
        }

        return $user['school_id'] !== null && (int) $user['school_id'] === $schoolId;
    }
}
