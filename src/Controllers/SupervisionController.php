<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions;
use App\Services\AttendanceService;

/**
 * Aufsichtsplan: Lehrkraft je Raum und Zeitslot.
 * Die Spalte „Ganztags“ entspricht einer Zuweisung mit timeslot_id = NULL
 * und gilt für alle Slots (auch die freien).
 */
final class SupervisionController extends Controller
{
    /** GET /{school}/admin/aufsicht */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUFSICHTSPLAN_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $attendance = new AttendanceService($this->ctx->db);
        $slots = $attendance->slots($editionId, true); // nur zugeteilte Slots

        $rooms = $this->ctx->db->fetchAll(
            'SELECT r.id, r.room_number, r.room_name,
                    (SELECT GROUP_CONCAT(e.name ORDER BY e.name SEPARATOR \', \')
                       FROM exhibitors e
                      WHERE e.room_id = r.id AND e.edition_id = r.edition_id AND e.active = 1) AS exhibitor_names
             FROM rooms r
             WHERE r.edition_id = ?
             ORDER BY r.room_number',
            [$editionId],
        );

        $teachers = $this->ctx->db->fetchAll(
            'SELECT id, firstname, lastname FROM users
             WHERE school_id = ? AND role = \'teacher\'
             ORDER BY lastname, firstname',
            [$schoolId],
        );

        $assignmentRows = $this->ctx->db->fetchAll(
            'SELECT tra.id, tra.teacher_id, tra.room_id, tra.timeslot_id,
                    u.firstname, u.lastname
             FROM teacher_room_assignments tra
             JOIN users u ON u.id = tra.teacher_id
             WHERE tra.edition_id = ?',
            [$editionId],
        );

        // [room_id][timeslot_id oder 0 für ganztags] = Zuweisung
        $assignments = [];
        foreach ($assignmentRows as $row) {
            $key = $row['timeslot_id'] !== null ? (int) $row['timeslot_id'] : 0;
            $assignments[(int) $row['room_id']][$key][] = $row;
        }

        // Räume ohne Aufsicht je Slot (Ganztags zählt als betreut)
        $unsupervised = [];
        foreach ($slots as $slot) {
            $slotId = (int) $slot['id'];
            foreach ($rooms as $room) {
                $roomId = (int) $room['id'];
                if (empty($assignments[$roomId][$slotId]) && empty($assignments[$roomId][0])) {
                    $unsupervised[$slotId][] = (string) $room['room_number'];
                }
            }
        }

        return $this->render('pages/supervision/index', [
            'title' => 'Aufsichtsplan',
            'edition' => $edition,
            'rooms' => $rooms,
            'slots' => $slots,
            'teachers' => $teachers,
            'assignments' => $assignments,
            'unsupervised' => $unsupervised,
            'canEdit' => $this->ctx->auth->can(Permissions::AUFSICHTSPLAN_VERWALTEN, $schoolId),
        ]);
    }

    /** POST /{school}/admin/aufsicht */
    public function store(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUFSICHTSPLAN_VERWALTEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $action = (string) ($_POST['action'] ?? '');
        $target = $this->ctx->schoolUrl('/admin/aufsicht');

        if ($action === 'entfernen') {
            $assignmentId = (int) ($_POST['assignment_id'] ?? 0);
            $stmt = $this->ctx->db->run(
                'DELETE FROM teacher_room_assignments WHERE id = ? AND edition_id = ?',
                [$assignmentId, $editionId],
            );
            if ($stmt->rowCount() > 0) {
                $this->ctx->audit->log(
                    'Aufsicht entfernt',
                    'info',
                    'Zuweisung #' . $assignmentId,
                    $this->ctx->schoolId(),
                );
                $this->flash('success', 'Aufsicht entfernt.');
            } else {
                $this->flash('error', 'Diese Zuweisung existiert nicht (mehr).');
            }

            $this->redirect($target);
        }

        if ($action !== 'zuweisen') {
            $this->flash('error', 'Unbekannte Aktion.');
            $this->redirect($target);
        }

        $teacherId = (int) ($_POST['teacher_id'] ?? 0);
        $roomId = (int) ($_POST['room_id'] ?? 0);
        $rawSlot = (string) ($_POST['timeslot_id'] ?? '');
        $timeslotId = ($rawSlot === '' || $rawSlot === 'ganztags') ? null : (int) $rawSlot;

        $teacher = $this->ctx->db->fetchOne(
            'SELECT id, firstname, lastname FROM users WHERE id = ? AND school_id = ? AND role = \'teacher\'',
            [$teacherId, $schoolId],
        );
        $roomOk = $this->ctx->db->fetchValue(
            'SELECT 1 FROM rooms WHERE id = ? AND edition_id = ?',
            [$roomId, $editionId],
        );
        if ($teacher === null || $roomOk === null) {
            $this->flash('error', 'Lehrkraft oder Raum gehört nicht zu dieser Schule bzw. Messe.');
            $this->redirect($target);
        }
        if ($timeslotId !== null) {
            $slotOk = $this->ctx->db->fetchValue(
                'SELECT 1 FROM timeslots WHERE id = ? AND edition_id = ? AND is_break = 0',
                [$timeslotId, $editionId],
            );
            if ($slotOk === null) {
                $this->flash('error', 'Dieser Zeitslot gehört nicht zu dieser Messe.');
                $this->redirect($target);
            }
        }

        $teacherName = trim((string) $teacher['firstname'] . ' ' . (string) $teacher['lastname']);

        // Konflikt: UNIQUE (teacher, edition, timeslot_key) — je Slot nur ein Raum.
        $existing = $this->ctx->db->fetchOne(
            'SELECT tra.id, tra.room_id, r.room_number, r.room_name
             FROM teacher_room_assignments tra
             JOIN rooms r ON r.id = tra.room_id
             WHERE tra.teacher_id = ? AND tra.edition_id = ? AND tra.timeslot_key = ?',
            [$teacherId, $editionId, $timeslotId ?? 0],
        );

        if ($existing !== null) {
            if ((int) $existing['room_id'] === $roomId) {
                $this->flash('info', $teacherName . ' ist hier bereits eingeteilt.');
            } else {
                $room = AttendanceService::roomLabel($existing['room_number'] ?? null, $existing['room_name'] ?? null);
                $this->flash('error', sprintf(
                    '%s hat in diesem Zeitraum bereits Aufsicht in Raum %s. Bitte dort zuerst entfernen.',
                    $teacherName,
                    $room,
                ));
            }

            $this->redirect($target);
        }

        $this->ctx->db->run(
            'INSERT INTO teacher_room_assignments (edition_id, teacher_id, room_id, timeslot_id, assigned_by)
             VALUES (?, ?, ?, ?, ?)',
            [$editionId, $teacherId, $roomId, $timeslotId, $this->ctx->auth->id()],
        );

        $this->ctx->audit->log(
            'Aufsicht zugewiesen',
            'info',
            sprintf(
                '%s (#%d) → Raum #%d, %s',
                $teacherName,
                $teacherId,
                $roomId,
                $timeslotId === null ? 'ganztags' : 'Slot #' . $timeslotId,
            ),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Aufsicht zugewiesen.');

        $this->redirect($target);
    }
}
