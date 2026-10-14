<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Neuzuteilung nach einer Absage: Die Schüler:innen eines abgesagten
 * Ausstellers bekommen im selben Zeitslot einen Platz bei einem anderen
 * Aussteller mit freier Kapazität.
 *
 * Arbeitet auf den ruhenden Buchungen (Services\HeldRegistrations): Die
 * Absage selbst übernimmt der CancellationService, der die Buchungen
 * ruhend stellt. Erst wenn hier eine neue Buchung entsteht, wird die
 * ruhende gelöscht. Wer keinen Platz bekommt, behält seine ruhende Buchung
 * und kann später (manuell oder bei einem neuen Lauf) zugeteilt werden.
 *
 * Ausgewählt wird jeweils der Aussteller mit der geringsten Belegung im
 * betroffenen Slot, bei dem die Person noch nicht angemeldet ist: dieselbe
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
     * Vor der Absage zählen die aktiven Buchungen, danach die ruhenden.
     *
     * @return array{slots: list<array<string, mixed>>, affected: int, placeable: int}
     */
    public function preview(int $editionId, int $exhibitorId): array
    {
        return $this->plan($editionId, $exhibitorId);
    }

    /**
     * Teilt die ruhenden Buchungen des Ausstellers neu zu und benachrichtigt
     * die Umgebuchten.
     *
     * @param bool $notifyUnplaced Auch die informieren, für die nichts frei war
     *             (beim Ausfall am Messetag, wo es keine eigene Absage-Nachricht gibt)
     * @return array{moved: int, unplaced: int, slots: list<array<string, mixed>>}
     */
    public function execute(
        int $editionId,
        int $exhibitorId,
        int $schoolId,
        string $exhibitorName,
        bool $notifyUnplaced = false,
    ): array {
        return $this->db->transaction(function () use ($editionId, $exhibitorId, $schoolId, $exhibitorName, $notifyUnplaced): array {
            $plan = $this->plan($editionId, $exhibitorId);
            $held = new HeldRegistrations($this->db);
            $moved = 0;
            $unplaced = 0;

            foreach ($plan['slots'] as $slot) {
                $slotId = (int) $slot['id'];
                foreach ($slot['students'] as $student) {
                    if ($student['target_id'] === null) {
                        $unplaced++;
                        if ($notifyUnplaced) {
                            $this->notifications->send(
                                $student['user_id'],
                                $schoolId,
                                sprintf(
                                    '„%s“ fällt aus. Für %s war leider kein Ersatz frei. Bitte melde dich im Orga-Büro.',
                                    $exhibitorName,
                                    (string) $slot['label'],
                                ),
                                'exhibitor_cancelled',
                                $exhibitorId,
                            );
                        }
                        continue;
                    }

                    $this->db->run(
                        'INSERT INTO registrations
                            (edition_id, user_id, exhibitor_id, timeslot_id, registration_type, priority)
                         VALUES (?, ?, ?, ?, \'automatic\', NULL)',
                        [$editionId, $student['user_id'], $student['target_id'], $slotId],
                    );
                    $held->clearSlot($editionId, $student['user_id'], $slotId);
                    $this->capacity->reserve($editionId, $student['target_id'], $slotId);
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

            // Ruhende Buchungen, deren Slot inzwischen anders belegt ist, sind überholt.
            foreach ($plan['superseded'] as $row) {
                $held->clearSlot($editionId, $row['user_id'], $row['timeslot_id']);
            }

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
     * @return array{slots: list<array<string, mixed>>, affected: int, placeable: int,
     *               superseded: list<array{user_id: int, timeslot_id: int}>}
     */
    private function plan(int $editionId, int $exhibitorId): array
    {
        $this->capacity->refresh($editionId);

        // Aktive Buchungen (vor der Absage) und ruhende (danach); nur mit Slot.
        $rows = $this->db->fetchAll(
            'SELECT b.user_id, b.timeslot_id, b.ruhend,
                    u.firstname, u.lastname, u.class,
                    t.slot_name, t.slot_number, t.start_time, t.end_time
             FROM (
                 SELECT user_id, timeslot_id, 0 AS ruhend FROM registrations
                  WHERE edition_id = ? AND exhibitor_id = ? AND timeslot_id IS NOT NULL
                 UNION ALL
                 SELECT user_id, timeslot_id, 1 AS ruhend FROM registrations_on_hold
                  WHERE edition_id = ? AND exhibitor_id = ? AND timeslot_id IS NOT NULL
             ) b
             JOIN users u ON u.id = b.user_id
             JOIN timeslots t ON t.id = b.timeslot_id
             ORDER BY t.slot_number, u.lastname, u.firstname',
            [$editionId, $exhibitorId, $editionId, $exhibitorId],
        );

        // Alternativen: aktive Aussteller außer dem ausgefallenen
        $alternatives = $this->db->fetchAll(
            'SELECT id, name FROM exhibitors WHERE edition_id = ? AND active = 1 AND id <> ? ORDER BY name',
            [$editionId, $exhibitorId],
        );

        // Wo ist die Person schon angemeldet (UNIQUE user + exhibitor), welche Slots sind belegt?
        $bookedBy = [];
        $slotTaken = [];
        foreach ($this->db->fetchAll(
            'SELECT user_id, exhibitor_id, timeslot_id FROM registrations WHERE edition_id = ?',
            [$editionId],
        ) as $row) {
            $bookedBy[(int) $row['user_id']][(int) $row['exhibitor_id']] = true;
            if ($row['timeslot_id'] !== null && (int) $row['exhibitor_id'] !== $exhibitorId) {
                $slotTaken[(int) $row['user_id']][(int) $row['timeslot_id']] = true;
            }
        }

        // Geplante Belegung je (Aussteller, Slot), zusätzlich zur echten
        $planned = [];
        $slots = [];
        $superseded = [];
        $affected = 0;
        $placeable = 0;

        foreach ($rows as $row) {
            $slotId = (int) $row['timeslot_id'];
            $userId = (int) $row['user_id'];

            if (isset($slotTaken[$userId][$slotId])) {
                if ((int) $row['ruhend'] === 1) {
                    $superseded[] = ['user_id' => $userId, 'timeslot_id' => $slotId];
                }
                continue;
            }
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
            'superseded' => $superseded,
        ];
    }
}
