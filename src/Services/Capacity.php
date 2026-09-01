<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Kapazitäts- und Belegungsrechnung für Aussteller × Zeitslot.
 *
 * Verbindliche Regel (auch Referenz für andere Module):
 *   1. Gibt es einen room_slot_capacities-Eintrag für (exhibitors.room_id, timeslot),
 *      gilt dieser Wert.
 *   2. Sonst gilt rooms.capacity des zugeordneten Raums.
 *   3. Aussteller ohne Raum → exhibitors.total_slots.
 * Belegung = Anzahl registrations mit genau diesem (exhibitor_id, timeslot_id).
 *
 * Die Matrix wird je Edition einmal geladen; Reservierungen während eines
 * Zuteilungslaufs werden in-memory mitgeführt (siehe reserve()/release()).
 */
final class Capacity
{
    /** @var array<int, array<int, array<int, int>>> editionId => exhibitorId => timeslotId => Kapazität */
    private array $capacity = [];

    /** @var array<int, array<int, array<int, int>>> editionId => exhibitorId => timeslotId => Belegung */
    private array $used = [];

    public function __construct(private readonly Database $db)
    {
    }

    /** Lädt Kapazitäts- und Belegungsmatrix einer Edition (idempotent). */
    public function preload(int $editionId): void
    {
        if (isset($this->capacity[$editionId])) {
            return;
        }

        $capacityMap = [];
        $rows = $this->db->fetchAll(
            'SELECT e.id AS exhibitor_id,
                    t.id AS timeslot_id,
                    COALESCE(rsc.capacity, r.capacity, e.total_slots) AS capacity
             FROM exhibitors e
             JOIN timeslots t ON t.edition_id = e.edition_id
             LEFT JOIN rooms r ON r.id = e.room_id
             LEFT JOIN room_slot_capacities rsc
                    ON rsc.room_id = e.room_id AND rsc.timeslot_id = t.id
             WHERE e.edition_id = ? AND e.active = 1',
            [$editionId],
        );
        foreach ($rows as $row) {
            $capacityMap[(int) $row['exhibitor_id']][(int) $row['timeslot_id']] = max(0, (int) $row['capacity']);
        }
        $this->capacity[$editionId] = $capacityMap;

        $usedMap = [];
        $usedRows = $this->db->fetchAll(
            'SELECT exhibitor_id, timeslot_id, COUNT(*) AS belegt
             FROM registrations
             WHERE edition_id = ? AND timeslot_id IS NOT NULL
             GROUP BY exhibitor_id, timeslot_id',
            [$editionId],
        );
        foreach ($usedRows as $row) {
            $usedMap[(int) $row['exhibitor_id']][(int) $row['timeslot_id']] = (int) $row['belegt'];
        }
        $this->used[$editionId] = $usedMap;
    }

    /** Verwirft den Cache einer Edition (nach Schreibaktionen von außen). */
    public function refresh(int $editionId): void
    {
        unset($this->capacity[$editionId], $this->used[$editionId]);
    }

    /** Kapazität eines Aussteller-Slots. */
    public function capacity(int $editionId, int $exhibitorId, int $timeslotId): int
    {
        $this->preload($editionId);

        return $this->capacity[$editionId][$exhibitorId][$timeslotId] ?? 0;
    }

    /** Aktuelle Belegung eines Aussteller-Slots. */
    public function used(int $editionId, int $exhibitorId, int $timeslotId): int
    {
        $this->preload($editionId);

        return $this->used[$editionId][$exhibitorId][$timeslotId] ?? 0;
    }

    /** Freie Plätze (nie negativ). */
    public function free(int $editionId, int $exhibitorId, int $timeslotId): int
    {
        return max(0, $this->capacity($editionId, $exhibitorId, $timeslotId)
            - $this->used($editionId, $exhibitorId, $timeslotId));
    }

    public function hasFree(int $editionId, int $exhibitorId, int $timeslotId): bool
    {
        return $this->free($editionId, $exhibitorId, $timeslotId) > 0;
    }

    /** Bucht einen Platz in der In-Memory-Matrix (nach erfolgreicher Zuteilung). */
    public function reserve(int $editionId, int $exhibitorId, int $timeslotId, int $count = 1): void
    {
        $this->preload($editionId);
        $current = $this->used[$editionId][$exhibitorId][$timeslotId] ?? 0;
        $this->used[$editionId][$exhibitorId][$timeslotId] = max(0, $current + $count);
    }

    /** Gibt einen Platz in der In-Memory-Matrix wieder frei. */
    public function release(int $editionId, int $exhibitorId, int $timeslotId, int $count = 1): void
    {
        $this->reserve($editionId, $exhibitorId, $timeslotId, -$count);
    }

    /**
     * Vollständige Übersicht für Raumpläne/Tabellen.
     *
     * @return array<int, array<int, array{capacity: int, used: int, free: int}>>
     *         exhibitorId => timeslotId => Kennzahlen
     */
    public function overview(int $editionId): array
    {
        $this->preload($editionId);

        $result = [];
        foreach ($this->capacity[$editionId] as $exhibitorId => $slots) {
            foreach ($slots as $timeslotId => $capacity) {
                $used = $this->used[$editionId][$exhibitorId][$timeslotId] ?? 0;
                $result[$exhibitorId][$timeslotId] = [
                    'capacity' => $capacity,
                    'used' => $used,
                    'free' => max(0, $capacity - $used),
                ];
            }
        }

        return $result;
    }

    /**
     * Gesamtkapazität und -belegung je Zeitslot (für Auslastungs-Diagramme).
     * Nur aktive Aussteller zählen mit.
     *
     * @return array<int, array{capacity: int, used: int}> timeslotId => Kennzahlen
     */
    public function slotTotals(int $editionId): array
    {
        $this->preload($editionId);

        $activeIds = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->fetchAll('SELECT id FROM exhibitors WHERE edition_id = ? AND active = 1', [$editionId]),
        );

        $totals = [];
        foreach ($activeIds as $exhibitorId) {
            foreach ($this->capacity[$editionId][$exhibitorId] ?? [] as $timeslotId => $capacity) {
                $totals[$timeslotId] ??= ['capacity' => 0, 'used' => 0];
                $totals[$timeslotId]['capacity'] += $capacity;
                $totals[$timeslotId]['used'] += $this->used[$editionId][$exhibitorId][$timeslotId] ?? 0;
            }
        }

        return $totals;
    }
}
