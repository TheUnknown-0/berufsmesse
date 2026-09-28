<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Fachlogik des Orga-Tools ohne Zugriffsentscheidungen: Kennzahlen,
 * Abschlusstermine, Beschriftungen.
 */
final class OrgaService
{
    public const STATUS = [
        'open' => 'Offen',
        'in_progress' => 'In Arbeit',
        'blocked' => 'Blockiert',
        'done' => 'Erledigt',
    ];

    public const STATUS_BADGES = [
        'open' => '',
        'in_progress' => 'badge-info',
        'blocked' => 'badge-danger',
        'done' => 'badge-success',
    ];

    public const PRIORITY = [
        'low' => 'Niedrig',
        'normal' => 'Normal',
        'high' => 'Hoch',
    ];

    public const PRIORITY_BADGES = [
        'low' => '',
        'normal' => 'badge-primary',
        'high' => 'badge-warning',
    ];

    public const NOTE_KINDS = [
        'protocol' => 'Protokoll',
        'decision' => 'Beschluss',
    ];

    /** Farbauswahl für Teams (passend zur warmen Palette, gut unterscheidbar). */
    public const COLORS = ['#c96442', '#0284c7', '#059669', '#7c3aed', '#d97706', '#db2777', '#475569', '#0d9488'];

    public function __construct(private readonly Database $db)
    {
    }

    /** @return array{deadline: string|null, deadline_label: string|null} */
    public function settings(int $editionId): array
    {
        $row = $this->db->fetchOne(
            'SELECT deadline, deadline_label FROM orga_settings WHERE edition_id = ?',
            [$editionId],
        );

        return [
            'deadline' => $row['deadline'] ?? null,
            'deadline_label' => $row['deadline_label'] ?? null,
        ];
    }

    public function saveSettings(int $editionId, ?string $deadline, ?string $label): void
    {
        $this->db->run(
            'INSERT INTO orga_settings (edition_id, deadline, deadline_label) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE deadline = VALUES(deadline), deadline_label = VALUES(deadline_label)',
            [$editionId, $deadline, $label],
        );
    }

    /**
     * Maßgeblicher Abschlusstermin eines Teams: der eigene, sonst der der Edition.
     *
     * @param array<string, mixed>|null $team
     * @param array{deadline: string|null, deadline_label: string|null} $settings
     * @return array{at: string, label: string}|null
     */
    public static function deadlineFor(?array $team, array $settings): ?array
    {
        if ($team !== null && !empty($team['deadline'])) {
            return ['at' => (string) $team['deadline'], 'label' => 'Abschluss ' . $team['name']];
        }
        if (!empty($settings['deadline'])) {
            return ['at' => (string) $settings['deadline'], 'label' => (string) ($settings['deadline_label'] ?: 'Orga-Abschluss')];
        }

        return null;
    }

    /**
     * Kennzahlen je Team.
     *
     * @param list<int> $teamIds
     * @return array<int, array{members: int, leaders: list<string>, tasks: int, done: int, overdue: int, goals: int, goals_done: int}>
     */
    public function stats(array $teamIds): array
    {
        if ($teamIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($teamIds), '?'));
        $stats = [];
        foreach ($teamIds as $id) {
            $stats[$id] = ['members' => 0, 'leaders' => [], 'tasks' => 0, 'done' => 0, 'overdue' => 0, 'goals' => 0, 'goals_done' => 0];
        }

        foreach ($this->db->fetchAll(
            "SELECT m.team_id, m.is_leader, u.firstname, u.lastname, u.username
             FROM orga_team_members m JOIN users u ON u.id = m.user_id
             WHERE m.team_id IN ({$placeholders})
             ORDER BY u.lastname, u.firstname",
            $teamIds,
        ) as $row) {
            $tid = (int) $row['team_id'];
            $stats[$tid]['members']++;
            if ((int) $row['is_leader'] === 1) {
                $stats[$tid]['leaders'][] = OrgaAccess::displayName($row);
            }
        }

