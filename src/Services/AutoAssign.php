<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Automatische Zuteilung der Schüler-Anmeldungen auf feste Zeitslots.
 *
 * Feste ("managed") Slots werden IMMER über timeslots.is_managed = 1 und
 * is_break = 0 bestimmt — nie über Slot-Nummern.
 *
 * Phase 1 (assignPending): verteilt vorhandene Wünsche (timeslot_id IS NULL)
 *   nach Priorität und Anmeldezeitpunkt auf freie Slots des Schülers.
 * Phase 2 (fillIncomplete): füllt Schüler mit Lücken automatisch mit
 *   Ausstellern auf, die im jeweiligen Slot noch Kapazität haben.
 * reset(): stellt den Zustand vor der Zuteilung wieder her.
 * simulate(): führt beide Phasen probeweise aus und rollt zurück.
 */
final class AutoAssign
{
    public function __construct(
        private readonly Database $db,
        private readonly Capacity $capacity,
    ) {
    }

    /**
     * IDs der festen Zuteilungsslots einer Edition, in Slot-Reihenfolge.
     *
     * @return list<int>
     */
    public function managedSlotIds(int $editionId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->fetchAll(
                'SELECT id FROM timeslots
                 WHERE edition_id = ? AND is_managed = 1 AND is_break = 0
                 ORDER BY slot_number',
                [$editionId],
            ),
        );
    }

    /**
     * Phase 1: offene Anmeldungen zuteilen.
     *
     * Reihenfolge: Priorität aufsteigend (1 = hoch, NULL zuletzt), danach
     * Anmeldezeitpunkt. Je Anmeldung wird unter den noch freien festen Slots
     * des Schülers derjenige mit der NIEDRIGSTEN Belegung beim gewünschten
     * Aussteller gewählt, sofern dort noch Kapazität frei ist.
     * Anmeldungen von Schülern, deren feste Slots bereits alle belegt sind,
     * werden gelöscht (Überschuss).
     *
     * @return array{assigned: int, deleted: int, skipped: int, slots: int}
     */
    public function assignPending(int $editionId): array
    {
        return $this->db->transaction(function () use ($editionId): array {
            $this->capacity->refresh($editionId);
            $managed = $this->managedSlotIds($editionId);
            $stats = ['assigned' => 0, 'deleted' => 0, 'skipped' => 0, 'slots' => count($managed)];

            if ($managed === []) {
                return $stats;
            }

            // Bereits belegte Slots je Schüler
            $taken = [];
            $assignedRows = $this->db->fetchAll(
                'SELECT user_id, timeslot_id FROM registrations
                 WHERE edition_id = ? AND timeslot_id IS NOT NULL',
                [$editionId],
            );
            foreach ($assignedRows as $row) {
                $taken[(int) $row['user_id']][(int) $row['timeslot_id']] = true;
            }

            $pending = $this->db->fetchAll(
                'SELECT id, user_id, exhibitor_id FROM registrations
                 WHERE edition_id = ? AND timeslot_id IS NULL
                 ORDER BY priority IS NULL, priority ASC, registered_at ASC, id ASC',
                [$editionId],
            );

            foreach ($pending as $row) {
                $registrationId = (int) $row['id'];
                $userId = (int) $row['user_id'];
                $exhibitorId = (int) $row['exhibitor_id'];

                $openSlots = array_values(array_filter(
                    $managed,
                    static fn (int $slotId): bool => !isset($taken[$userId][$slotId]),
                ));

                // Schüler hat bereits alle festen Slots belegt → Überschuss entfernen
                if ($openSlots === []) {
                    $this->db->run(
                        'DELETE FROM registrations WHERE id = ? AND edition_id = ?',
                        [$registrationId, $editionId],
                    );
                    $stats['deleted']++;
                    continue;
                }

                $bestSlot = null;
                $bestUsed = PHP_INT_MAX;
                foreach ($openSlots as $slotId) {
                    if (!$this->capacity->hasFree($editionId, $exhibitorId, $slotId)) {
                        continue;
                    }
                    $used = $this->capacity->used($editionId, $exhibitorId, $slotId);
                    if ($used < $bestUsed) {
                        $bestUsed = $used;
                        $bestSlot = $slotId;
                    }
                }

                // Kein Slot mit freier Kapazität → Wunsch bleibt offen
                if ($bestSlot === null) {
                    $stats['skipped']++;
                    continue;
                }

                $this->db->run(
                    'UPDATE registrations SET timeslot_id = ? WHERE id = ? AND edition_id = ?',
                    [$bestSlot, $registrationId, $editionId],
                );
                $this->capacity->reserve($editionId, $exhibitorId, $bestSlot);
                $taken[$userId][$bestSlot] = true;
                $stats['assigned']++;
            }

            return $stats;
        });
    }

    /**
     * Phase 2: Schüler mit weniger Zuteilungen als feste Slots auffüllen.
     * Es wird je offenem Slot ein aktiver Aussteller mit freier Kapazität
     * gewählt (bevorzugt der mit der geringsten Belegung). Aussteller, bei
     * denen der Schüler bereits angemeldet ist, werden übersprungen
     * (UNIQUE user + exhibitor).
     *
     * @return array{created: int, students: int, skipped: int, slots: int}
     */
    public function fillIncomplete(int $editionId, int $schoolId): array
    {
        return $this->db->transaction(function () use ($editionId, $schoolId): array {
            $this->capacity->refresh($editionId);
            $managed = $this->managedSlotIds($editionId);
            $stats = ['created' => 0, 'students' => 0, 'skipped' => 0, 'slots' => count($managed)];

            if ($managed === []) {
                return $stats;
            }

            $exhibitors = array_map(
                static fn (array $row): int => (int) $row['id'],
                $this->db->fetchAll(
                    'SELECT id FROM exhibitors WHERE edition_id = ? AND active = 1 ORDER BY id',
                    [$editionId],
                ),
            );
            if ($exhibitors === []) {
                return $stats;
            }

            $students = $this->db->fetchAll(
                'SELECT id FROM users
                 WHERE role = \'student\' AND school_id = ? AND edition_id = ?
                 ORDER BY id',
                [$schoolId, $editionId],
            );

            // Vorhandene Zuteilungen und belegte Aussteller je Schüler
            $slotsByUser = [];
            $exhibitorsByUser = [];
            $existing = $this->db->fetchAll(
                'SELECT user_id, exhibitor_id, timeslot_id FROM registrations WHERE edition_id = ?',
                [$editionId],
            );
            foreach ($existing as $row) {
                $userId = (int) $row['user_id'];
                $exhibitorsByUser[$userId][(int) $row['exhibitor_id']] = true;
                if ($row['timeslot_id'] !== null) {
                    $slotsByUser[$userId][(int) $row['timeslot_id']] = true;
                }
            }

            foreach ($students as $student) {
                $userId = (int) $student['id'];
                $touched = false;

                foreach ($managed as $slotId) {
                    if (isset($slotsByUser[$userId][$slotId])) {
                        continue;
                    }

                    $bestExhibitor = null;
                    $bestUsed = PHP_INT_MAX;
                    foreach ($exhibitors as $exhibitorId) {
                        if (isset($exhibitorsByUser[$userId][$exhibitorId])) {
                            continue;
                        }
                        if (!$this->capacity->hasFree($editionId, $exhibitorId, $slotId)) {
                            continue;
                        }
                        $used = $this->capacity->used($editionId, $exhibitorId, $slotId);
                        if ($used < $bestUsed) {
                            $bestUsed = $used;
                            $bestExhibitor = $exhibitorId;
                        }
                    }

                    if ($bestExhibitor === null) {
                        $stats['skipped']++;
                        continue;
                    }

                    $this->db->run(
                        'INSERT INTO registrations
                            (edition_id, user_id, exhibitor_id, timeslot_id, registration_type, priority)
                         VALUES (?, ?, ?, ?, \'automatic\', NULL)',
                        [$editionId, $userId, $bestExhibitor, $slotId],
                    );
                    $this->capacity->reserve($editionId, $bestExhibitor, $slotId);
                    $slotsByUser[$userId][$slotId] = true;
                    $exhibitorsByUser[$userId][$bestExhibitor] = true;
                    $stats['created']++;
                    $touched = true;
                }

                if ($touched) {
                    $stats['students']++;
                }
            }

            return $stats;
        });
    }

    /**
     * Probelauf: führt die Zuteilung wirklich aus, misst das Ergebnis und
     * macht anschließend ALLES rückgängig.
     *
     * So misst die Vorschau exakt das, was auch passieren würde — statt die
     * Verteilungslogik ein zweites Mal nachzubauen, die dann auseinanderdriftet.
     * Der Rollback steht in `finally`; die Methode committet unter keinen
     * Umständen.
     *
     * @return array{before: array<string, mixed>, after: array<string, mixed>,
     *               phase1: array<string, int>, phase2: array<string, int>|null,
     *               unfulfilled: list<array<string, mixed>>}
     */
    public function simulate(int $editionId, int $schoolId, bool $withFill): array
    {
        $pdo = $this->db->pdo();
        if ($pdo->inTransaction()) {
            throw new \RuntimeException('Der Probelauf braucht eine eigene Transaktion.');
        }

        $pdo->beginTransaction();
        try {
            $before = $this->quality($editionId, $schoolId);
            $phase1 = $this->assignPending($editionId);
            $phase2 = $withFill ? $this->fillIncomplete($editionId, $schoolId) : null;
            $after = $this->quality($editionId, $schoolId);
            $unfulfilled = $this->unfulfilledWishes($editionId);

            return [
                'before' => $before,
                'after' => $after,
                'phase1' => $phase1,
                'phase2' => $phase2,
                'unfulfilled' => $unfulfilled,
            ];
        } finally {
            // Nichts von alledem darf bestehen bleiben.
            $pdo->rollBack();
            $this->capacity->refresh($editionId);
        }
    }

    /**
     * Kennzahlen zur Güte der Verteilung.
     *
     * @return array{students: int, wishes: int, assigned: int, open: int,
     *               by_priority: array<int, array{total: int, assigned: int}>,
     *               full_plans: int, partial_plans: int, empty_plans: int,
     *               free_seats: int, slots: int}
     */
    public function quality(int $editionId, int $schoolId): array
    {
        $managed = $this->managedSlotIds($editionId);
        $slotCount = count($managed);

        $totals = $this->db->fetchOne(
            'SELECT COUNT(*) AS wishes,
                    SUM(timeslot_id IS NOT NULL) AS assigned
             FROM registrations WHERE edition_id = ?',
            [$editionId],
        ) ?? ['wishes' => 0, 'assigned' => 0];

        // Erfüllungsgrad je Wunsch-Priorität (1 = Erstwunsch)
        $byPriority = [];
        foreach ($this->db->fetchAll(
            'SELECT priority, COUNT(*) AS total, SUM(timeslot_id IS NOT NULL) AS assigned
             FROM registrations
             WHERE edition_id = ? AND priority IS NOT NULL
             GROUP BY priority ORDER BY priority',
            [$editionId],
        ) as $row) {
            $byPriority[(int) $row['priority']] = [
                'total' => (int) $row['total'],
                'assigned' => (int) $row['assigned'],
            ];
        }

        // Wie voll ist der Tagesplan der Schüler:innen?
        $students = (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM users WHERE role = 'student' AND school_id = ? AND edition_id = ?",
            [$schoolId, $editionId],
        );
        $full = 0;
        $partial = 0;
        if ($slotCount > 0) {
            $counts = $this->db->fetchAll(
                "SELECT u.id, COUNT(r.id) AS belegt
                 FROM users u
                 LEFT JOIN registrations r ON r.user_id = u.id AND r.edition_id = ? AND r.timeslot_id IS NOT NULL
                 WHERE u.role = 'student' AND u.school_id = ? AND u.edition_id = ?
                 GROUP BY u.id",
                [$editionId, $schoolId, $editionId],
            );
            foreach ($counts as $row) {
                $belegt = (int) $row['belegt'];
                if ($belegt >= $slotCount) {
                    $full++;
                } elseif ($belegt > 0) {
                    $partial++;
                }
            }
        }

        // Ungenutzte Plätze über alle Aussteller × feste Slots
        $freeSeats = 0;
        $this->capacity->refresh($editionId);
        $exhibitors = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->fetchAll(
                'SELECT id FROM exhibitors WHERE edition_id = ? AND active = 1',
                [$editionId],
            ),
        );
        foreach ($exhibitors as $exhibitorId) {
            foreach ($managed as $slotId) {
                $freeSeats += max(0, $this->capacity->free($editionId, $exhibitorId, $slotId));
            }
        }

        return [
            'students' => $students,
            'wishes' => (int) $totals['wishes'],
            'assigned' => (int) ($totals['assigned'] ?? 0),
            'open' => (int) $totals['wishes'] - (int) ($totals['assigned'] ?? 0),
            'by_priority' => $byPriority,
            'full_plans' => $full,
            'partial_plans' => $partial,
            'empty_plans' => max(0, $students - $full - $partial),
            'free_seats' => $freeSeats,
            'slots' => $slotCount,
        ];
    }

    /**
     * Wünsche, die auch nach der Zuteilung offen bleiben — gruppiert nach
     * Aussteller, damit sichtbar wird, wo die Kapazität nicht reicht.
     *
     * @return list<array<string, mixed>>
     */
    private function unfulfilledWishes(int $editionId): array
    {
        return $this->db->fetchAll(
            'SELECT e.id, e.name, COUNT(*) AS offen,
                    SUM(r.priority = 1) AS erstwuensche
             FROM registrations r
             JOIN exhibitors e ON e.id = r.exhibitor_id
             WHERE r.edition_id = ? AND r.timeslot_id IS NULL
             GROUP BY e.id, e.name
             ORDER BY offen DESC, e.name
             LIMIT 25',
            [$editionId],
        );
    }

    /**
     * Zuteilung zurücksetzen: automatisch erzeugte Anmeldungen löschen,
     * manuelle Wünsche behalten und deren Slot leeren.
     *
     * @return array{cleared: int, removed: int}
     */
    public function reset(int $editionId): array
    {
        return $this->db->transaction(function () use ($editionId): array {
            $removed = $this->db->run(
                'DELETE FROM registrations WHERE edition_id = ? AND registration_type = \'automatic\'',
                [$editionId],
            )->rowCount();

            $cleared = $this->db->run(
                'UPDATE registrations SET timeslot_id = NULL
                 WHERE edition_id = ? AND registration_type = \'manual\' AND timeslot_id IS NOT NULL',
                [$editionId],
            )->rowCount();

            $this->capacity->refresh($editionId);

            return ['cleared' => $cleared, 'removed' => $removed];
        });
    }
}
