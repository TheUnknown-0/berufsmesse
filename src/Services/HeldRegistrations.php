<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Ruhende Buchungen (Tabelle `registrations_on_hold`).
 *
 * Sagt ein Aussteller ab, werden seine Buchungen nicht gelöscht, sondern
 * hierher verschoben. Die Schüler:innen gelten damit als ungebucht, die alte
 * Buchung bleibt aber erhalten:
 *  - Bekommt die Person im selben Slot eine neue Buchung, ist die ruhende
 *    überholt und wird gelöscht (clearSlot). Jede Stelle, die einer Person
 *    einen Slot zuteilt, MUSS clearSlot() aufrufen.
 *  - Wird die Absage zurückgenommen, holt restore() alle noch vorhandenen
 *    Einträge zurück.
 */
final class HeldRegistrations
{
    public const CAUSES = ['cancelled_by_exhibitor', 'cancelled_by_school', 'failover'];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Verschiebt alle Buchungen eines Ausstellers in die ruhenden Buchungen.
     *
     * @return list<int> betroffene Personen
     */
    public function hold(int $editionId, int $exhibitorId, string $cause, ?string $reason, ?int $heldBy): array
    {
        $cause = in_array($cause, self::CAUSES, true) ? $cause : 'cancelled_by_school';

        return $this->db->transaction(function () use ($editionId, $exhibitorId, $cause, $reason, $heldBy): array {
            $users = array_map(
                static fn (array $row): int => (int) $row['user_id'],
                $this->db->fetchAll(
                    'SELECT DISTINCT user_id FROM registrations WHERE exhibitor_id = ? AND edition_id = ?',
                    [$exhibitorId, $editionId],
                ),
            );
            if ($users === []) {
                return [];
            }

            // Ein Rest aus einer früheren Absage desselben Ausstellers wird von
            // der aktuellen Buchung ersetzt (UNIQUE user + exhibitor).
            $this->db->run(
                'DELETE h FROM registrations_on_hold h
                 JOIN registrations r ON r.user_id = h.user_id AND r.exhibitor_id = h.exhibitor_id
                 WHERE r.exhibitor_id = ? AND r.edition_id = ?',
                [$exhibitorId, $editionId],
            );
            $this->db->run(
                'INSERT INTO registrations_on_hold
                    (edition_id, user_id, exhibitor_id, timeslot_id, registration_type, priority,
                     registered_at, cause, reason, held_by)
                 SELECT edition_id, user_id, exhibitor_id, timeslot_id, registration_type, priority,
                        registered_at, ?, ?, ?
                 FROM registrations
                 WHERE exhibitor_id = ? AND edition_id = ?',
                [$cause, $reason !== null ? mb_substr($reason, 0, 500) : null, $heldBy, $exhibitorId, $editionId],
            );
            $this->db->run(
                'DELETE FROM registrations WHERE exhibitor_id = ? AND edition_id = ?',
                [$exhibitorId, $editionId],
            );

            return $users;
        });
    }

    /**
     * Die Person hat in diesem Slot eine neue Buchung bekommen: Eine dort
     * ruhende Buchung ist damit überholt und wird gelöscht.
     */
    public function clearSlot(int $editionId, int $userId, int $timeslotId): int
    {
        return $this->db->run(
            'DELETE FROM registrations_on_hold WHERE edition_id = ? AND user_id = ? AND timeslot_id = ?',
            [$editionId, $userId, $timeslotId],
        )->rowCount();
    }

    /**
     * Absage zurücknehmen: Alle noch ruhenden Buchungen des Ausstellers
     * werden wieder aktiv. Ist der Slot einer Person inzwischen anders
     * belegt, bleibt diese eine Buchung ruhend (sie wird nicht verworfen).
     *
     * @return array{restored: list<int>, blocked: int}
     */
    public function restore(int $editionId, int $exhibitorId): array
    {
        return $this->db->transaction(function () use ($editionId, $exhibitorId): array {
            $rows = $this->db->fetchAll(
                'SELECT * FROM registrations_on_hold WHERE exhibitor_id = ? AND edition_id = ? FOR UPDATE',
                [$exhibitorId, $editionId],
            );

            $restored = [];
            $blocked = 0;
            foreach ($rows as $row) {
                $userId = (int) $row['user_id'];
                $slotId = $row['timeslot_id'] !== null ? (int) $row['timeslot_id'] : null;

                $conflict = $this->db->fetchValue(
                    'SELECT 1 FROM registrations
                     WHERE edition_id = ? AND user_id = ?
                       AND (exhibitor_id = ? OR (? IS NOT NULL AND timeslot_id = ?))
                     LIMIT 1',
                    [$editionId, $userId, $exhibitorId, $slotId, $slotId],
                );
                if ($conflict !== null) {
                    $blocked++;
                    continue;
                }

                $this->db->run(
                    'INSERT INTO registrations
                        (edition_id, user_id, exhibitor_id, timeslot_id, registration_type, priority, registered_at)
                     VALUES (?, ?, ?, ?, ?, ?, COALESCE(?, CURRENT_TIMESTAMP))',
                    [
                        $editionId, $userId, $exhibitorId, $slotId,
                        (string) $row['registration_type'], $row['priority'], $row['registered_at'],
                    ],
                );
                $this->db->run('DELETE FROM registrations_on_hold WHERE id = ?', [(int) $row['id']]);
                $restored[] = $userId;
            }

            return ['restored' => array_values(array_unique($restored)), 'blocked' => $blocked];
        });
    }

    /**
     * Ruhende Buchungen einer Person, mit Aussteller und Slot (für den Tagesplan).
     *
     * @return list<array<string, mixed>>
     */
    public function forUser(int $editionId, int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT h.*, e.name AS exhibitor_name,
                    t.slot_number, t.slot_name, t.start_time, t.end_time
             FROM registrations_on_hold h
             JOIN exhibitors e ON e.id = h.exhibitor_id
             LEFT JOIN timeslots t ON t.id = h.timeslot_id
             WHERE h.edition_id = ? AND h.user_id = ?
             ORDER BY t.slot_number IS NULL, t.slot_number',
            [$editionId, $userId],
        );
    }

    /** Anzahl ruhender Buchungen eines Ausstellers (mit und ohne Slot). */
    public function countForExhibitor(int $editionId, int $exhibitorId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM registrations_on_hold WHERE edition_id = ? AND exhibitor_id = ?',
            [$editionId, $exhibitorId],
        );
    }

    /** Anzahl ruhender Buchungen einer Person (zählt bei der Höchstzahl mit). */
    public function countForUser(int $editionId, int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM registrations_on_hold WHERE edition_id = ? AND user_id = ?',
            [$editionId, $userId],
        );
    }
}
