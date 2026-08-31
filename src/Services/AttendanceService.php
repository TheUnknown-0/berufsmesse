<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Fachlogik rund um Anmeldungen und Anwesenheit beim Check-in.
 *
 * Freie Slots werden ausschließlich über die Flags der timeslots-Tabelle
 * bestimmt (is_managed = 0 && is_break = 0) — nie über Slot-Nummern.
 *
 * Kapazität eines Aussteller-Slots (Reihenfolge laut ARCHITECTURE.md):
 *   room_slot_capacities → rooms.capacity → exhibitors.total_slots
 */
final class AttendanceService
{
    public function __construct(private readonly Database $db)
    {
    }

    /** Freier Slot = keine Zuteilung, keine Pause → Check-in schreibt ein. */
    public function isFreeSlot(array $slot): bool
    {
        return (int) ($slot['is_managed'] ?? 1) === 0 && (int) ($slot['is_break'] ?? 0) === 0;
    }

    // ------------------------------------------------------------ Kapazität

    /** Kapazität eines Aussteller-Slots. 0 = unbegrenzt/unbekannt. */
    public function slotCapacity(int $exhibitorId, ?int $roomId, int $timeslotId): int
    {
        if ($roomId !== null) {
            $slotCap = $this->db->fetchValue(
                'SELECT capacity FROM room_slot_capacities WHERE room_id = ? AND timeslot_id = ?',
                [$roomId, $timeslotId],
            );
            if ($slotCap !== null) {
                return (int) $slotCap;
            }

            $roomCap = $this->db->fetchValue('SELECT capacity FROM rooms WHERE id = ?', [$roomId]);
            if ($roomCap !== null) {
                return (int) $roomCap;
            }
        }

        return (int) ($this->db->fetchValue('SELECT total_slots FROM exhibitors WHERE id = ?', [$exhibitorId]) ?? 0);
    }