        foreach ($this->db->fetchAll(
            "SELECT team_id,
                    COUNT(*) AS total,
                    SUM(status = 'done') AS done,
                    SUM(status <> 'done' AND due_date IS NOT NULL AND due_date < CURDATE()) AS overdue
             FROM orga_tasks WHERE team_id IN ({$placeholders}) GROUP BY team_id",
            $teamIds,
        ) as $row) {
            $tid = (int) $row['team_id'];
            $stats[$tid]['tasks'] = (int) $row['total'];
            $stats[$tid]['done'] = (int) $row['done'];
            $stats[$tid]['overdue'] = (int) $row['overdue'];
        }

        foreach ($this->db->fetchAll(
            "SELECT team_id, COUNT(*) AS total, SUM(is_done = 1) AS done
             FROM orga_goals WHERE team_id IN ({$placeholders}) GROUP BY team_id",
            $teamIds,
        ) as $row) {
            $tid = (int) $row['team_id'];
            $stats[$tid]['goals'] = (int) $row['total'];
            $stats[$tid]['goals_done'] = (int) $row['done'];
        }

        return $stats;
    }

    /**
     * Ziele eines Teams mit Fortschritt der verknüpften Organisationspunkte.
     *
     * @return list<array<string, mixed>>
     */
    public function goals(int $teamId): array
    {
        return $this->db->fetchAll(
            "SELECT g.*, u.firstname AS done_firstname, u.lastname AS done_lastname, u.username AS done_username,
                    (SELECT COUNT(*) FROM orga_tasks t WHERE t.goal_id = g.id) AS task_total,
                    (SELECT COUNT(*) FROM orga_tasks t WHERE t.goal_id = g.id AND t.status = 'done') AS task_done
             FROM orga_goals g
             LEFT JOIN users u ON u.id = g.done_by
             WHERE g.team_id = ?
             ORDER BY g.is_done, g.sort_order, g.id",
            [$teamId],
        );
    }

    /**
     * Mitglieder eines Teams, Leitungen zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function members(int $teamId): array
    {
        return $this->db->fetchAll(
            'SELECT u.id, u.username, u.firstname, u.lastname, m.is_leader
             FROM orga_team_members m JOIN users u ON u.id = m.user_id
             WHERE m.team_id = ?
             ORDER BY m.is_leader DESC, u.lastname, u.firstname',
            [$teamId],
        );
    }

    /** Ist die Person Mitglied des Teams (Voraussetzung für eine Zuweisung)? */
    public function isTeamMember(int $teamId, int $userId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM orga_team_members WHERE team_id = ? AND user_id = ?',
            [$teamId, $userId],
        ) !== null;
    }

    /**
     * Aussteller der Edition für die Verknüpfung von Organisationspunkten.
     *
     * @return list<array<string, mixed>>
     */
    public function exhibitors(int $editionId): array
    {
        return $this->db->fetchAll(
            'SELECT id, name FROM exhibitors WHERE edition_id = ? ORDER BY name',
            [$editionId],
        );
    }

    /** Prüft, ob ein Aussteller zur Edition gehört. */
    public function exhibitorInEdition(int $exhibitorId, int $editionId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM exhibitors WHERE id = ? AND edition_id = ?',
            [$exhibitorId, $editionId],
        ) !== null;
    }

    /** Ist ein Datum (Y-m-d) überschritten? */
    public static function isOverdue(?string $date, string $status = 'open'): bool
    {
        return $status !== 'done' && $date !== null && $date !== '' && $date < date('Y-m-d');
    }

    /** Liest ein Datumsfeld (Y-m-d) aus einem Formular; ungültig = null. */
    public static function dateOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        return $date !== false && $date->format('Y-m-d') === $value ? $value : null;
    }

    /** Liest ein datetime-local-Feld; ungültig = null. */
    public static function dateTimeOrNull(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);

        return $date !== false ? $date->format('Y-m-d H:i:s') : null;
    }
}
