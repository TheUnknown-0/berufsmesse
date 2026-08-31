<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\Audit;
use App\Services\AttendanceService;
use App\Services\QrService;

/**
 * Lehrer-Scanner: Aufsicht scannt die persönlichen QR-Codes der Schüler:innen
 * und checkt sie für den eigenen Raum + Zeitslot ein.
 */
final class TeacherScanController extends Controller
{
    /** GET /{school}/scan */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireScanner();
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $enabled = $this->ctx->settings->getBool('checkin_teacher_scan_enabled', $this->ctx->schoolId(), true);

        $attendance = new AttendanceService($this->ctx->db);
        $slots = $attendance->slots($editionId);

        $rooms = $this->ctx->db->fetchAll(
            'SELECT r.id, r.room_number, r.room_name,
                    (SELECT e.name FROM exhibitors e
                      WHERE e.room_id = r.id AND e.edition_id = r.edition_id AND e.active = 1
                      ORDER BY e.name LIMIT 1) AS exhibitor_name
             FROM rooms r
             WHERE r.edition_id = ?
             ORDER BY r.room_number',
            [$editionId],
        );

        // Eigene Aufsichten (timeslot_id NULL = ganztags)
        $assignments = $this->ctx->db->fetchAll(
            'SELECT tra.room_id, tra.timeslot_id, r.room_number, r.room_name,
                    t.slot_name, t.start_time, t.end_time
             FROM teacher_room_assignments tra
             JOIN rooms r ON r.id = tra.room_id
             LEFT JOIN timeslots t ON t.id = tra.timeslot_id
             WHERE tra.teacher_id = ? AND tra.edition_id = ?
             ORDER BY t.start_time IS NULL DESC, t.start_time',
            [(int) $user['id'], $editionId],
        );

        $currentSlotId = $attendance->currentSlotId($editionId, $edition['event_date'] ?? null);

        $selectedRoomId = null;
        foreach ($assignments as $assignment) {
            $slotId = $assignment['timeslot_id'] !== null ? (int) $assignment['timeslot_id'] : null;
            if ($slotId === $currentSlotId) {
                $selectedRoomId = (int) $assignment['room_id'];
                break;
            }
            if ($slotId === null && $selectedRoomId === null) {
                $selectedRoomId = (int) $assignment['room_id']; // Ganztags als Rückfallebene
            }
        }

