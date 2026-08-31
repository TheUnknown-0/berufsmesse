<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;

/**
 * Lehrer-Bereich: Klassenübersicht mit Einschreibe- und Zuteilungsquote
 * sowie Detailliste je Klasse.
 */
final class TeacherController extends Controller
{
    /** GET /{school}/klassen */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requireClassAccess();
        $edition = $this->ctx->requireEdition();

        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $managedCount = $this->managedSlotCount($editionId);

        $rows = $this->ctx->db->fetchAll(
            'SELECT u.class,
                    COUNT(DISTINCT u.id) AS gesamt,
                    COUNT(DISTINCT r.user_id) AS eingeschrieben
             FROM users u
             LEFT JOIN registrations r ON r.user_id = u.id AND r.edition_id = ?
             WHERE u.role = \'student\' AND u.school_id = ? AND u.edition_id = ?
               AND u.class IS NOT NULL AND u.class <> \'\'
             GROUP BY u.class
             ORDER BY u.class',
            [$editionId, $schoolId, $editionId],
        );

        $complete = [];
        if ($managedCount > 0) {
            $completeRows = $this->ctx->db->fetchAll(
                'SELECT u.class, COUNT(*) AS vollstaendig
                 FROM (
                     SELECT r.user_id
                     FROM registrations r
                     JOIN timeslots t ON t.id = r.timeslot_id AND t.is_managed = 1 AND t.is_break = 0
                     WHERE r.edition_id = ?
                     GROUP BY r.user_id
                     HAVING COUNT(DISTINCT r.timeslot_id) >= ?
                 ) AS fertig
                 JOIN users u ON u.id = fertig.user_id
                 WHERE u.role = \'student\' AND u.school_id = ? AND u.edition_id = ?
                 GROUP BY u.class',
                [$editionId, $managedCount, $schoolId, $editionId],
            );
            foreach ($completeRows as $row) {
                $complete[(string) $row['class']] = (int) $row['vollstaendig'];
            }
        }

        $classes = [];
        foreach ($rows as $row) {
            $class = (string) $row['class'];
            $classes[] = [
                'class' => $class,
                'gesamt' => (int) $row['gesamt'],
                'eingeschrieben' => (int) $row['eingeschrieben'],
                'vollstaendig' => $complete[$class] ?? 0,
            ];
        }

        // Schüler:innen ohne Klassenangabe separat ausweisen
        $withoutClass = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM users
             WHERE role = \'student\' AND school_id = ? AND edition_id = ?
               AND (class IS NULL OR class = \'\')',
            [$schoolId, $editionId],
        );

        return $this->render('pages/teacher/index', [
            'title' => 'Klassen',
            'edition' => $edition,
            'classes' => $classes,
            'managedCount' => $managedCount,
            'withoutClass' => $withoutClass,
        ]);
    }

    /** GET /{school}/klassen/{class} */
    public function show(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requireClassAccess();
        $edition = $this->ctx->requireEdition();

        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $class = (string) ($params['class'] ?? '');

        $students = $this->ctx->db->fetchAll(
            'SELECT u.id, u.firstname, u.lastname, u.username,
                    (SELECT COUNT(*) FROM registrations r
                      WHERE r.user_id = u.id AND r.edition_id = ?) AS anmeldungen,
                    (SELECT COUNT(*) FROM registrations r
                       JOIN timeslots t ON t.id = r.timeslot_id AND t.is_managed = 1 AND t.is_break = 0
                      WHERE r.user_id = u.id AND r.edition_id = ?) AS zugeteilt,
                    (SELECT COUNT(*) FROM attendance a
                      WHERE a.user_id = u.id AND a.edition_id = ?) AS anwesend
             FROM users u
             WHERE u.role = \'student\' AND u.school_id = ? AND u.edition_id = ? AND u.class = ?
             ORDER BY u.lastname, u.firstname',
            [$editionId, $editionId, $editionId, $schoolId, $editionId, $class],
        );

        if ($students === []) {
            throw new HttpException(404, 'Für diese Klasse sind keine Schülerinnen und Schüler eingetragen.');
        }

        $managedSlots = $this->ctx->db->fetchAll(
            'SELECT id, slot_number, slot_name, start_time, end_time
             FROM timeslots
             WHERE edition_id = ? AND is_managed = 1 AND is_break = 0
             ORDER BY start_time, slot_number',
            [$editionId],
        );

        // Zuteilungsmatrix: user_id => timeslot_id => Aussteller/Raum
        $matrix = [];
        $assignments = $this->ctx->db->fetchAll(
            'SELECT r.user_id, r.timeslot_id, r.registration_type,
                    e.name AS exhibitor_name, rm.room_number
             FROM registrations r
             JOIN users u ON u.id = r.user_id
             JOIN exhibitors e ON e.id = r.exhibitor_id
             LEFT JOIN rooms rm ON rm.id = e.room_id
             WHERE r.edition_id = ? AND r.timeslot_id IS NOT NULL
               AND u.school_id = ? AND u.edition_id = ? AND u.class = ?',
            [$editionId, $schoolId, $editionId, $class],
        );
        foreach ($assignments as $row) {
            $matrix[(int) $row['user_id']][(int) $row['timeslot_id']] = $row;
        }

        return $this->render('pages/teacher/class', [
            'title' => 'Klasse ' . $class,
            'edition' => $edition,
            'class' => $class,
            'students' => $students,
            'managedSlots' => $managedSlots,
            'matrix' => $matrix,
            'hasAttendance' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM attendance WHERE edition_id = ?',
                [$editionId],
            ) > 0,
        ]);
    }

    // ---------- Helfer ----------

    private function managedSlotCount(int $editionId): int
    {
        return (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM timeslots WHERE edition_id = ? AND is_managed = 1 AND is_break = 0',
            [$editionId],
        );
    }

    /** Zugriff für Lehrkräfte oder Nutzer mit dem Recht, Anmeldungen zu sehen. */
    private function requireClassAccess(): void
    {
        $user = $this->requireLogin();

        if ($user['role'] === 'teacher') {
            return;
        }
        if ($this->ctx->auth->can(Permissions::ANMELDUNGEN_SEHEN, $this->ctx->schoolId())) {
            return;
        }

        throw new HttpException(403);
    }
}
