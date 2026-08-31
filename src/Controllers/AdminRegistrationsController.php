<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions;
use App\Services\Capacity;

/**
 * Anmeldungen verwalten: Schüler suchen, Anmeldungen einsehen, manuell
 * hinzufügen, Zeitslot ändern und entfernen.
 *
 * Überbuchung ist nur mit explizitem Haken „Kapazität ignorieren" möglich.
 */
final class AdminRegistrationsController extends Controller
{
    private const SEARCH_LIMIT = 50;

    /** GET /{school}/admin/anmeldungen */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANMELDUNGEN_SEHEN);
        $edition = $this->ctx->requireEdition();

        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $query = trim((string) ($_GET['q'] ?? ''));

        $sql = 'SELECT u.id, u.firstname, u.lastname, u.class, u.username,
                       COUNT(r.id) AS anmeldungen,
                       COUNT(r.timeslot_id) AS zugeteilt
                FROM users u
                LEFT JOIN registrations r ON r.user_id = u.id AND r.edition_id = ?
                WHERE u.role = \'student\' AND u.school_id = ? AND u.edition_id = ?';
        $args = [$editionId, $schoolId, $editionId];

        if ($query !== '') {
            $sql .= ' AND (u.firstname LIKE ? OR u.lastname LIKE ? OR u.class LIKE ? OR u.username LIKE ?)';
            $like = '%' . $query . '%';
            array_push($args, $like, $like, $like, $like);
        }
        $sql .= ' GROUP BY u.id, u.firstname, u.lastname, u.class, u.username
                  ORDER BY u.lastname, u.firstname
                  LIMIT ' . self::SEARCH_LIMIT;

        $students = $this->ctx->db->fetchAll($sql, $args);

        $studentId = (int) ($_GET['student'] ?? 0);
        $student = null;
        $registrations = [];
        if ($studentId > 0) {
            $student = $this->findStudent($studentId, $schoolId, $editionId);
            if ($student !== null) {
                $registrations = $this->ctx->db->fetchAll(
                    'SELECT r.id, r.exhibitor_id, r.timeslot_id, r.priority, r.registration_type, r.registered_at,
                            e.name AS exhibitor_name,
                            rm.room_number,
                            t.slot_number, t.slot_name, t.start_time, t.end_time
                     FROM registrations r
                     JOIN exhibitors e ON e.id = r.exhibitor_id
                     LEFT JOIN rooms rm ON rm.id = e.room_id
                     LEFT JOIN timeslots t ON t.id = r.timeslot_id
                     WHERE r.user_id = ? AND r.edition_id = ?
                     ORDER BY t.slot_number IS NULL, t.slot_number, e.name',
                    [$studentId, $editionId],
                );
            }
        }

        return $this->render('pages/admin-registrations/index', [
            'title' => 'Anmeldungen',
            'edition' => $edition,
            'query' => $query,
            'students' => $students,
            'student' => $student,
            'registrations' => $registrations,
            'exhibitors' => $this->ctx->db->fetchAll(
                'SELECT id, name, active FROM exhibitors WHERE edition_id = ? ORDER BY active DESC, name',
                [$editionId],
            ),
            'slots' => $this->ctx->db->fetchAll(
                'SELECT id, slot_number, slot_name, start_time, end_time, is_managed, is_break
                 FROM timeslots WHERE edition_id = ? AND is_break = 0
                 ORDER BY start_time, slot_number',
                [$editionId],
            ),
            'max' => RegistrationController::maxRegistrations($edition),
            'canCreate' => $this->ctx->auth->can(Permissions::ANMELDUNGEN_ERSTELLEN, $schoolId),
            'canDelete' => $this->ctx->auth->can(Permissions::ANMELDUNGEN_LOESCHEN, $schoolId),
            'searchLimit' => self::SEARCH_LIMIT,
        ]);
    }

    /** POST /{school}/admin/anmeldungen/hinzufuegen */
    public function store(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANMELDUNGEN_ERSTELLEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $studentId = (int) ($_POST['student_id'] ?? 0);
        $exhibitorId = (int) ($_POST['exhibitor_id'] ?? 0);
        $timeslotId = $this->postSlotId();
        $priority = $this->postPriority(RegistrationController::maxRegistrations($edition));
        $ignoreCapacity = isset($_POST['ignore_capacity']);
        $back = $this->ctx->schoolUrl('/admin/anmeldungen?student=' . $studentId);

        $student = $this->findStudent($studentId, $schoolId, $editionId);
        if ($student === null) {
            $this->flash('error', 'Diese Schülerin/dieser Schüler gehört nicht zu dieser Schule oder Messe.');
            $this->redirect($this->ctx->schoolUrl('/admin/anmeldungen'));
        }

        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT id, name FROM exhibitors WHERE id = ? AND edition_id = ?',
            [$exhibitorId, $editionId],
        );
        if ($exhibitor === null) {
            $this->flash('error', 'Dieser Aussteller gehört nicht zur aktuellen Messe.');
            $this->redirect($back);
        }

        if ($timeslotId !== null && !$this->slotExists($timeslotId, $editionId)) {
            $this->flash('error', 'Dieser Zeitslot gehört nicht zur aktuellen Messe.');
            $this->redirect($back);
        }

        $duplicate = $this->ctx->db->fetchValue(
            'SELECT 1 FROM registrations WHERE user_id = ? AND exhibitor_id = ?',
            [$studentId, $exhibitorId],
        );
        if ($duplicate !== null) {
            $this->flash('error', 'Es besteht bereits eine Anmeldung bei diesem Aussteller.');
            $this->redirect($back);
        }

        if ($timeslotId !== null) {
            if ($this->hasSlotConflict($studentId, $editionId, $timeslotId, null)) {
                $this->flash('error', 'In diesem Zeitslot besteht bereits eine Zuteilung.');
                $this->redirect($back);
            }
            if (!$ignoreCapacity && !$this->capacityAvailable($editionId, $exhibitorId, $timeslotId)) {
                $this->flash('error', 'Die Kapazität dieses Ausstellers ist in diesem Slot erschöpft. Setze den Haken „Kapazität ignorieren“, um trotzdem zu buchen.');
                $this->redirect($back);
            }
        }

        $this->ctx->db->run(
            'INSERT INTO registrations
                (edition_id, user_id, exhibitor_id, timeslot_id, registration_type, priority)
             VALUES (?, ?, ?, ?, \'manual\', ?)',
            [$editionId, $studentId, $exhibitorId, $timeslotId, $priority],
        );

        $this->ctx->audit->log(
            'Anmeldung manuell erstellt',
            $ignoreCapacity && $timeslotId !== null ? 'warning' : 'info',
            sprintf(
                'Schüler:in: %s %s (%s), Aussteller: %s%s',
                (string) $student['firstname'],
                (string) $student['lastname'],
                (string) ($student['class'] ?? '—'),
                (string) $exhibitor['name'],
                $ignoreCapacity ? ' — Kapazität ignoriert' : '',
            ),
            $schoolId,
        );
        $this->flash('success', 'Anmeldung wurde angelegt.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/anmeldungen/slot */
    public function updateSlot(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANMELDUNGEN_ERSTELLEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $registrationId = (int) ($_POST['registration_id'] ?? 0);
        $timeslotId = $this->postSlotId();
        $ignoreCapacity = isset($_POST['ignore_capacity']);

        $registration = $this->findRegistration($registrationId, $schoolId, $editionId);
        if ($registration === null) {
            $this->flash('error', 'Diese Anmeldung wurde nicht gefunden.');
            $this->redirect($this->ctx->schoolUrl('/admin/anmeldungen'));
        }
        $back = $this->ctx->schoolUrl('/admin/anmeldungen?student=' . (int) $registration['user_id']);

        if ($timeslotId !== null) {
            if (!$this->slotExists($timeslotId, $editionId)) {
                $this->flash('error', 'Dieser Zeitslot gehört nicht zur aktuellen Messe.');
                $this->redirect($back);
            }
            if ($this->hasSlotConflict((int) $registration['user_id'], $editionId, $timeslotId, $registrationId)) {
                $this->flash('error', 'In diesem Zeitslot besteht bereits eine andere Zuteilung.');
                $this->redirect($back);
            }
            $alreadyHere = $registration['timeslot_id'] !== null
                && (int) $registration['timeslot_id'] === $timeslotId;
            if (!$ignoreCapacity && !$alreadyHere
                && !$this->capacityAvailable($editionId, (int) $registration['exhibitor_id'], $timeslotId)) {
                $this->flash('error', 'Die Kapazität dieses Ausstellers ist in diesem Slot erschöpft. Setze den Haken „Kapazität ignorieren“, um trotzdem zu buchen.');
                $this->redirect($back);
            }
        }

        $this->ctx->db->run(
            'UPDATE registrations SET timeslot_id = ? WHERE id = ? AND edition_id = ?',
            [$timeslotId, $registrationId, $editionId],
        );

        $this->ctx->audit->log(
            'Zeitslot einer Anmeldung geändert',
            $ignoreCapacity ? 'warning' : 'info',
            sprintf(
                'Anmeldung #%d (%s, Aussteller: %s) → %s%s',
                $registrationId,
                (string) $registration['student_name'],
                (string) $registration['exhibitor_name'],
                $timeslotId === null ? 'kein Slot' : 'Slot-ID ' . $timeslotId,
                $ignoreCapacity ? ' — Kapazität ignoriert' : '',
            ),
            $schoolId,
        );
        $this->flash('success', 'Zeitslot wurde aktualisiert.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/anmeldungen/entfernen */
    public function destroy(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANMELDUNGEN_LOESCHEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $registrationId = (int) ($_POST['registration_id'] ?? 0);

        $registration = $this->findRegistration($registrationId, $schoolId, $editionId);
        if ($registration === null) {
            $this->flash('error', 'Diese Anmeldung wurde nicht gefunden.');
            $this->redirect($this->ctx->schoolUrl('/admin/anmeldungen'));
        }

        $this->ctx->db->run(
            'DELETE FROM registrations WHERE id = ? AND edition_id = ?',
            [$registrationId, $editionId],
        );
        $this->ctx->audit->log(
            'Anmeldung entfernt',
            'warning',
            sprintf(
                '%s bei %s',
                (string) $registration['student_name'],
                (string) $registration['exhibitor_name'],
            ),
            $schoolId,
        );
        $this->flash('success', 'Anmeldung wurde entfernt.');
        $this->redirect($this->ctx->schoolUrl('/admin/anmeldungen?student=' . (int) $registration['user_id']));
    }

    // ---------- Helfer ----------

    /** @return array<string, mixed>|null */
    private function findStudent(int $studentId, int $schoolId, int $editionId): ?array
    {
        if ($studentId <= 0) {
            return null;
        }

        return $this->ctx->db->fetchOne(
            'SELECT id, firstname, lastname, class, username
             FROM users
             WHERE id = ? AND role = \'student\' AND school_id = ? AND edition_id = ?',
            [$studentId, $schoolId, $editionId],
        );
    }

    /** @return array<string, mixed>|null Anmeldung inkl. Schul-Verifikation. */
    private function findRegistration(int $registrationId, int $schoolId, int $editionId): ?array
    {
        if ($registrationId <= 0) {
            return null;
        }

        return $this->ctx->db->fetchOne(
            'SELECT r.id, r.user_id, r.exhibitor_id, r.timeslot_id,
                    CONCAT(u.firstname, \' \', u.lastname) AS student_name,
                    e.name AS exhibitor_name
             FROM registrations r
             JOIN users u ON u.id = r.user_id
             JOIN exhibitors e ON e.id = r.exhibitor_id
             WHERE r.id = ? AND r.edition_id = ? AND u.school_id = ?',
            [$registrationId, $editionId, $schoolId],
        );
    }

    private function slotExists(int $timeslotId, int $editionId): bool
    {
        return $this->ctx->db->fetchValue(
            'SELECT 1 FROM timeslots WHERE id = ? AND edition_id = ?',
            [$timeslotId, $editionId],
        ) !== null;
    }

    /** Hat die/der Schüler:in in diesem Slot bereits eine andere Zuteilung? */
    private function hasSlotConflict(int $userId, int $editionId, int $timeslotId, ?int $exceptId): bool
    {
        $sql = 'SELECT 1 FROM registrations
                WHERE user_id = ? AND edition_id = ? AND timeslot_id = ?';
        $args = [$userId, $editionId, $timeslotId];
        if ($exceptId !== null) {
            $sql .= ' AND id <> ?';
            $args[] = $exceptId;
        }

        return $this->ctx->db->fetchValue($sql . ' LIMIT 1', $args) !== null;
    }

    private function capacityAvailable(int $editionId, int $exhibitorId, int $timeslotId): bool
    {
        return (new Capacity($this->ctx->db))->hasFree($editionId, $exhibitorId, $timeslotId);
    }

    /** timeslot_id aus dem Formular: leer = keine Zuteilung. */
    private function postSlotId(): ?int
    {
        $raw = trim((string) ($_POST['timeslot_id'] ?? ''));

        return $raw === '' || !ctype_digit($raw) ? null : (int) $raw;
    }

    private function postPriority(int $max): ?int
    {
        $raw = trim((string) ($_POST['priority'] ?? ''));
        if ($raw === '' || !ctype_digit($raw)) {
            return null;
        }
        $value = (int) $raw;

        return $value >= 1 && $value <= $max ? $value : null;
    }
}
