<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Jahresbezug eines Ausstellers.
 *
 * Beim Klonen einer Edition zeigt jeder neue Aussteller über
 * `previous_exhibitor_id` auf seinen Vorgänger. Diese Kette liefert die
 * Teilnahmehistorie desselben Unternehmens über die Jahrgänge hinweg —
 * ohne dass es einen eigenen Unternehmens-Stammsatz bräuchte.
 */
final class ExhibitorHistory
{
    /** Sicherheitsnetz gegen im Kreis zeigende Verweise. */
    private const MAX_DEPTH = 25;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Frühere Teilnahmen eines Ausstellers, jüngste zuerst.
     *
     * @return list<array<string, mixed>> je Eintrag: id, year, edition_name,
     *         pipeline_status, registrations, attendances
     */
    public function previous(int $exhibitorId): array
    {
        $history = [];
        $currentId = $exhibitorId;
        $seen = [$exhibitorId => true];

        for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
            $row = $this->db->fetchOne(
                'SELECT e.previous_exhibitor_id FROM exhibitors e WHERE e.id = ?',
                [$currentId],
            );
            $previousId = $row !== null && $row['previous_exhibitor_id'] !== null
                ? (int) $row['previous_exhibitor_id']
                : null;
            if ($previousId === null || isset($seen[$previousId])) {
                break;
            }
            $seen[$previousId] = true;

            $entry = $this->db->fetchOne(
                'SELECT e.id, e.name, e.pipeline_status, me.year, me.name AS edition_name,
                        (SELECT COUNT(*) FROM registrations r WHERE r.exhibitor_id = e.id) AS registrations,
                        (SELECT COUNT(*) FROM attendance a WHERE a.exhibitor_id = e.id) AS attendances
                 FROM exhibitors e
                 JOIN messe_editions me ON me.id = e.edition_id
                 WHERE e.id = ?',
                [$previousId],
            );
            if ($entry === null) {
                break;
            }

            $history[] = $entry;
            $currentId = $previousId;
        }

        return $history;
    }

    /**
     * Kurzfassung für Listen: seit wie vielen Jahrgängen dabei und wie das
     * letzte Mal lief.
     *
     * @return array{years: int, last_year: int|null, last_attendances: int|null}
     */
    public function summary(int $exhibitorId): array
    {
        $previous = $this->previous($exhibitorId);
        $last = $previous[0] ?? null;

        return [
            'years' => count($previous) + 1,
            'last_year' => $last !== null ? (int) $last['year'] : null,
            'last_attendances' => $last !== null ? (int) $last['attendances'] : null,
        ];
    }

    /**
     * Kurzfassung für viele Aussteller auf einmal.
     *
     * Lädt alle Aussteller der Schule in EINER Abfrage und löst die Ketten
     * anschließend im Speicher auf — sonst würde die Pipeline-Übersicht pro
     * Karte mehrere Abfragen absetzen.
     *
     * @param  list<int> $exhibitorIds
     * @return array<int, array{years: int, last_year: int|null, last_attendances: int|null}>
     */
    public function summaries(int $schoolId, array $exhibitorIds): array
    {
        if ($exhibitorIds === []) {
            return [];
        }

        $all = [];
        foreach ($this->db->fetchAll(
            'SELECT e.id, e.previous_exhibitor_id, me.year,
                    (SELECT COUNT(*) FROM attendance a WHERE a.exhibitor_id = e.id) AS attendances
             FROM exhibitors e
             JOIN messe_editions me ON me.id = e.edition_id
             WHERE me.school_id = ?',
            [$schoolId],
        ) as $row) {
            $all[(int) $row['id']] = $row;
        }

        $result = [];
        foreach ($exhibitorIds as $id) {
            $years = 1;
            $lastYear = null;
            $lastAttendances = null;

            $currentId = $id;
            $seen = [$id => true];
            for ($depth = 0; $depth < self::MAX_DEPTH; $depth++) {
                $current = $all[$currentId] ?? null;
                $previousId = $current !== null && $current['previous_exhibitor_id'] !== null
                    ? (int) $current['previous_exhibitor_id']
                    : null;
                if ($previousId === null || isset($seen[$previousId]) || !isset($all[$previousId])) {
                    break;
                }
                $seen[$previousId] = true;

                if ($lastYear === null) {
                    $lastYear = (int) $all[$previousId]['year'];
                    $lastAttendances = (int) $all[$previousId]['attendances'];
                }
                $years++;
                $currentId = $previousId;
            }

            $result[$id] = [
                'years' => $years,
                'last_year' => $lastYear,
                'last_attendances' => $lastAttendances,
            ];
        }

        return $result;
    }
}
