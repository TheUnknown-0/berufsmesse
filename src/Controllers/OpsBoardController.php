<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions;
use App\Services\AttendanceService;

/**
 * Leitstand für den Messetag: eine Seite, die den gesamten Betrieb zeigt —
 * laufender Slot, Anwesenheitsquote, Aussteller ohne Check-in, Räume mit
 * auffälliger Auslastung und Falsch-Raum-Meldungen.
 *
 * Ergänzt die bestehende Live-Ansicht (AttendanceController::live), die
 * einen einzelnen Slot im Detail zeigt: Hier geht es um den Überblick über
 * alles gleichzeitig, mit automatischer Aktualisierung.
 */
final class OpsBoardController extends Controller
{
    /** Ab welcher Anwesenheitsquote (in Prozent) ein Slot auffällig ist. */
    private const LOW_ATTENDANCE = 60;

    /** GET /{school}/admin/leitstand */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANWESENHEIT_SEHEN);
        $edition = $this->ctx->requireEdition();

        $attendance = new AttendanceService($this->ctx->db);

        return $this->render('pages/attendance/leitstand', [
            'title' => 'Leitstand',
            'edition' => $edition,
            'slots' => $attendance->slots((int) $edition['id']),
            'state' => $this->state((int) $edition['id'], $edition),
            'pageScripts' => ['ops-board.js'],
        ]);
    }

    /** GET /{school}/api/leitstand — Zustand für die Aktualisierung. */
    public function apiState(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANWESENHEIT_SEHEN);
        $edition = $this->ctx->requireEdition();

        return ['success' => true, 'state' => $this->state((int) $edition['id'], $edition)];
    }

    /**
     * Gesamtzustand des Messetags.
     *
     * @param  array<string, mixed> $edition
     * @return array<string, mixed>
     */
    private function state(int $editionId, array $edition): array
    {
        $attendance = new AttendanceService($this->ctx->db);
        $currentSlotId = $attendance->currentSlotId($editionId, $edition['event_date'] ?? null);

        $slot = $currentSlotId !== null
            ? $this->ctx->db->fetchOne(
                'SELECT * FROM timeslots WHERE id = ? AND edition_id = ?',
                [$currentSlotId, $editionId],
            )
            : null;

        return [
            'time' => date('H:i'),
            'slot' => $slot === null ? null : [
                'id' => (int) $slot['id'],
                'label' => $slot['slot_name'] !== null && $slot['slot_name'] !== ''
                    ? (string) $slot['slot_name']
                    : 'Slot ' . (int) $slot['slot_number'],
                'start' => substr((string) $slot['start_time'], 0, 5),
                'end' => substr((string) $slot['end_time'], 0, 5),
                'is_break' => (int) $slot['is_break'] === 1,
                'progress' => $this->slotProgress($slot),
            ],
            'totals' => $this->totals($editionId, $currentSlotId),
            'silent_exhibitors' => $currentSlotId === null ? [] : $this->silentExhibitors($editionId, $currentSlotId),
            'rooms' => $currentSlotId === null ? [] : $this->roomLoad($editionId, $currentSlotId),
            'wrong_room' => $this->wrongRoom($editionId),
            'weak_slots' => $this->weakSlots($editionId),
        ];
    }

    /** Fortschritt im laufenden Slot in Prozent. */
    private function slotProgress(array $slot): int
    {
        $start = strtotime(date('Y-m-d ') . (string) $slot['start_time']);
        $end = strtotime(date('Y-m-d ') . (string) $slot['end_time']);
        if ($start === false || $end === false || $end <= $start) {
            return 0;
        }

        $now = time();
        if ($now <= $start) {
            return 0;
        }
        if ($now >= $end) {
            return 100;
        }

        return (int) round(($now - $start) / ($end - $start) * 100);
    }

    /**
     * Kennzahlen für den laufenden Slot und den ganzen Tag.
     *
     * @return array<string, int>
     */
    private function totals(int $editionId, ?int $slotId): array
    {
        $dayPresent = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM attendance WHERE edition_id = ?',
            [$editionId],
        );
        $dayExpected = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM registrations WHERE edition_id = ? AND timeslot_id IS NOT NULL',
            [$editionId],
        );

        $slotPresent = 0;
        $slotExpected = 0;
        $missing = 0;
        if ($slotId !== null) {
            $slotPresent = (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM attendance WHERE edition_id = ? AND timeslot_id = ?',
                [$editionId, $slotId],
            );
            $slotExpected = (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM registrations WHERE edition_id = ? AND timeslot_id = ?',
                [$editionId, $slotId],
            );
            // Zugeteilt, aber (noch) nicht eingecheckt
            $missing = (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*)
                 FROM registrations r
                 WHERE r.edition_id = ? AND r.timeslot_id = ?
                   AND NOT EXISTS (
                       SELECT 1 FROM attendance a
                        WHERE a.user_id = r.user_id AND a.timeslot_id = r.timeslot_id
                          AND a.edition_id = r.edition_id
                   )',
                [$editionId, $slotId],
            );
        }

        return [
            'slot_present' => $slotPresent,
            'slot_expected' => $slotExpected,
            'slot_missing' => $missing,
            'slot_quote' => $slotExpected > 0 ? (int) round($slotPresent / $slotExpected * 100) : 0,
            'day_present' => $dayPresent,
            'day_expected' => $dayExpected,
            'day_quote' => $dayExpected > 0 ? (int) round($dayPresent / $dayExpected * 100) : 0,
        ];
    }

    /**
     * Aussteller, bei denen im laufenden Slot noch niemand eingecheckt hat,
     * obwohl Schüler:innen erwartet werden — der wahrscheinlichste Hinweis
     * auf ein Problem vor Ort.
     *
     * @return list<array<string, mixed>>
     */
    private function silentExhibitors(int $editionId, int $slotId): array
    {
        return $this->ctx->db->fetchAll(
            'SELECT e.id, e.name, r.room_number, r.room_name,
                    (SELECT COUNT(*) FROM registrations rg
                      WHERE rg.exhibitor_id = e.id AND rg.timeslot_id = ? AND rg.edition_id = ?) AS erwartet
             FROM exhibitors e
             LEFT JOIN rooms r ON r.id = e.room_id AND r.edition_id = e.edition_id
             WHERE e.edition_id = ? AND e.active = 1
               AND NOT EXISTS (
                   SELECT 1 FROM attendance a
                    WHERE a.exhibitor_id = e.id AND a.timeslot_id = ? AND a.edition_id = ?
               )
             HAVING erwartet > 0
             ORDER BY erwartet DESC, e.name',
            [$slotId, $editionId, $editionId, $slotId, $editionId],
        );
    }

    /**
     * Auslastung je Raum im laufenden Slot.
     *
     * @return list<array<string, mixed>>
     */
    private function roomLoad(int $editionId, int $slotId): array
    {
        $rows = $this->ctx->db->fetchAll(
            'SELECT r.id, r.room_number, r.room_name, r.capacity,
                    (SELECT COUNT(*) FROM attendance a
                      JOIN exhibitors e2 ON e2.id = a.exhibitor_id
                      WHERE e2.room_id = r.id AND a.timeslot_id = ? AND a.edition_id = ?) AS anwesend,
                    (SELECT COUNT(*) FROM registrations rg
                      JOIN exhibitors e3 ON e3.id = rg.exhibitor_id
                      WHERE e3.room_id = r.id AND rg.timeslot_id = ? AND rg.edition_id = ?) AS erwartet
             FROM rooms r
             WHERE r.edition_id = ?
             ORDER BY r.building, r.floor, r.room_number',
            [$slotId, $editionId, $slotId, $editionId, $editionId],
        );

        $result = [];
        foreach ($rows as $row) {
            $capacity = (int) $row['capacity'];
            $present = (int) $row['anwesend'];
            $result[] = [
                'id' => (int) $row['id'],
                'label' => (string) $row['room_number']
                    . ($row['room_name'] !== null && $row['room_name'] !== '' ? ' · ' . (string) $row['room_name'] : ''),
                'present' => $present,
                'expected' => (int) $row['erwartet'],
                'capacity' => $capacity,
                'load' => $capacity > 0 ? (int) round($present / $capacity * 100) : 0,
                'over' => $capacity > 0 && $present > $capacity,
            ];
        }

        return $result;
    }

    /**
     * Falsch-Raum-Meldungen des Tages, jüngste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    private function wrongRoom(int $editionId): array
    {
        return $this->ctx->db->fetchAll(
            'SELECT a.checked_in_at, u.firstname, u.lastname, u.class,
                    e.name AS exhibitor_name, r.room_number AS actual_room
             FROM attendance a
             JOIN users u ON u.id = a.user_id
             JOIN exhibitors e ON e.id = a.exhibitor_id
             LEFT JOIN rooms r ON r.id = a.actual_room_id
             WHERE a.edition_id = ? AND a.wrong_room = 1
             ORDER BY a.checked_in_at DESC
             LIMIT 15',
            [$editionId],
        );
    }

    /**
     * Slots mit auffällig niedriger Anwesenheitsquote — zeigt im Rückblick,
     * wo der Tag geklemmt hat.
     *
     * @return list<array<string, mixed>>
     */
    private function weakSlots(int $editionId): array
    {
        $rows = $this->ctx->db->fetchAll(
            'SELECT t.id, t.slot_number, t.slot_name, t.start_time, t.end_time,
                    (SELECT COUNT(*) FROM registrations rg
                      WHERE rg.timeslot_id = t.id AND rg.edition_id = ?) AS erwartet,
                    (SELECT COUNT(*) FROM attendance a
                      WHERE a.timeslot_id = t.id AND a.edition_id = ?) AS anwesend
             FROM timeslots t
             WHERE t.edition_id = ? AND t.is_break = 0
             ORDER BY t.slot_number',
            [$editionId, $editionId, $editionId],
        );

        $weak = [];
        foreach ($rows as $row) {
            $expected = (int) $row['erwartet'];
            if ($expected === 0) {
                continue;
            }
            $quote = (int) round((int) $row['anwesend'] / $expected * 100);
            if ($quote >= self::LOW_ATTENDANCE) {
                continue;
            }

            $weak[] = [
                'label' => $row['slot_name'] !== null && $row['slot_name'] !== ''
                    ? (string) $row['slot_name']
                    : 'Slot ' . (int) $row['slot_number'],
                'time' => substr((string) $row['start_time'], 0, 5) . '–' . substr((string) $row['end_time'], 0, 5),
                'expected' => $expected,
                'present' => (int) $row['anwesend'],
                'quote' => $quote,
            ];
        }

        return $weak;
    }
}
