<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions;
use App\Services\AttendanceService;

/**
 * Anwesenheit: Verwaltungsliste mit manueller Pflege, Live-Monitor
 * und Auswertung nach der Messe.
 */
final class AttendanceController extends Controller
{
    /** GET /{school}/admin/anwesenheit */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANWESENHEIT_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $attendance = new AttendanceService($this->ctx->db);
        $slots = $attendance->slots($editionId);

        $exhibitors = $this->ctx->db->fetchAll(
            'SELECT id, name FROM exhibitors WHERE edition_id = ? AND active = 1 ORDER BY name',
            [$editionId],
        );
        $classes = array_column($this->ctx->db->fetchAll(
            'SELECT DISTINCT class FROM users
             WHERE school_id = ? AND role = \'student\' AND class IS NOT NULL AND class <> \'\'
             ORDER BY class',
            [$schoolId],
        ), 'class');

        $filterSlot = (int) ($_GET['slot'] ?? 0);
        $filterExhibitor = (int) ($_GET['aussteller'] ?? 0);
        $filterClass = trim((string) ($_GET['klasse'] ?? ''));
        $filterStatus = (string) ($_GET['status'] ?? '');

        $sql = 'SELECT reg.user_id, reg.exhibitor_id, reg.timeslot_id, reg.registration_type,
                       u.firstname, u.lastname, u.class,
                       e.name AS exhibitor_name,
                       t.slot_name, t.slot_number, t.start_time, t.end_time,
                       r.room_number, r.room_name,
                       a.id AS attendance_id, a.checked_in_at, a.checkin_method, a.wrong_room
                FROM registrations reg
                JOIN users u ON u.id = reg.user_id
                JOIN exhibitors e ON e.id = reg.exhibitor_id
                JOIN timeslots t ON t.id = reg.timeslot_id
                LEFT JOIN rooms r ON r.id = e.room_id
                LEFT JOIN attendance a ON a.user_id = reg.user_id
                      AND a.exhibitor_id = reg.exhibitor_id
                      AND a.timeslot_id = reg.timeslot_id
                      AND a.edition_id = reg.edition_id
                WHERE reg.edition_id = ? AND u.school_id = ?';
        $args = [$editionId, $schoolId];

        if ($filterSlot > 0) {
            $sql .= ' AND reg.timeslot_id = ?';
            $args[] = $filterSlot;
        }
        if ($filterExhibitor > 0) {
            $sql .= ' AND reg.exhibitor_id = ?';
            $args[] = $filterExhibitor;
        }
        if ($filterClass !== '') {
            $sql .= ' AND u.class = ?';
            $args[] = $filterClass;
        }
        if ($filterStatus === 'anwesend') {
            $sql .= ' AND a.id IS NOT NULL';
        } elseif ($filterStatus === 'fehlend') {
            $sql .= ' AND a.id IS NULL';
        }
        $sql .= ' ORDER BY t.start_time, t.slot_number, e.name, u.lastname, u.firstname LIMIT 1000';

        $rows = $this->ctx->db->fetchAll($sql, $args);

        $present = 0;
        foreach ($rows as $row) {
            if ($row['attendance_id'] !== null) {
                $present++;
            }
        }

