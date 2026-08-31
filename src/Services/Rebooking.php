<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Ausfall-Management: Ein Aussteller fällt kurzfristig aus — die
 * betroffenen Schüler:innen werden auf Aussteller mit freier Kapazität im
 * selben Zeitslot umgebucht statt einfach abgemeldet.
 *
 * Der bestehende CancellationService entfernt die Anmeldungen und meldet
 * die Absage; er weist aber keine Alternative zu. Beides greift ineinander:
 * erst umbuchen, dann absagen.
 *
 * Ausgewählt wird jeweils der Aussteller mit der geringsten Belegung im
 * betroffenen Slot, bei dem die Person noch nicht angemeldet ist — dieselbe
 * Regel wie in AutoAssign, damit die Verteilung gleichmäßig bleibt.
 */
final class Rebooking
{
    public function __construct(
        private readonly Database $db,
        private readonly Capacity $capacity,
        private readonly Notifications $notifications,
    ) {
    }

    /**
     * Vorschau: Wer ist betroffen und wohin könnte umgebucht werden?
     *
     * @return array{slots: list<array<string, mixed>>, affected: int, placeable: int}
     */
    public function preview(int $editionId, int $exhibitorId): array
    {
        return $this->plan($editionId, $exhibitorId);
    }

    /**
     * Führt die Umbuchung aus und benachrichtigt die Betroffenen.
     *
     * @return array{moved: int, unplaced: int, slots: list<array<string, mixed>>}
     */
    public function execute(int $editionId, int $exhibitorId, int $schoolId, string $exhibitorName): array
    {
        return $this->db->transaction(function () use ($editionId, $exhibitorId, $schoolId, $exhibitorName): array {
            $plan = $this->plan($editionId, $exhibitorId);
            $moved = 0;
            $unplaced = 0;

            foreach ($plan['slots'] as $slot) {
                foreach ($slot['students'] as $student) {
                    if ($student['target_id'] === null) {
                        $unplaced++;
                        continue;
                    }

                    $this->db->run(
                        'UPDATE registrations
                            SET exhibitor_id = ?, registration_type = \'automatic\'
                          WHERE id = ? AND edition_id = ?',
                        [$student['target_id'], $student['registration_id'], $editionId],
                    );
                    $this->capacity->reserve($editionId, $student['target_id'], (int) $slot['id']);
                    $moved++;

                    $this->notifications->send(
                        $student['user_id'],
                        $schoolId,
                        sprintf(
                            '„%s“ fällt aus. Du wurdest für %s (%s–%s) zu „%s“ umgebucht.',
                            $exhibitorName,
                            (string) $slot['label'],
                            (string) $slot['start'],
                            (string) $slot['end'],
                            (string) $student['target_name'],
                        ),
                        'exhibitor_cancelled',
                        $exhibitorId,
                    );
                }
            }

            // Nicht vermittelbare Anmeldungen entfernen und darüber informieren
            if ($unplaced > 0) {
                foreach ($plan['slots'] as $slot) {
                    foreach ($slot['students'] as $student) {
                        if ($student['target_id'] !== null) {
                            continue;
                        }
                        $this->db->run(
                            'DELETE FROM registrations WHERE id = ? AND edition_id = ?',
                            [$student['registration_id'], $editionId],
                        );
                        $this->notifications->send(
                            $student['user_id'],
                            $schoolId,
                            sprintf(
                                '„%s“ fällt aus. Für %s war leider kein Ersatz frei — bitte melde dich im Orga-Büro.',
                                $exhibitorName,
                                (string) $slot['label'],
                            ),
                            'exhibitor_cancelled',
                            $exhibitorId,
                        );
                    }
                }
            }

            // Ausgefallener Aussteller: unsichtbar und in der Pipeline vermerkt
            $this->db->run(
                "UPDATE exhibitors SET active = 0, pipeline_status = 'cancelled' WHERE id = ? AND edition_id = ?",
                [$exhibitorId, $editionId],
            );
            $this->capacity->refresh($editionId);

            return ['moved' => $moved, 'unplaced' => $unplaced, 'slots' => $plan['slots']];
        });
    }

