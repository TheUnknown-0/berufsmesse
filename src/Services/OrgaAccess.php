<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database;
use App\Core\Permissions;

/**
 * Einzige Stelle, die über Zugriffe im Orga-Tool entscheidet.
 *
 * Regeln (vom Nutzer so festgelegt):
 *  - Das Orga-Tool betreten admin, school_admin und orga.
 *  - Teammitglieder sind ausschließlich Konten mit der Rolle orga; jede
 *    Person kann in beliebig vielen Teams sein und beliebig viele leiten.
 *  - Die Teamleitung bearbeitet Ziele, Organisationspunkte und Protokolle
 *    ihres Teams. Mitglieder lesen, kommentieren und setzen den Status der
 *    Punkte, die ihnen zugewiesen sind.
 *  - Verwaltung (ORGA_TOOL_VERWALTEN) darf alles, was eine Teamleitung darf,
 *    in allen Teams, und legt Teams und Zuordnungen an.
 *  - Moderation (ORGA_CHAT_MODERIEREN) bearbeitet und löscht fremde
 *    Nachrichten. admin/school_admin haben beides implizit (Auth::can).
 *
 * Ein Raum (Space) ist ein Team, der Gesamtchat oder die Leitungsrunde.
 */
final class OrgaAccess
{
    /** Rollen, die das Orga-Tool überhaupt betreten. */
    public const ROLES = ['admin', 'school_admin', 'orga'];

    /** Einzige Rolle, die Teams angehören kann. */
    public const MEMBER_ROLE = 'orga';

    /** @var array<int, bool>|null team_id => is_leader */
    private ?array $memberships = null;

    public function __construct(
        private readonly Database $db,
        private readonly Auth $auth,
        private readonly int $schoolId,
        private readonly int $editionId,
    ) {
    }

    public function editionId(): int
    {
        return $this->editionId;
    }

    public function userId(): int
    {
        return (int) $this->auth->id();
    }

    public function mayEnter(): bool
    {
        return in_array($this->auth->role(), self::ROLES, true);
    }

    public function seesAll(): bool
    {
        return $this->auth->can(Permissions::ORGA_TOOL_ALLE_SEHEN, $this->schoolId);
    }

    public function manages(): bool
    {
        return $this->auth->can(Permissions::ORGA_TOOL_VERWALTEN, $this->schoolId);
    }

    public function moderates(): bool
    {
        return $this->auth->can(Permissions::ORGA_CHAT_MODERIEREN, $this->schoolId);
    }

    // ---------------------------------------------------------- Teams

    /** @return array<int, bool> team_id => is_leader (nur Teams dieser Edition) */
    public function memberships(): array
    {
        if ($this->memberships !== null) {
            return $this->memberships;
        }

        $this->memberships = [];
        foreach ($this->db->fetchAll(
            'SELECT m.team_id, m.is_leader
             FROM orga_team_members m
             JOIN orga_teams t ON t.id = m.team_id
             WHERE m.user_id = ? AND t.edition_id = ?',
            [$this->userId(), $this->editionId],
        ) as $row) {
            $this->memberships[(int) $row['team_id']] = (int) $row['is_leader'] === 1;
        }

        return $this->memberships;
    }

    public function isMember(int $teamId): bool
    {
        return array_key_exists($teamId, $this->memberships());
    }

    public function isLeader(int $teamId): bool
    {
        return ($this->memberships()[$teamId] ?? false) === true;
    }

    public function leadsAnyTeam(): bool
    {
        return in_array(true, $this->memberships(), true);
    }

    public function canViewTeam(int $teamId): bool
    {
        return $this->seesAll() || $this->isMember($teamId);
    }

    /** Ziele, Organisationspunkte und Protokolle eines Teams bearbeiten. */
    public function canLeadTeam(int $teamId): bool
    {
        return $this->manages() || $this->isLeader($teamId);
    }

    /** Kommentieren: wer im Team ist oder es leiten darf. */
    public function canCommentInTeam(int $teamId): bool
    {
        return $this->isMember($teamId) || $this->canLeadTeam($teamId);
    }

    /**
     * Status und Checkliste eines Punkts ändern: Leitung immer, Mitglieder
     * nur bei Punkten, die ihnen selbst zugewiesen sind.
     *
     * @param array<string, mixed> $task
     */
    public function canWorkOnTask(array $task): bool
    {
        $teamId = (int) $task['team_id'];
        if ($this->canLeadTeam($teamId)) {
            return true;
        }

        return $this->isMember($teamId)
            && $task['assignee_id'] !== null
            && (int) $task['assignee_id'] === $this->userId();
    }