        return $this->render('pages/scan/index', [
            'title' => 'Scanner',
            'enabled' => $enabled,
            'edition' => $edition,
            'rooms' => $rooms,
            'slots' => $slots,
            'assignments' => $assignments,
            'selectedRoomId' => $selectedRoomId,
            'selectedSlotId' => $currentSlotId,
            'pageScripts' => ['vendor/jsqr.min.js', 'qr-camera.js', 'teacher-scan.js'],
        ]);
    }

    /** POST /{school}/api/scan/checkin */
    public function apiCheckin(array $params): array
    {
        $this->requireSchool($params['school']);
        $teacher = $this->requireScanner();
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        if (!$this->ctx->settings->getBool('checkin_teacher_scan_enabled', $schoolId, true)) {
            return $this->jsonError('Der Lehrer-Check-in ist an dieser Schule deaktiviert.', 403);
        }

        $teacherId = (int) $teacher['id'];
        $ip = Audit::clientIp();
        $qr = new QrService($this->ctx->db, $this->ctx->settings);
        $attendance = new AttendanceService($this->ctx->db);

        if ($qr->isRateLimited($teacherId, $ip)) {
            return $this->jsonError('Zu viele Fehlversuche. Bitte kurz warten.', 429);
        }

        $input = $this->jsonInput();
        $roomId = (int) ($input['room_id'] ?? 0);
        $timeslotId = (int) ($input['timeslot_id'] ?? 0);
        $studentToken = QrService::extractToken((string) ($input['student_token'] ?? ''));
        $studentUserId = (int) ($input['student_user_id'] ?? 0);
        $force = !empty($input['force']);
        $isManual = $studentToken === '' && $studentUserId > 0;

        if ($roomId <= 0 || $timeslotId <= 0) {
            return $this->jsonError('Bitte zuerst Raum und Zeitslot auswählen.');
        }

        $room = $this->ctx->db->fetchOne(
            'SELECT id, room_number, room_name FROM rooms WHERE id = ? AND edition_id = ?',
            [$roomId, $editionId],
        );
        $slot = $this->ctx->db->fetchOne(
            'SELECT * FROM timeslots WHERE id = ? AND edition_id = ? AND is_break = 0',
            [$timeslotId, $editionId],
        );
        if ($room === null || $slot === null) {
            return $this->jsonError('Raum oder Zeitslot gehört nicht zu dieser Messe.');
        }

        // Schüler:in auflösen
        if ($isManual) {
            $student = $this->ctx->db->fetchOne(
                'SELECT id, firstname, lastname, class FROM users
                 WHERE id = ? AND school_id = ? AND role = \'student\'',
                [$studentUserId, $schoolId],
            );
        } else {
            if ($studentToken === '') {
                $qr->recordAttempt($teacherId, $ip, false);

                return $this->jsonError('Es wurde kein Code erkannt.');
            }
            if (!str_starts_with($studentToken, 'S-')) {
                $qr->recordAttempt($teacherId, $ip, false);

                return $this->jsonError('Das ist kein Schüler-Code. Bitte den persönlichen QR-Code der Schüler:in scannen.');
            }
            $student = $qr->resolveStudentToken($studentToken, $editionId, $schoolId);
        }

        if ($student === null) {
            $qr->recordAttempt($teacherId, $ip, false);

            return $this->jsonError('Unbekannter oder gesperrter Schüler-Code.');
        }

        $studentId = (int) $student['id'];
        $studentName = trim((string) $student['firstname'] . ' ' . (string) $student['lastname']);
        $studentClass = (string) ($student['class'] ?? '');

        $windowError = $qr->windowError($slot, $editionId, true);
        if ($windowError !== null) {
            $qr->recordAttempt($teacherId, $ip, false);

            return $this->jsonError($windowError);
        }

        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT id, name FROM exhibitors
             WHERE room_id = ? AND edition_id = ? AND active = 1
             ORDER BY name LIMIT 1',
            [$roomId, $editionId],
        );
        if ($exhibitor === null) {
            return $this->jsonError('Diesem Raum ist kein aktiver Aussteller zugeordnet.');
        }
        $exhibitorId = (int) $exhibitor['id'];
        $exhibitorName = (string) $exhibitor['name'];

        // Bereits eingecheckt?
        $existing = $attendance->existingCheckin($studentId, $exhibitorId, $timeslotId, $editionId);
        if ($existing !== null) {
            $qr->recordAttempt($teacherId, $ip, true);

            return [
                'success' => true,
                'status' => 'already',
                'student' => ['id' => $studentId, 'name' => $studentName, 'class' => $studentClass],
                'exhibitor' => $exhibitorName,
                'message' => sprintf(
                    '%s ist bereits seit %s Uhr eingecheckt.',
                    $studentName,
                    date('H:i', strtotime((string) $existing['checked_in_at'])),
                ),
            ];
        }

        $wrongRoom = false;
        if (!$attendance->hasRegistration($studentId, $exhibitorId, $timeslotId, $editionId)) {
            if ($attendance->isFreeSlot($slot)) {
                $error = $attendance->ensureFreeSlotRegistration(
                    $studentId,
                    $exhibitorId,
                    $roomId,
                    $timeslotId,
                    $editionId,
                );
                if ($error !== null) {
                    $qr->recordAttempt($teacherId, $ip, false);

                    return $this->jsonError($error);
                }
            } else {
                $target = $attendance->slotRegistration($studentId, $timeslotId, $editionId);
                $targetRoom = $target !== null
                    ? AttendanceService::roomLabel($target['room_number'] ?? null, $target['room_name'] ?? null)
                    : '';

                if (!$force) {
                    $qr->recordAttempt($teacherId, $ip, true);

                    return [
                        'success' => false,
                        'status' => 'requires_confirmation',
                        'student' => ['id' => $studentId, 'name' => $studentName, 'class' => $studentClass],
                        'scanned_exhibitor' => $exhibitorName,
                        'soll_exhibitor' => $target !== null ? (string) $target['exhibitor_name'] : null,
                        'soll_room' => $targetRoom !== '' ? $targetRoom : null,
                        'message' => $target !== null
                            ? sprintf(
                                '%s gehört in diesem Slot zu %s%s.',
                                $studentName,
                                (string) $target['exhibitor_name'],
                                $targetRoom !== '' ? ' (Raum ' . $targetRoom . ')' : '',
                            )
                            : sprintf('%s ist für diesen Zeitslot nicht eingetragen.', $studentName),
                    ];
                }
                $wrongRoom = true;
            }
        }

        $attendance->recordCheckin(
            $editionId,
            $studentId,
            $exhibitorId,
            $timeslotId,
            $isManual ? 'manual' : 'teacher_scan',
            null,
            $teacherId,
            $roomId,
            $wrongRoom,
        );
        $qr->recordAttempt($teacherId, $ip, true);

        // Ist die Lehrkraft laut Aufsichtsplan für Raum + Slot eingeteilt?
        $assigned = $this->ctx->db->fetchValue(
            'SELECT 1 FROM teacher_room_assignments
             WHERE teacher_id = ? AND room_id = ? AND edition_id = ?
               AND (timeslot_id = ? OR timeslot_id IS NULL)
             LIMIT 1',
            [$teacherId, $roomId, $editionId, $timeslotId],
        );
        $isOverride = $assigned === null;

        // Nur auffällige Fälle protokollieren, nicht jeden Scan.
        if ($wrongRoom || $isOverride) {
            $this->ctx->audit->log(
                $isManual ? 'Check-in manuell (Scanner)' : 'Check-in Lehrer-Scan',
                'warning',
                sprintf(
                    'Schüler:in #%d bei %s (Raum #%d, Slot #%d)%s%s',
                    $studentId,
                    $exhibitorName,
                    $roomId,
                    $timeslotId,
                    $wrongRoom ? ' [FALSCHER RAUM]' : '',
                    $isOverride ? ' [OVERRIDE]' : '',
                ),
                $this->ctx->schoolId(),
            );
        }

        return [
            'success' => true,
            'status' => 'checked_in',
            'student' => ['id' => $studentId, 'name' => $studentName, 'class' => $studentClass],
            'exhibitor' => $exhibitorName,
            'wrong_room' => $wrongRoom,
            'override' => $isOverride,
            'message' => $wrongRoom
                ? sprintf('%s eingecheckt — falscher Raum vermerkt.', $studentName)
                : sprintf('%s eingecheckt.', $studentName),
        ];
    }

    /** GET /{school}/api/scan/roster?room=&slot= */
    public function apiRoster(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requireScanner();
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $roomId = (int) ($_GET['room'] ?? 0);
        $timeslotId = (int) ($_GET['slot'] ?? 0);
        if ($roomId <= 0 || $timeslotId <= 0) {
            return $this->jsonError('Raum oder Zeitslot fehlt.');
        }

        $room = $this->ctx->db->fetchOne(
            'SELECT id, room_number, room_name FROM rooms WHERE id = ? AND edition_id = ?',
            [$roomId, $editionId],
        );
        $slotOk = $this->ctx->db->fetchValue(
            'SELECT 1 FROM timeslots WHERE id = ? AND edition_id = ? AND is_break = 0',
            [$timeslotId, $editionId],
        );
        if ($room === null || $slotOk === null) {
            return $this->jsonError('Raum oder Zeitslot gehört nicht zu dieser Messe.');
        }

        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT id, name FROM exhibitors
             WHERE room_id = ? AND edition_id = ? AND active = 1
             ORDER BY name LIMIT 1',
            [$roomId, $editionId],
        );
        if ($exhibitor === null) {
            return [
                'success' => true,
                'exhibitor' => null,
                'room' => AttendanceService::roomLabel($room['room_number'] ?? null, $room['room_name'] ?? null),
                'present' => [],
                'missing' => [],
                'present_count' => 0,
                'expected_count' => 0,
            ];
        }
        $exhibitorId = (int) $exhibitor['id'];

        $presentRows = $this->ctx->db->fetchAll(
            'SELECT u.id, u.firstname, u.lastname, u.class,
                    a.checked_in_at, a.checkin_method, a.wrong_room
             FROM attendance a
             JOIN users u ON u.id = a.user_id
             WHERE a.exhibitor_id = ? AND a.timeslot_id = ? AND a.edition_id = ?
             ORDER BY a.checked_in_at DESC',
            [$exhibitorId, $timeslotId, $editionId],
        );

        $present = [];
        $presentIds = [];
        foreach ($presentRows as $row) {
            $presentIds[(int) $row['id']] = true;
            $present[] = [
                'id' => (int) $row['id'],
                'name' => trim((string) $row['firstname'] . ' ' . (string) $row['lastname']),
                'class' => (string) ($row['class'] ?? ''),
                'time' => $row['checked_in_at'] !== null ? date('H:i', strtotime((string) $row['checked_in_at'])) : '',
                'method' => (string) $row['checkin_method'],
                'wrong_room' => (int) $row['wrong_room'] === 1,
            ];
        }

        $expectedRows = $this->ctx->db->fetchAll(
            'SELECT u.id, u.firstname, u.lastname, u.class
             FROM registrations reg
             JOIN users u ON u.id = reg.user_id
             WHERE reg.exhibitor_id = ? AND reg.timeslot_id = ? AND reg.edition_id = ?
             ORDER BY u.lastname, u.firstname',
            [$exhibitorId, $timeslotId, $editionId],
        );

        $missing = [];
        foreach ($expectedRows as $row) {
            if (isset($presentIds[(int) $row['id']])) {
                continue;
            }
            $missing[] = [
                'id' => (int) $row['id'],
                'name' => trim((string) $row['firstname'] . ' ' . (string) $row['lastname']),
                'class' => (string) ($row['class'] ?? ''),
            ];
        }

        return [
            'success' => true,
            'exhibitor' => (string) $exhibitor['name'],
            'room' => AttendanceService::roomLabel($room['room_number'] ?? null, $room['room_name'] ?? null),
            'present' => $present,
            'missing' => $missing,
            'present_count' => count($present),
            'expected_count' => count($present) + count($missing),
        ];
    }

    /**
     * Zugriff: Lehrkräfte der Schule oder Nutzer mit ANWESENHEIT_BEARBEITEN.
     *
     * @return array<string, mixed>
     */
    private function requireScanner(): array
    {
        $user = $this->requireLogin();
        if (($user['role'] ?? '') === 'teacher') {
            return $user;
        }
        if (!$this->ctx->auth->can(Permissions::ANWESENHEIT_BEARBEITEN, $this->ctx->schoolId())) {
            throw new HttpException(403);
        }

        return $user;
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
