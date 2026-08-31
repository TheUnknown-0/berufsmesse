<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Warteliste und automatisches Nachrücken.
 *
 * Bewusst OHNE eigene Tabelle: Ein Wunsch, der nach der Zuteilung ohne
 * Zeitslot dasteht (`registrations.timeslot_id IS NULL`), IST der
 * Wartelisteneintrag. Eine Parallelstruktur müsste sonst bei jeder Absage,
 * Umbuchung und Neuzuteilung mitgepflegt werden und würde auseinanderlaufen.
 *
 * Wird ein Platz frei, rückt der am längsten wartende Wunsch mit der
 * höchsten Priorität nach — sofern der zugehörige Schüler in diesem Slot
 * noch nichts anderes hat.
 */
final class Waitlist
{
    public function __construct(
        private readonly Database $db,
        private readonly Capacity $capacity,
        private readonly Notifications $notifications,
    ) {
    }

    /**
     * Lässt für einen frei gewordenen Platz jemanden nachrücken.
     *
     * @param  int|null $schoolId Für die Benachrichtigung; null = keine
     * @return array<string, mixed>|null Der nachgerückte Datensatz, sonst null
     */
    public function promote(int $editionId, int $exhibitorId, int $timeslotId, ?int $schoolId = null): ?array
    {
        $this->capacity->refresh($editionId);
        if (!$this->capacity->hasFree($editionId, $exhibitorId, $timeslotId)) {
            return null;
        }

        // Kandidaten: offene Wünsche für genau diesen Aussteller, deren
        // Schüler:in im fraglichen Slot noch frei ist.
        $candidate = $this->db->fetchOne(
            'SELECT r.id, r.user_id, e.name AS exhibitor_name,
                    t.slot_name, t.slot_number, t.start_time, t.end_time
             FROM registrations r
             JOIN exhibitors e ON e.id = r.exhibitor_id
             JOIN timeslots t ON t.id = ?
             WHERE r.edition_id = ? AND r.exhibitor_id = ? AND r.timeslot_id IS NULL
               AND NOT EXISTS (
                   SELECT 1 FROM registrations belegt
                    WHERE belegt.user_id = r.user_id
                      AND belegt.edition_id = r.edition_id
                      AND belegt.timeslot_id = ?
               )
             ORDER BY r.priority IS NULL, r.priority ASC, r.registered_at ASC, r.id ASC
             LIMIT 1',
            [$timeslotId, $editionId, $exhibitorId, $timeslotId],
        );
        if ($candidate === null) {
            return null;
        }

        $this->db->run(
            'UPDATE registrations SET timeslot_id = ? WHERE id = ? AND edition_id = ? AND timeslot_id IS NULL',
            [$timeslotId, (int) $candidate['id'], $editionId],
        );
        $this->capacity->reserve($editionId, $exhibitorId, $timeslotId);

        $slotLabel = $candidate['slot_name'] !== null && $candidate['slot_name'] !== ''
            ? (string) $candidate['slot_name']
            : 'Slot ' . (int) $candidate['slot_number'];

        $this->notifications->send(
            (int) $candidate['user_id'],
            $schoolId,
            sprintf(
                'Gute Nachricht: Bei %s ist ein Platz frei geworden — du bist für %s (%s–%s) nachgerückt.',
                (string) $candidate['exhibitor_name'],
                $slotLabel,
                substr((string) $candidate['start_time'], 0, 5),
                substr((string) $candidate['end_time'], 0, 5),
            ),
            'info',
            (int) $candidate['id'],
        );

        return $candidate;
    }

    /**
     * Nachrücken für einen Aussteller über ALLE festen Slots, in denen
     * gerade Kapazität frei ist. Wird nach dem Entfernen einer Zuteilung
     * aufgerufen, wenn der konkrete Slot nicht mehr bekannt ist.
     *
     * @return list<array<string, mixed>> die nachgerückten Datensätze
     */
    public function promoteForExhibitor(int $editionId, int $exhibitorId, ?int $schoolId = null): array
    {
        $promoted = [];
        foreach ($this->managedSlots($editionId) as $slotId) {
            $entry = $this->promote($editionId, $exhibitorId, $slotId, $schoolId);
            if ($entry !== null) {
                $promoted[] = $entry;
            }
        }

        return $promoted;
    }

    /**
     * Warteschlange je Aussteller: wie viele Wünsche sind offen und wer
     * steht als Nächstes an.
     *
     * @return list<array<string, mixed>>
     */
    public function overview(int $editionId): array
    {
        return $this->db->fetchAll(
            'SELECT e.id, e.name, COUNT(*) AS wartend,
                    SUM(r.priority = 1) AS erstwuensche,
                    MIN(r.registered_at) AS aeltester
             FROM registrations r
             JOIN exhibitors e ON e.id = r.exhibitor_id
             WHERE r.edition_id = ? AND r.timeslot_id IS NULL
             GROUP BY e.id, e.name
             ORDER BY wartend DESC, e.name',
            [$editionId],
        );
    }

    /**
     * Wartende Schüler:innen eines Ausstellers in Nachrück-Reihenfolge.
     *
     * @return list<array<string, mixed>>
     */
    public function queueFor(int $editionId, int $exhibitorId): array
    {
        return $this->db->fetchAll(
            'SELECT r.id, r.priority, r.registered_at,
                    u.id AS user_id, u.firstname, u.lastname, u.class
             FROM registrations r
             JOIN users u ON u.id = r.user_id
             WHERE r.edition_id = ? AND r.exhibitor_id = ? AND r.timeslot_id IS NULL
             ORDER BY r.priority IS NULL, r.priority ASC, r.registered_at ASC, r.id ASC',
            [$editionId, $exhibitorId],
        );
    }

    /**
     * Feste Zuteilungsslots einer Edition.
     *
     * @return list<int>
     */
    private function managedSlots(int $editionId): array
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
}