        return $this->render('pages/attendance/index', [
            'title' => 'Anwesenheit',
            'edition' => $edition,
            'rows' => $rows,
            'slots' => $slots,
            'exhibitors' => $exhibitors,
            'classes' => $classes,
            'filterSlot' => $filterSlot,
            'filterExhibitor' => $filterExhibitor,
            'filterClass' => $filterClass,
            'filterStatus' => $filterStatus,
            'presentCount' => $present,
            'canEdit' => $this->ctx->auth->can(Permissions::ANWESENHEIT_BEARBEITEN, $schoolId),
            'pageScripts' => ['attendance-admin.js'],
        ]);
    }

    /** POST /{school}/api/anwesenheit/setzen */
    public function apiSet(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANWESENHEIT_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $input = $this->jsonInput();
        $action = (string) ($input['action'] ?? '');
        $userId = (int) ($input['user_id'] ?? 0);
        $exhibitorId = (int) ($input['exhibitor_id'] ?? 0);
        $timeslotId = (int) ($input['timeslot_id'] ?? 0);

        if ($userId <= 0 || $exhibitorId <= 0 || $timeslotId <= 0) {
            return $this->jsonError('Unvollständige Angaben.');
        }

        $student = $this->ctx->db->fetchOne(
            'SELECT id, firstname, lastname FROM users WHERE id = ? AND school_id = ? AND role = \'student\'',
            [$userId, $schoolId],
        );
        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT id, name, room_id FROM exhibitors WHERE id = ? AND edition_id = ?',
            [$exhibitorId, $editionId],
        );
        $slotOk = $this->ctx->db->fetchValue(
            'SELECT 1 FROM timeslots WHERE id = ? AND edition_id = ?',
            [$timeslotId, $editionId],
        );
        if ($student === null || $exhibitor === null || $slotOk === null) {
            return $this->jsonError('Datensatz gehört nicht zu dieser Schule bzw. Messe.', 404);
        }

        $attendance = new AttendanceService($this->ctx->db);
        $studentName = trim((string) $student['firstname'] . ' ' . (string) $student['lastname']);

        if ($action === 'anwesend') {
            $created = $attendance->recordCheckin(
                $editionId,
                $userId,
                $exhibitorId,
                $timeslotId,
                'manual',
                null,
                $this->ctx->auth->id(),
                $exhibitor['room_id'] !== null ? (int) $exhibitor['room_id'] : null,
            );
            if ($created) {
                $this->ctx->audit->log(
                    'Anwesenheit manuell gesetzt',
                    'info',
                    sprintf('%s (#%d) bei %s, Slot #%d', $studentName, $userId, (string) $exhibitor['name'], $timeslotId),
                    $this->ctx->schoolId(),
                );
            }

            return [
                'success' => true,
                'status' => $created ? 'gesetzt' : 'bereits',
                'checked_in_at' => date('H:i'),
                'message' => $created
                    ? $studentName . ' als anwesend eingetragen.'
                    : $studentName . ' war bereits als anwesend eingetragen.',
            ];
        }

        if ($action === 'abwesend') {
            $removed = $attendance->removeCheckin($userId, $exhibitorId, $timeslotId, $editionId);
            if ($removed) {
                $this->ctx->audit->log(
                    'Anwesenheit entfernt',
                    'warning',
                    sprintf('%s (#%d) bei %s, Slot #%d', $studentName, $userId, (string) $exhibitor['name'], $timeslotId),
                    $this->ctx->schoolId(),
                );
            }

            return [
                'success' => true,
                'status' => $removed ? 'entfernt' : 'nicht_vorhanden',
                'message' => $removed
                    ? 'Anwesenheit von ' . $studentName . ' entfernt.'
                    : 'Es lag keine Anwesenheit vor.',
            ];
        }

        return $this->jsonError('Unbekannte Aktion.');
    }

    /** GET /{school}/admin/anwesenheit-live */
    public function live(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANWESENHEIT_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $attendance = new AttendanceService($this->ctx->db);
        $slots = $attendance->slots($editionId);
        $currentSlotId = $attendance->currentSlotId($editionId, $edition['event_date'] ?? null);

        return $this->render('pages/attendance/live', [
            'title' => 'Anwesenheit live',
            'edition' => $edition,
            'slots' => $slots,
            'currentSlotId' => $currentSlotId,
            'pageScripts' => ['attendance-live.js'],
        ]);
    }

    /** GET /{school}/api/anwesenheit/live?slot= */
    public function apiLive(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANWESENHEIT_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $timeslotId = (int) ($_GET['slot'] ?? 0);
        if ($timeslotId <= 0) {
            return $this->jsonError('Parameter "slot" fehlt (ID eines Zeitslots).');
        }
        $slot = $this->ctx->db->fetchOne(
            'SELECT id, slot_name, slot_number, start_time, end_time FROM timeslots
             WHERE id = ? AND edition_id = ? AND is_break = 0',
            [$timeslotId, $editionId],
        );
        if ($slot === null) {
            return $this->jsonError('Zeitslot gehört nicht zu dieser Messe.');
        }

        $rows = $this->ctx->db->fetchAll(
            'SELECT e.id, e.name, r.id AS room_id, r.room_number, r.room_name,
                    (SELECT COUNT(*) FROM attendance a
                      WHERE a.exhibitor_id = e.id AND a.timeslot_id = ? AND a.edition_id = ?) AS present,
                    (SELECT COUNT(*) FROM registrations rg
                      WHERE rg.exhibitor_id = e.id AND rg.timeslot_id = ? AND rg.edition_id = ?) AS registered,
                    (SELECT COUNT(*) FROM attendance a
                      WHERE a.exhibitor_id = e.id AND a.timeslot_id = ? AND a.edition_id = ? AND a.wrong_room = 1) AS wrong
             FROM exhibitors e
             LEFT JOIN rooms r ON r.id = e.room_id AND r.edition_id = e.edition_id
             WHERE e.edition_id = ? AND e.active = 1
             ORDER BY r.room_number IS NULL, r.room_number, e.name',
            [$timeslotId, $editionId, $timeslotId, $editionId, $timeslotId, $editionId, $editionId],
        );

        $tiles = [];
        $totalPresent = 0;
        $totalExpected = 0;
        $totalWrong = 0;
        foreach ($rows as $row) {
            $present = (int) $row['present'];
            $expected = max((int) $row['registered'], $present);
            $totalPresent += $present;
            $totalExpected += $expected;
            $totalWrong += (int) $row['wrong'];
            $tiles[] = [
                'exhibitor_id' => (int) $row['id'],
                'exhibitor' => (string) $row['name'],
                'room' => AttendanceService::roomLabel($row['room_number'] ?? null, $row['room_name'] ?? null),
                'present' => $present,
                'expected' => $expected,
                'wrong' => (int) $row['wrong'],
                'pct' => $expected > 0 ? (int) round($present / $expected * 100) : 0,
            ];
        }

        $recent = $this->ctx->db->fetchAll(
            'SELECT u.firstname, u.lastname, u.class, e.name AS exhibitor_name,
                    a.checked_in_at, a.checkin_method, a.wrong_room
             FROM attendance a
             JOIN users u ON u.id = a.user_id
             JOIN exhibitors e ON e.id = a.exhibitor_id
             WHERE a.edition_id = ? AND a.timeslot_id = ?
             ORDER BY a.checked_in_at DESC, a.id DESC
             LIMIT 12',
            [$editionId, $timeslotId],
        );

        $latest = [];
        foreach ($recent as $row) {
            $latest[] = [
                'name' => trim((string) $row['firstname'] . ' ' . (string) $row['lastname']),
                'class' => (string) ($row['class'] ?? ''),
                'exhibitor' => (string) $row['exhibitor_name'],
                'time' => $row['checked_in_at'] !== null ? date('H:i', strtotime((string) $row['checked_in_at'])) : '',
                'method' => (string) $row['checkin_method'],
                'wrong_room' => (int) $row['wrong_room'] === 1,
            ];
        }

        return [
            'success' => true,
            'slot' => [
                'id' => (int) $slot['id'],
                'name' => (string) ($slot['slot_name'] ?? ('Slot ' . (string) $slot['slot_number'])),
                'start' => substr((string) $slot['start_time'], 0, 5),
                'end' => substr((string) $slot['end_time'], 0, 5),
            ],
            'tiles' => $tiles,
            'latest' => $latest,
            'total_present' => $totalPresent,
            'total_expected' => $totalExpected,
            'total_missing' => max(0, $totalExpected - $totalPresent),
            'total_wrong' => $totalWrong,
            'total_pct' => $totalExpected > 0 ? (int) round($totalPresent / $totalExpected * 100) : 0,
            'updated_at' => date('H:i:s'),
        ];
    }

    /** GET /{school}/admin/anwesenheit-bericht */
    public function report(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BERICHTE_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $totalRegistrations = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM registrations WHERE edition_id = ? AND timeslot_id IS NOT NULL',
            [$editionId],
        );
        $totalAttendance = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM attendance WHERE edition_id = ?',
            [$editionId],
        );
        $totalWrongRoom = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM attendance WHERE edition_id = ? AND wrong_room = 1',
            [$editionId],
        );

        $methodRows = $this->ctx->db->fetchAll(
            'SELECT checkin_method, COUNT(*) AS anzahl FROM attendance WHERE edition_id = ? GROUP BY checkin_method',
            [$editionId],
        );
        $methods = ['self_scan' => 0, 'teacher_scan' => 0, 'manual' => 0];
        foreach ($methodRows as $row) {
            $methods[(string) $row['checkin_method']] = (int) $row['anzahl'];
        }

        $perSlot = $this->ctx->db->fetchAll(
            'SELECT t.id, t.slot_name, t.slot_number, t.start_time, t.end_time, t.is_managed,
                    (SELECT COUNT(*) FROM registrations rg WHERE rg.timeslot_id = t.id AND rg.edition_id = ?) AS registered,
                    (SELECT COUNT(*) FROM attendance a WHERE a.timeslot_id = t.id AND a.edition_id = ?) AS present,
                    (SELECT COUNT(*) FROM attendance a WHERE a.timeslot_id = t.id AND a.edition_id = ? AND a.wrong_room = 1) AS wrong
             FROM timeslots t
             WHERE t.edition_id = ? AND t.is_break = 0
             ORDER BY t.start_time, t.slot_number',
            [$editionId, $editionId, $editionId, $editionId],
        );

        // Fehlende Schüler:innen je Slot (registriert, aber kein Check-in)
        $missingRows = $this->ctx->db->fetchAll(
            'SELECT reg.timeslot_id, u.firstname, u.lastname, u.class, e.name AS exhibitor_name
             FROM registrations reg
             JOIN users u ON u.id = reg.user_id
             JOIN exhibitors e ON e.id = reg.exhibitor_id
             LEFT JOIN attendance a ON a.user_id = reg.user_id
                   AND a.exhibitor_id = reg.exhibitor_id
                   AND a.timeslot_id = reg.timeslot_id
                   AND a.edition_id = reg.edition_id
             WHERE reg.edition_id = ? AND u.school_id = ? AND reg.timeslot_id IS NOT NULL
               AND a.id IS NULL
             ORDER BY u.class, u.lastname, u.firstname',
            [$editionId, $schoolId],
        );
        $missingBySlot = [];
        foreach ($missingRows as $row) {
            $missingBySlot[(int) $row['timeslot_id']][] = $row;
        }

        $topExhibitors = $this->ctx->db->fetchAll(
            'SELECT e.name, COUNT(a.id) AS anzahl
             FROM attendance a
             JOIN exhibitors e ON e.id = a.exhibitor_id
             WHERE a.edition_id = ?
             GROUP BY e.id, e.name
             ORDER BY anzahl DESC, e.name
             LIMIT 12',
            [$editionId],
        );

        return $this->render('pages/attendance/report', [
            'title' => 'Anwesenheits-Bericht',
            'edition' => $edition,
            'totalRegistrations' => $totalRegistrations,
            'totalAttendance' => $totalAttendance,
            'totalWrongRoom' => $totalWrongRoom,
            'overallPct' => $totalRegistrations > 0 ? (int) round($totalAttendance / $totalRegistrations * 100) : 0,
            'wrongRoomPct' => $totalAttendance > 0 ? round($totalWrongRoom / $totalAttendance * 100, 1) : 0.0,
            'methods' => $methods,
            'perSlot' => $perSlot,
            'missingBySlot' => $missingBySlot,
            'topExhibitors' => $topExhibitors,
        ]);
    }

    /** @return array<string, mixed> JSON-Body des Requests. */
    private function jsonInput(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : $_POST;
    }
}