    /**
     * Team dieser Edition laden (Schul- und Jahrgangsisolation).
     *
     * @return array<string, mixed>|null
     */
    public function findTeam(int $teamId): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM orga_teams WHERE id = ? AND edition_id = ?',
            [$teamId, $this->editionId],
        );
    }

    /** @return list<array<string, mixed>> Teams, die der Nutzer sehen darf. */
    public function visibleTeams(): array
    {
        $teams = $this->db->fetchAll(
            'SELECT * FROM orga_teams WHERE edition_id = ? ORDER BY sort_order, name',
            [$this->editionId],
        );
        if ($this->seesAll()) {
            return $teams;
        }

        return array_values(array_filter(
            $teams,
            fn (array $team): bool => $this->isMember((int) $team['id']),
        ));
    }

    // ---------------------------------------------------------- Räume

    /**
     * Raum aus dem URL-Segment: „gesamt“, „leitung“ oder „team-<id>“.
     *
     * @return array{channel: string, team_id: int|null, key: string, slug: string, title: string, color: string|null}|null
     */
    public function space(string $slug): ?array
    {
        if ($slug === 'gesamt') {
            return ['channel' => 'all', 'team_id' => null, 'key' => 'all', 'slug' => 'gesamt', 'title' => 'Orga-Gesamtchat', 'color' => null];
        }
        if ($slug === 'leitung') {
            return ['channel' => 'leads', 'team_id' => null, 'key' => 'leads', 'slug' => 'leitung', 'title' => 'Leitungsrunde', 'color' => null];
        }
        if (preg_match('/^team-(\d{1,10})$/', $slug, $m) === 1) {
            $team = $this->findTeam((int) $m[1]);
            if ($team === null) {
                return null;
            }

            return self::teamSpace($team);
        }

        return null;
    }

    /**
     * @param array<string, mixed> $team
     * @return array{channel: string, team_id: int, key: string, slug: string, title: string, color: string|null}
     */
    public static function teamSpace(array $team): array
    {
        $id = (int) $team['id'];

        return [
            'channel' => 'team',
            'team_id' => $id,
            'key' => 't' . $id,
            'slug' => 'team-' . $id,
            'title' => (string) $team['name'],
            'color' => (string) $team['color'],
        ];
    }

    /** @param array{channel: string, team_id: int|null} $space */
    public function canReadSpace(array $space): bool
    {
        return match ($space['channel']) {
            'team' => $this->canViewTeam((int) $space['team_id']),
            'all' => $this->mayEnter(),
            'leads' => $this->seesAll() || $this->leadsAnyTeam(),
            default => false,
        };
    }

    /**
     * Schreiben dürfen die Mitglieder des Raums sowie Verwaltung und
     * Moderation (Admins senden überall mit). Reines Einsehen genügt nicht.
     *
     * @param array{channel: string, team_id: int|null} $space
     */
    public function canWriteSpace(array $space): bool
    {
        if (!$this->canReadSpace($space)) {
            return false;
        }
        if ($this->manages() || $this->moderates()) {
            return true;
        }

        return match ($space['channel']) {
            'team' => $this->isMember((int) $space['team_id']),
            'all' => true,
            'leads' => $this->leadsAnyTeam(),
            default => false,
        };
    }

    /**
     * Nachrichten anheften: Moderation überall, Teamleitungen in ihrem Team
     * und in der Leitungsrunde.
     *
     * @param array{channel: string, team_id: int|null} $space
     */
    public function canPinIn(array $space): bool
    {
        if ($this->moderates()) {
            return true;
        }

        return match ($space['channel']) {
            'team' => $this->canLeadTeam((int) $space['team_id']),
            'leads' => $this->leadsAnyTeam() || $this->manages(),
            'all' => $this->manages(),
            default => false,
        };
    }

    /**
     * Beschlüsse und Protokolle anlegen und bearbeiten.
     *
     * @param array{channel: string, team_id: int|null} $space
     */
    public function canWriteNotes(array $space): bool
    {
        return match ($space['channel']) {
            'team' => $this->canLeadTeam((int) $space['team_id']),
            'leads' => $this->manages() || $this->leadsAnyTeam(),
            'all' => $this->manages(),
            default => false,
        };
    }

    /**
     * Alle Räume, die der Nutzer lesen darf: Gesamtchat, ggf. Leitungsrunde,
     * dann die Teams.
     *
     * @return list<array{channel: string, team_id: int|null, key: string, slug: string, title: string, color: string|null}>
     */
    public function readableSpaces(): array
    {
        if (!$this->mayEnter()) {
            return [];
        }

        $spaces = [$this->space('gesamt')];
        $leads = $this->space('leitung');
        if ($this->canReadSpace($leads)) {
            $spaces[] = $leads;
        }
        foreach ($this->visibleTeams() as $team) {
            $spaces[] = self::teamSpace($team);
        }

        return $spaces;
    }

    /**
     * Ungelesene fremde Nachrichten je Raum.
     *
     * @return array<string, int> space_key => Anzahl
     */
    public function unreadCounts(): array
    {
        $spaces = $this->readableSpaces();
        if ($spaces === []) {
            return [];
        }

        $counts = [];
        foreach ($this->db->fetchAll(
            "SELECT m.channel, m.team_id, COUNT(*) AS cnt
             FROM orga_messages m
             LEFT JOIN orga_chat_reads r
                    ON r.user_id = ? AND r.edition_id = m.edition_id
                   AND r.space_key = IF(m.channel = 'team', CONCAT('t', m.team_id), m.channel)
             WHERE m.edition_id = ? AND m.deleted_at IS NULL
               AND (m.user_id IS NULL OR m.user_id <> ?)
               AND m.id > COALESCE(r.last_read_id, 0)
             GROUP BY m.channel, m.team_id",
            [$this->userId(), $this->editionId, $this->userId()],
        ) as $row) {
            $key = $row['channel'] === 'team' ? 't' . (int) $row['team_id'] : (string) $row['channel'];
            $counts[$key] = (int) $row['cnt'];
        }

        $result = [];
        foreach ($spaces as $space) {
            $result[$space['key']] = $counts[$space['key']] ?? 0;
        }

        return $result;
    }

    /**
     * Wer diesen Raum lesen darf (Grundlage für @Erwähnungen und die
     * Vorschlagsliste im Chat).
     *
     * @param array{channel: string, team_id: int|null} $space
     * @return list<array{id: int, username: string, name: string}>
     */
    public function audience(array $space): array
    {
        $users = $this->db->fetchAll(
            "SELECT id, username, firstname, lastname, role FROM users
             WHERE school_id = ? AND role IN ('orga', 'school_admin')
             ORDER BY firstname, lastname, username",
            [$this->schoolId],
        );

        $seeAll = array_flip($this->userIdsWithPermission(Permissions::ORGA_TOOL_ALLE_SEHEN));
        $allowed = match ($space['channel']) {
            'team' => array_flip(array_map('intval', array_column($this->db->fetchAll(
                'SELECT user_id FROM orga_team_members WHERE team_id = ?',
                [(int) $space['team_id']],
            ), 'user_id'))),
            'leads' => array_flip(array_map('intval', array_column($this->db->fetchAll(
                'SELECT DISTINCT m.user_id FROM orga_team_members m
                 JOIN orga_teams t ON t.id = m.team_id
                 WHERE t.edition_id = ? AND m.is_leader = 1',
                [$this->editionId],
            ), 'user_id'))),
            default => null,
        };

        $result = [];
        foreach ($users as $user) {
            $id = (int) $user['id'];
            $ok = $allowed === null
                || $user['role'] === 'school_admin'
                || isset($allowed[$id])
                || isset($seeAll[$id]);
            if ($ok) {
                $result[] = ['id' => $id, 'username' => (string) $user['username'], 'name' => self::displayName($user)];
            }
        }

        return $result;
    }

    /**
     * Orga-Konten der Schule, die ein Recht direkt oder über eine Gruppe
     * tragen (Rollen-Defaults gibt es für orga nicht).
     *
     * @return list<int>
     */
    private function userIdsWithPermission(string $permission): array
    {
        $rows = $this->db->fetchAll(
            "SELECT u.id FROM users u
             JOIN user_permissions up ON up.user_id = u.id
             WHERE u.school_id = ? AND u.role = 'orga' AND up.permission = ?
             UNION
             SELECT u.id FROM users u
             JOIN user_permission_groups upg ON upg.user_id = u.id
             JOIN permission_group_items pgi ON pgi.group_id = upg.group_id
             WHERE u.school_id = ? AND u.role = 'orga' AND pgi.permission = ?",
            [$this->schoolId, $permission, $this->schoolId, $permission],
        );

        return array_map('intval', array_column($rows, 'id'));
    }

    /** @return list<array<string, mixed>> Alle Orga-Konten der Schule (Kandidaten für Teams). */
    public function memberCandidates(): array
    {
        return $this->db->fetchAll(
            'SELECT id, username, firstname, lastname FROM users
             WHERE school_id = ? AND role = ?
             ORDER BY lastname, firstname, username',
            [$this->schoolId, self::MEMBER_ROLE],
        );
    }

    /** @param array<string, mixed> $user */
    public static function displayName(array $user): string
    {
        $name = trim(((string) ($user['firstname'] ?? '')) . ' ' . ((string) ($user['lastname'] ?? '')));

        return $name !== '' ? $name : (string) ($user['username'] ?? 'Unbekannt');
    }
}