    /** Belegung eines Aussteller-Slots (Anzahl Anmeldungen). */
    public function registrationCount(int $exhibitorId, int $timeslotId, int $editionId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM registrations WHERE exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?',
            [$exhibitorId, $timeslotId, $editionId],
        );
    }

    // ---------------------------------------------------------- Anmeldungen

    public function hasRegistration(int $userId, int $exhibitorId, int $timeslotId, int $editionId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM registrations
             WHERE user_id = ? AND exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?',
            [$userId, $exhibitorId, $timeslotId, $editionId],
        ) !== null;
    }

    /**
     * Soll-Anmeldung eines Schülers in einem Zeitslot (inkl. Aussteller/Raum).
     *
     * @return array<string, mixed>|null
     */
    public function slotRegistration(int $userId, int $timeslotId, int $editionId): ?array
    {
        return $this->db->fetchOne(
            'SELECT reg.id, reg.exhibitor_id, e.name AS exhibitor_name,
                    r.id AS room_id, r.room_number, r.room_name
             FROM registrations reg
             JOIN exhibitors e ON e.id = reg.exhibitor_id
             LEFT JOIN rooms r ON r.id = e.room_id
             WHERE reg.user_id = ? AND reg.timeslot_id = ? AND reg.edition_id = ?
             LIMIT 1',
            [$userId, $timeslotId, $editionId],
        );
    }

    /**
     * Sorgt in einem freien Slot für eine Anmeldung beim gescannten Aussteller.
     *
     * @return string|null Fehlermeldung oder null bei Erfolg.
     */
    public function ensureFreeSlotRegistration(
        int $userId,
        int $exhibitorId,
        ?int $roomId,
        int $timeslotId,
        int $editionId,
    ): ?string {
        if ($this->hasRegistration($userId, $exhibitorId, $timeslotId, $editionId)) {
            return null;
        }

        // In diesem Slot bereits woanders eingetragen?
        $other = $this->slotRegistration($userId, $timeslotId, $editionId);
        if ($other !== null && (int) $other['exhibitor_id'] !== $exhibitorId) {
            return 'Du bist in diesem Zeitslot bereits bei ' . (string) $other['exhibitor_name'] . ' eingetragen.';
        }

        $capacity = $this->slotCapacity($exhibitorId, $roomId, $timeslotId);
        if ($capacity > 0 && $this->registrationCount($exhibitorId, $timeslotId, $editionId) >= $capacity) {
            return 'Dieser Aussteller ist in diesem Zeitslot bereits voll belegt.';
        }

        // UNIQUE (user, exhibitor): existiert bereits eine Anmeldung bei diesem
        // Aussteller, wird sie höchstens auf diesen Slot gesetzt (falls noch
        // unzugeteilt) — sonst bleibt sie unverändert bestehen.
        $existing = $this->db->fetchOne(
            'SELECT id, timeslot_id FROM registrations WHERE user_id = ? AND exhibitor_id = ? AND edition_id = ?',
            [$userId, $exhibitorId, $editionId],
        );
        if ($existing !== null) {
            if ($existing['timeslot_id'] === null) {
                $this->db->run(
                    'UPDATE registrations SET timeslot_id = ?, registration_type = \'qr_checkin\'
                     WHERE id = ? AND edition_id = ?',
                    [$timeslotId, (int) $existing['id'], $editionId],
                );
            }

            return null;
        }

        $this->db->run(
            'INSERT INTO registrations (edition_id, user_id, exhibitor_id, timeslot_id, registration_type)
             VALUES (?, ?, ?, ?, \'qr_checkin\')',
            [$editionId, $userId, $exhibitorId, $timeslotId],
        );

        return null;
    }

    // ---------------------------------------------------------- Anwesenheit

    /** @return array<string, mixed>|null Vorhandener Check-in-Datensatz. */
    public function existingCheckin(int $userId, int $exhibitorId, int $timeslotId, int $editionId): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, checked_in_at, checkin_method, wrong_room
             FROM attendance
             WHERE user_id = ? AND exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?',
            [$userId, $exhibitorId, $timeslotId, $editionId],
        );
    }

    /**
     * Trägt eine Anwesenheit ein (UNIQUE user+exhibitor+slot).
     *
     * @return bool true = neu eingetragen, false = war bereits vorhanden.
     */
    public function recordCheckin(
        int $editionId,
        int $userId,
        int $exhibitorId,
        int $timeslotId,
        string $method,
        ?string $qrToken = null,
        ?int $checkedInBy = null,
        ?int $actualRoomId = null,
        bool $wrongRoom = false,
    ): bool {
        $stmt = $this->db->run(
            'INSERT IGNORE INTO attendance
                (edition_id, user_id, exhibitor_id, timeslot_id, qr_token,
                 checkin_method, checked_in_by, actual_room_id, wrong_room)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $editionId, $userId, $exhibitorId, $timeslotId, $qrToken,
                $method, $checkedInBy, $actualRoomId, $wrongRoom ? 1 : 0,
            ],
        );

        return $stmt->rowCount() > 0;
    }

    /** Entfernt eine Anwesenheit. @return bool true, wenn etwas gelöscht wurde. */
    public function removeCheckin(int $userId, int $exhibitorId, int $timeslotId, int $editionId): bool
    {
        $stmt = $this->db->run(
            'DELETE FROM attendance
             WHERE user_id = ? AND exhibitor_id = ? AND timeslot_id = ? AND edition_id = ?',
            [$userId, $exhibitorId, $timeslotId, $editionId],
        );

        return $stmt->rowCount() > 0;
    }

    // ------------------------------------------------------- Hilfsabfragen

    /**
     * Alle Zeitslots der Edition ohne Pausen.
     *
     * @return list<array<string, mixed>>
     */
    public function slots(int $editionId, bool $onlyManaged = false): array
    {
        $sql = 'SELECT * FROM timeslots WHERE edition_id = ? AND is_break = 0';
        if ($onlyManaged) {
            $sql .= ' AND is_managed = 1';
        }
        $sql .= ' ORDER BY start_time, slot_number';

        return $this->db->fetchAll($sql, [$editionId]);
    }

    /** Der laufende bzw. nächste Slot des Messetags (für Vorauswahlen). */
    public function currentSlotId(int $editionId, ?string $eventDate): ?int
    {
        $slots = $this->slots($editionId);
        if ($slots === []) {
            return null;
        }

        if ($eventDate === null || $eventDate === '') {
            return (int) $slots[0]['id'];
        }

        $now = time();
        foreach ($slots as $slot) {
            $start = strtotime($eventDate . ' ' . (string) $slot['start_time']);
            $end = strtotime($eventDate . ' ' . (string) $slot['end_time']);
            if ($start !== false && $end !== false && $now >= $start && $now <= $end) {
                return (int) $slot['id'];
            }
        }
        foreach ($slots as $slot) {
            $start = strtotime($eventDate . ' ' . (string) $slot['start_time']);
            if ($start !== false && $now < $start) {
                return (int) $slot['id'];
            }
        }

        return (int) $slots[array_key_last($slots)]['id'];
    }

    /** Anzeige-Label eines Raums ("A101 · Werkstatt"). */
    public static function roomLabel(?string $number, ?string $name): string
    {
        $number = trim((string) $number);
        $name = trim((string) $name);
        if ($number !== '' && $name !== '') {
            return $number . ' · ' . $name;
        }

        return $number !== '' ? $number : $name;
    }
}