    /**
     * Baut den Umbuchungsplan: je betroffenem Slot die Schüler:innen mit
     * dem jeweils besten Alternativ-Aussteller.
     *
     * Die Kapazität wird über eine lokale Zählung mitgeführt, damit nicht
     * mehrere Personen auf denselben letzten freien Platz geplant werden.
     *
     * @return array{slots: list<array<string, mixed>>, affected: int, placeable: int}
     */
    private function plan(int $editionId, int $exhibitorId): array
    {
        $this->capacity->refresh($editionId);

        $rows = $this->db->fetchAll(
            'SELECT r.id AS registration_id, r.user_id, r.timeslot_id,
                    u.firstname, u.lastname, u.class,
                    t.slot_name, t.slot_number, t.start_time, t.end_time
             FROM registrations r
             JOIN users u ON u.id = r.user_id
             JOIN timeslots t ON t.id = r.timeslot_id
             WHERE r.edition_id = ? AND r.exhibitor_id = ? AND r.timeslot_id IS NOT NULL
             ORDER BY t.slot_number, u.lastname, u.firstname',
            [$editionId, $exhibitorId],
        );

        // Alternativen: aktive Aussteller außer dem ausgefallenen
        $alternatives = $this->db->fetchAll(
            'SELECT id, name FROM exhibitors WHERE edition_id = ? AND active = 1 AND id <> ? ORDER BY name',
            [$editionId, $exhibitorId],
        );

        // Wo ist die Person schon angemeldet? (UNIQUE user + exhibitor)
        $bookedBy = [];
        foreach ($this->db->fetchAll(
            'SELECT user_id, exhibitor_id FROM registrations WHERE edition_id = ?',
            [$editionId],
        ) as $row) {
            $bookedBy[(int) $row['user_id']][(int) $row['exhibitor_id']] = true;
        }

        // Geplante Belegung je (Aussteller, Slot) — zusätzlich zur echten
        $planned = [];
        $slots = [];
        $affected = 0;
        $placeable = 0;

        foreach ($rows as $row) {
            $slotId = (int) $row['timeslot_id'];
            $userId = (int) $row['user_id'];
            $affected++;

            if (!isset($slots[$slotId])) {
                $slots[$slotId] = [
                    'id' => $slotId,
                    'label' => $row['slot_name'] !== null && $row['slot_name'] !== ''
                        ? (string) $row['slot_name']
                        : 'Slot ' . (int) $row['slot_number'],
                    'start' => substr((string) $row['start_time'], 0, 5),
                    'end' => substr((string) $row['end_time'], 0, 5),
                    'students' => [],
                ];
            }

            $bestId = null;
            $bestName = null;
            $bestUsed = PHP_INT_MAX;
            foreach ($alternatives as $alternative) {
                $altId = (int) $alternative['id'];
                if (isset($bookedBy[$userId][$altId])) {
                    continue;
                }
                $used = $this->capacity->used($editionId, $altId, $slotId)
                    + ($planned[$altId][$slotId] ?? 0);
                if ($used >= $this->capacity->capacity($editionId, $altId, $slotId)) {
                    continue;
                }
                if ($used < $bestUsed) {
                    $bestUsed = $used;
                    $bestId = $altId;
                    $bestName = (string) $alternative['name'];
                }
            }

            if ($bestId !== null) {
                $planned[$bestId][$slotId] = ($planned[$bestId][$slotId] ?? 0) + 1;
                $bookedBy[$userId][$bestId] = true;
                $placeable++;
            }

            $slots[$slotId]['students'][] = [
                'registration_id' => (int) $row['registration_id'],
                'user_id' => $userId,
                'name' => trim((string) $row['firstname'] . ' ' . (string) $row['lastname']),
                'class' => $row['class'] !== null ? (string) $row['class'] : null,
                'target_id' => $bestId,
                'target_name' => $bestName,
            ];
        }

        return [
            'slots' => array_values($slots),
            'affected' => $affected,
            'placeable' => $placeable,
        ];
    }
}
