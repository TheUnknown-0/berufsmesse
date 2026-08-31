<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\Exports;
use App\Services\Pdf;
use App\Services\QrService;

/**
 * Druckzentrale: PDF-Berichte und Datenexporte.
 *
 * Alle Aktionen, die eine Datei ausliefern, beenden den Request selbst
 * (Rückgabetyp never) — nach Pdf::emit()/Exports::* folgt nichts mehr.
 *
 * Sichtbarkeit: BERICHTE_SEHEN. Erzeugen: BERICHTE_DRUCKEN.
 * Für das Zugangsdaten-PDF zusätzlich BENUTZER_PASSWORT_ZURUECKSETZEN,
 * weil dabei echte Passwörter gesetzt werden.
 */
final class PrintController extends Controller
{
    /** Zeichenvorrat ohne verwechselbare Zeichen (0 O 1 l I). */
    private const PASSWORD_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789';

    private const PASSWORD_LENGTH = 10;

    /** Rollen, für die Zugangsdaten erzeugt werden dürfen. */
    private const PASSWORD_ROLES = ['student', 'teacher', 'orga', 'school_admin'];

    private const ROLE_LABELS = [
        'student' => 'Schüler:in',
        'teacher' => 'Lehrkraft',
        'orga' => 'Orga-Team',
        'school_admin' => 'Schul-Admin',
        'admin' => 'Administrator',
        'exhibitor' => 'Aussteller',
    ];

    private const CHECKIN_METHOD_LABELS = [
        'self_scan' => 'Selbst-Scan',
        'teacher_scan' => 'Lehrer-Scan',
        'manual' => 'Manuell',
    ];

    private const REGISTRATION_TYPE_LABELS = [
        'manual' => 'Manuell',
        'automatic' => 'Automatisch',
        'qr_checkin' => 'QR-Check-in',
    ];

    private ?QrService $qrService = null;

    // =====================================================================
    // Übersicht
    // =====================================================================

    /** GET /{school}/admin/druckzentrale */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BERICHTE_SEHEN);
        $edition = $this->ctx->requireEdition();

        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $db = $this->ctx->db;

        $classes = array_column($db->fetchAll(
            'SELECT DISTINCT class FROM users
             WHERE role = \'student\' AND school_id = ? AND edition_id = ?
               AND class IS NOT NULL AND class <> \'\'
             ORDER BY class',
            [$schoolId, $editionId],
        ), 'class');

        $students = $db->fetchAll(
            'SELECT id, firstname, lastname, class FROM users
             WHERE role = \'student\' AND school_id = ? AND edition_id = ?
             ORDER BY class, lastname, firstname',
            [$schoolId, $editionId],
        );

        $rooms = $db->fetchAll(
            'SELECT id, room_number, room_name FROM rooms WHERE edition_id = ? ORDER BY room_number',
            [$editionId],
        );

        $timeslots = $db->fetchAll(
            'SELECT id, slot_number, slot_name, start_time, end_time, is_managed, is_break
             FROM timeslots WHERE edition_id = ? ORDER BY start_time, slot_number',
            [$editionId],
        );

        $exhibitors = $db->fetchAll(
            'SELECT id, name FROM exhibitors WHERE edition_id = ? AND active = 1 ORDER BY name',
            [$editionId],
        );

        $stats = [
            'students' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM users WHERE role = \'student\' AND school_id = ? AND edition_id = ?',
                [$schoolId, $editionId],
            ),
            'assigned' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM registrations WHERE edition_id = ? AND timeslot_id IS NOT NULL',
                [$editionId],
            ),
            'rooms' => count($rooms),
            'without_password' => (int) $db->fetchValue(
                'SELECT COUNT(*) FROM users
                 WHERE school_id = ? AND (edition_id = ? OR edition_id IS NULL)
                   AND role IN (\'student\', \'teacher\', \'orga\', \'school_admin\')
                   AND (password IS NULL OR password = \'\')',
                [$schoolId, $editionId],
            ),
        ];

        return $this->render('pages/print/index', [
            'title' => 'Druckzentrale',
            'edition' => $edition,
            'classes' => $classes,
            'students' => $students,
            'rooms' => $rooms,
            'timeslots' => $timeslots,
            'exhibitors' => $exhibitors,
            'stats' => $stats,
            'base' => $this->ctx->schoolUrl('/admin/druckzentrale'),
            'canPrint' => $this->ctx->auth->can(Permissions::BERICHTE_DRUCKEN, $schoolId),
            'canResetPasswords' => $this->ctx->auth->can(Permissions::BENUTZER_PASSWORT_ZURUECKSETZEN, $schoolId),
            'passwordRoles' => array_intersect_key(self::ROLE_LABELS, array_flip(self::PASSWORD_ROLES)),
        ]);
    }

    // =====================================================================
    // 1. Persönlicher Plan
    // =====================================================================

    /** GET /{school}/admin/druckzentrale/persoenlicher-plan?user_id=…|class=… */
    public function personalPlan(array $params): never
    {
        $edition = $this->boot($params['school'], Permissions::BERICHTE_DRUCKEN);
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $db = $this->ctx->db;

        $userId = (int) ($_GET['user_id'] ?? 0);
        $class = trim((string) ($_GET['class'] ?? ''));

        $studentSql = 'SELECT id, firstname, lastname, class FROM users
                       WHERE role = \'student\' AND school_id = ? AND edition_id = ?';
        $studentArgs = [$schoolId, $editionId];

        if ($userId > 0) {
            $studentSql .= ' AND id = ?';
            $studentArgs[] = $userId;
            $filterSql = ' AND u.id = ?';
            $filterArg = $userId;
            $scope = 'Einzelplan';
        } elseif ($class !== '') {
            $studentSql .= ' AND class = ?';
            $studentArgs[] = $class;
            $filterSql = ' AND u.class = ?';
            $filterArg = $class;
            $scope = 'Klasse ' . $class;
        } else {
            throw new HttpException(400, 'Bitte eine Klasse oder eine Person auswählen.');
        }

        $studentSql .= ' ORDER BY lastname, firstname';
        $students = $db->fetchAll($studentSql, $studentArgs);

        if ($students === []) {
            throw new HttpException(404, 'Keine passenden Schülerinnen und Schüler gefunden.');
        }

        $slots = $this->timeslots($editionId);

        $registrations = $db->fetchAll(
            'SELECT r.user_id, r.timeslot_id, ex.name AS exhibitor_name,
                    rm.room_number, rm.room_name
             FROM registrations r
             JOIN users u ON u.id = r.user_id AND u.role = \'student\'
                  AND u.school_id = ? AND u.edition_id = ?
             JOIN exhibitors ex ON ex.id = r.exhibitor_id AND ex.edition_id = ?
             LEFT JOIN rooms rm ON rm.id = ex.room_id AND rm.edition_id = ?
             WHERE r.edition_id = ? AND r.timeslot_id IS NOT NULL' . $filterSql,
            [$schoolId, $editionId, $editionId, $editionId, $editionId, $filterArg],
        );

        $byUser = [];
        foreach ($registrations as $row) {
            $byUser[(int) $row['user_id']][(int) $row['timeslot_id']] = $row;
        }

        $pdf = $this->newPdf('Persönlicher Plan');

        foreach ($students as $student) {
            $this->personalPage(
                $pdf,
                $student,
                $slots,
                $byUser[(int) $student['id']] ?? [],
                $this->qr()->studentTokenFor((int) $student['id'], $editionId),
            );
        }

        $pdf->emit('Persoenlicher_Plan_' . $scope . '_' . date('Y-m-d') . '.pdf');
    }

    /**
     * Eine Seite pro Schüler:in.
     *
     * @param array<string, mixed>            $student
     * @param list<array<string, mixed>>      $slots
     * @param array<int, array<string, mixed>> $regsBySlot
     */
    private function personalPage(Pdf $pdf, array $student, array $slots, array $regsBySlot, string $token): void
    {
        $pdf->AddPage();

        $name = trim((string) $student['firstname'] . ' ' . (string) $student['lastname']);
        $class = trim((string) ($student['class'] ?? ''));

        // QR-Code rechts oben
        $qrSize = 32.0;
        $qrX = $pdf->GetPageWidth() - 10.0 - $qrSize;
        $qrY = Pdf::CONTENT_TOP + 1.0;
        $pdf->qr($token, $qrX, $qrY, $qrSize);

        $pdf->SetFont('Helvetica', '', 7);
        $pdf->SetTextColor(110, 118, 128);
        $pdf->SetXY($qrX, $qrY + $qrSize + 0.5);
        $pdf->Cell($qrSize, 4, $pdf->fit('Dein Check-in-Code', $qrSize), 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);

        // Namensblock links
        $blockWidth = $qrX - 14.0;
        $pdf->SetXY(10, Pdf::CONTENT_TOP + 2.0);
        $pdf->SetFont('Helvetica', 'B', 18);
        $pdf->Cell($blockWidth, 10, $pdf->fit($name, $blockWidth), 0, 2, 'L');

        $pdf->SetFont('Helvetica', '', 11);
        $pdf->SetTextColor(90, 98, 108);
        $pdf->Cell($blockWidth, 6, $pdf->fit('Klasse ' . ($class !== '' ? $class : 'ohne Angabe'), $blockWidth), 0, 2, 'L');
        $pdf->Cell(
            $blockWidth,
            6,
            $pdf->fit(
                self::plural(count($regsBySlot), 'feste Zuteilung', 'feste Zuteilungen') . ' an diesem Tag',
                $blockWidth,
            ),
            0,
            2,
            'L',
        );
        $pdf->SetTextColor(0, 0, 0);

        $pdf->SetY(max($pdf->GetY() + 6.0, $qrY + $qrSize + 8.0));
        $pdf->heading('Tagesplan');

        $pdf->setColumns([
            ['Zeit', 32.0, 'L'],
            ['Aussteller / Aktivität', 96.0, 'L'],
            ['Raum', 62.0, 'L'],
        ]);
        $pdf->drawHead();

        if ($slots === []) {
            $pdf->emptyState('Für diese Messe sind noch keine Zeitslots hinterlegt.');
        }

        foreach ($slots as $slot) {
            $slotId = (int) $slot['id'];
            $registration = $regsBySlot[$slotId] ?? null;

            if ((int) $slot['is_break'] === 1) {
                $activity = ((string) ($slot['slot_name'] ?? '')) !== '' ? (string) $slot['slot_name'] : 'Pause';
                $room = '';
            } elseif ($registration !== null) {
                $activity = (string) $registration['exhibitor_name'];
                $room = $this->roomLabel($registration['room_number'] ?? null, $registration['room_name'] ?? null);
            } elseif ((int) $slot['is_managed'] === 1) {
                $activity = 'Keine Zuteilung';
                $room = '-';
            } else {
                $activity = 'Freie Wahl vor Ort';
                $room = '-';
            }

            $pdf->ensureSpace(8.0, true);
            $pdf->drawRow([$this->slotTime($slot), $activity, $room], 7.0);
        }

        $pdf->Ln(4);
        $pdf->note(
            'Feste Zuteilungen sind verbindlich. In Slots mit freier Wahl entscheidest du vor Ort — '
            . 'der Check-in per QR-Code schreibt dich automatisch ein. '
            . 'Zeige den Code oben rechts der Aufsicht zum Einchecken.',
        );
    }

    // =====================================================================
    // 2. Klassenliste
    // =====================================================================

    /** GET /{school}/admin/druckzentrale/klassenliste?class=… */
    public function classList(array $params): never
    {
        $edition = $this->boot($params['school'], Permissions::BERICHTE_DRUCKEN);
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $db = $this->ctx->db;

        $class = trim((string) ($_GET['class'] ?? ''));

        $slots = $db->fetchAll(
            'SELECT id, slot_number, slot_name, start_time, end_time
             FROM timeslots WHERE edition_id = ? AND is_managed = 1 AND is_break = 0
             ORDER BY start_time, slot_number',
            [$editionId],
        );

        $studentSql = 'SELECT id, firstname, lastname, class FROM users
                       WHERE role = \'student\' AND school_id = ? AND edition_id = ?';
        $studentArgs = [$schoolId, $editionId];
        if ($class !== '') {
            $studentSql .= ' AND class = ?';
            $studentArgs[] = $class;
        }
        $studentSql .= ' ORDER BY class, lastname, firstname';
        $students = $db->fetchAll($studentSql, $studentArgs);

        $regSql = 'SELECT r.user_id, r.timeslot_id, ex.name AS exhibitor_name, rm.room_number
                   FROM registrations r
                   JOIN users u ON u.id = r.user_id AND u.role = \'student\'
                        AND u.school_id = ? AND u.edition_id = ?
                   JOIN exhibitors ex ON ex.id = r.exhibitor_id AND ex.edition_id = ?
                   JOIN timeslots t ON t.id = r.timeslot_id AND t.edition_id = ? AND t.is_managed = 1
                   LEFT JOIN rooms rm ON rm.id = ex.room_id AND rm.edition_id = ?
                   WHERE r.edition_id = ?';
        $regArgs = [$schoolId, $editionId, $editionId, $editionId, $editionId, $editionId];
        if ($class !== '') {
            $regSql .= ' AND u.class = ?';
            $regArgs[] = $class;
        }

        $byUser = [];
        foreach ($db->fetchAll($regSql, $regArgs) as $row) {
            $byUser[(int) $row['user_id']][(int) $row['timeslot_id']] = $row;
        }

        $byClass = [];
        foreach ($students as $student) {
            $key = trim((string) ($student['class'] ?? ''));
            $byClass[$key === '' ? 'Ohne Klasse' : $key][] = $student;
        }
        ksort($byClass);

        $pdf = $this->newPdf('Klassenliste', 'L');

        if ($byClass === []) {
            $pdf->AddPage('L');
            $pdf->emptyState('Keine Schülerinnen und Schüler gefunden.');
            $pdf->emit('Klassenliste_' . date('Y-m-d') . '.pdf');
        }

        $nameWidth = 58.0;
        $slotWidth = count($slots) > 0
            ? (277.0 - $nameWidth) / count($slots)
            : 277.0 - $nameWidth;

        foreach ($byClass as $className => $classStudents) {
            $pdf->AddPage('L');
            $pdf->heading(
                $className . ' — ' . self::plural(count($classStudents), 'Schüler:in', 'Schüler:innen'),
            );

            $columns = [['Name', $nameWidth, 'L']];
            foreach ($slots as $slot) {
                $columns[] = [$this->slotLabel($slot), $slotWidth, 'C', $this->slotTime($slot)];
            }
            $pdf->setColumns($columns);
            $pdf->drawHead();

            if ($slots === []) {
                $pdf->emptyState('Es sind keine festen Zuteilungsslots (is_managed) eingerichtet.');
                continue;
            }

            foreach ($classStudents as $student) {
                $values = [(string) $student['lastname'] . ', ' . (string) $student['firstname']];
                $regs = $byUser[(int) $student['id']] ?? [];

                foreach ($slots as $slot) {
                    $registration = $regs[(int) $slot['id']] ?? null;
                    if ($registration === null) {
                        $values[] = '-';
                        continue;
                    }
                    $room = trim((string) ($registration['room_number'] ?? ''));
                    $values[] = (string) $registration['exhibitor_name']
                        . ($room !== '' ? ' (' . $room . ')' : '');
                }

                $pdf->ensureSpace(7.0, true);
                $pdf->drawRow($values, 6.2);
            }
        }

        $pdf->emit('Klassenliste_' . ($class !== '' ? $class . '_' : '') . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 3. Raumplan
    // =====================================================================

    /** GET /{school}/admin/druckzentrale/raumplan?room=… */
    public function roomPlan(array $params): never
    {
        $edition = $this->boot($params['school'], Permissions::BERICHTE_DRUCKEN);
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $db = $this->ctx->db;

        $roomId = (int) ($_GET['room'] ?? 0);

        $roomSql = 'SELECT id, room_number, room_name, building, floor, capacity
                    FROM rooms WHERE edition_id = ?';
        $roomArgs = [$editionId];
        if ($roomId > 0) {
            $roomSql .= ' AND id = ?';
            $roomArgs[] = $roomId;
        }
        $roomSql .= ' ORDER BY room_number';
        $rooms = $db->fetchAll($roomSql, $roomArgs);

        if ($rooms === []) {
            throw new HttpException(404, 'Kein passender Raum gefunden.');
        }

        $slots = $db->fetchAll(
            'SELECT id, slot_number, slot_name, start_time, end_time, is_managed
             FROM timeslots WHERE edition_id = ? AND is_break = 0
             ORDER BY start_time, slot_number',
            [$editionId],
        );

        $exhibitorsByRoom = [];
        foreach ($db->fetchAll(
            'SELECT id, name, room_id FROM exhibitors
             WHERE edition_id = ? AND room_id IS NOT NULL AND active = 1 ORDER BY name',
            [$editionId],
        ) as $exhibitor) {
            $exhibitorsByRoom[(int) $exhibitor['room_id']][] = (string) $exhibitor['name'];
        }

        $regSql = 'SELECT ex.room_id, r.timeslot_id, ex.name AS exhibitor_name,
                          u.firstname, u.lastname, u.class
                   FROM registrations r
                   JOIN users u ON u.id = r.user_id AND u.role = \'student\' AND u.school_id = ?
                   JOIN exhibitors ex ON ex.id = r.exhibitor_id AND ex.edition_id = ?
                        AND ex.room_id IS NOT NULL
                   JOIN timeslots t ON t.id = r.timeslot_id AND t.edition_id = ?
                   JOIN rooms rm ON rm.id = ex.room_id AND rm.edition_id = ?
                   WHERE r.edition_id = ?';
        $regArgs = [$schoolId, $editionId, $editionId, $editionId, $editionId];
        if ($roomId > 0) {
            $regSql .= ' AND ex.room_id = ?';
            $regArgs[] = $roomId;
        }
        $regSql .= ' ORDER BY u.lastname, u.firstname';

        $byRoomSlot = [];
        foreach ($db->fetchAll($regSql, $regArgs) as $row) {
            $byRoomSlot[(int) $row['room_id']][(int) $row['timeslot_id']][] = $row;
        }

        $pdf = $this->newPdf('Raumplan');
        $printed = 0;

        foreach ($rooms as $room) {
            $rid = (int) $room['id'];
            $exhibitors = $exhibitorsByRoom[$rid] ?? [];
            $slotData = $byRoomSlot[$rid] ?? [];

            // Räume ohne Aussteller und ohne Anmeldungen nur drucken,
            // wenn genau dieser Raum angefordert wurde.
            if ($exhibitors === [] && $slotData === [] && $roomId === 0) {
                continue;
            }

            $pdf->AddPage();
            $printed++;

            $pdf->heading('Raum ' . $this->roomLabel($room['room_number'] ?? null, $room['room_name'] ?? null), 16, 10);

            $meta = [];
            if (trim((string) ($room['building'] ?? '')) !== '') {
                $meta[] = 'Gebäude ' . (string) $room['building'];
            }
            if (trim((string) ($room['floor'] ?? '')) !== '') {
                $meta[] = 'Etage ' . (string) $room['floor'];
            }
            $meta[] = 'Kapazität ' . (string) (int) $room['capacity'];
            $meta[] = $exhibitors === [] ? 'kein Aussteller zugewiesen' : 'Aussteller: ' . implode(', ', $exhibitors);
            $pdf->note(implode('  ·  ', $meta), 9);
            $pdf->Ln(2);

            $pdf->setColumns([
                ['Nr.', 12.0, 'C'],
                ['Name', 74.0, 'L'],
                ['Klasse', 26.0, 'L'],
                ['Aussteller', 78.0, 'L'],
            ]);

            foreach ($slots as $slot) {
                $students = $slotData[(int) $slot['id']] ?? [];

                $pdf->ensureSpace(24.0);
                $pdf->band(
                    $this->slotLabel($slot) . '  ·  ' . $this->slotTime($slot)
                        . ((int) $slot['is_managed'] === 1 ? '' : '  ·  ohne feste Zuteilung'),
                    self::plural(count($students), 'Teilnehmer:in', 'Teilnehmende'),
                );

                if ($students === []) {
                    $pdf->SetFont('Helvetica', 'I', 8);
                    $pdf->SetTextColor(120, 128, 138);
                    $pdf->Cell(0, 6, $pdf->t('Keine Anmeldungen für diesen Slot.'), 0, 1, 'L');
                    $pdf->SetTextColor(0, 0, 0);
                    $pdf->Ln(2);
                    continue;
                }

                $pdf->drawHead(7.0);
                foreach ($students as $index => $student) {
                    $pdf->ensureSpace(7.0, true);
                    $pdf->drawRow([
                        (string) ($index + 1),
                        (string) $student['lastname'] . ', ' . (string) $student['firstname'],
                        trim((string) ($student['class'] ?? '')) !== '' ? (string) $student['class'] : '-',
                        (string) $student['exhibitor_name'],
                    ]);
                }
                $pdf->Ln(3);
            }
        }

        if ($printed === 0) {
            $pdf->AddPage();
            $pdf->emptyState('Keine Räume mit Ausstellern oder Anmeldungen gefunden.');
        }

        $pdf->emit('Raumplan_' . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 4. Raumzuteilungs-Übersicht
    // =====================================================================

    /** GET /{school}/admin/druckzentrale/raumzuteilung */
    public function roomAssignment(array $params): never
    {
        $edition = $this->boot($params['school'], Permissions::BERICHTE_DRUCKEN);
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $db = $this->ctx->db;

        $rows = $db->fetchAll(
            'SELECT ex.id, ex.name, ex.room_id, rm.room_number, rm.room_name, rm.capacity,
                    (SELECT COUNT(*) FROM registrations r
                      WHERE r.exhibitor_id = ex.id AND r.edition_id = ? AND r.timeslot_id IS NOT NULL)
                        AS assigned_count,
                    (SELECT COUNT(DISTINCT r2.timeslot_id) FROM registrations r2
                      WHERE r2.exhibitor_id = ex.id AND r2.edition_id = ? AND r2.timeslot_id IS NOT NULL)
                        AS slot_count
             FROM exhibitors ex
             LEFT JOIN rooms rm ON rm.id = ex.room_id AND rm.edition_id = ?
             WHERE ex.edition_id = ? AND ex.active = 1
             ORDER BY (rm.room_number IS NULL), rm.room_number, ex.name',
            [$editionId, $editionId, $editionId, $editionId],
        );

        $supervisors = [];
        foreach ($db->fetchAll(
            'SELECT tra.room_id, tra.timeslot_id, u.firstname, u.lastname,
                    t.slot_name, t.slot_number
             FROM teacher_room_assignments tra
             JOIN users u ON u.id = tra.teacher_id AND u.school_id = ?
             LEFT JOIN timeslots t ON t.id = tra.timeslot_id AND t.edition_id = ?
             WHERE tra.edition_id = ?
             ORDER BY tra.room_id, t.start_time, t.slot_number, u.lastname',
            [$schoolId, $editionId, $editionId],
        ) as $row) {
            $name = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
            $slot = $row['timeslot_id'] === null
                ? 'ganztags'
                : ($this->slotLabel($row) !== '' ? $this->slotLabel($row) : 'Slot');
            $supervisors[(int) $row['room_id']][] = $name . ' (' . $slot . ')';
        }

        $pdf = $this->newPdf('Raumzuteilung', 'L');
        $pdf->AddPage('L');
        $pdf->heading('Raumzuteilung, Anmeldezahlen und Aufsicht');
        $pdf->note(
            'Übersicht für die Schulleitung: welcher Aussteller in welchem Raum, '
            . 'wie viele zugeteilte Anmeldungen und wer die Aufsicht übernimmt.',
        );
        $pdf->Ln(2);

        $pdf->setColumns([
            ['Aussteller', 82.0, 'L'],
            ['Raum', 34.0, 'L'],
            ['Kapazität', 20.0, 'C'],
            ['Slots', 16.0, 'C'],
            ['Anmeldungen', 25.0, 'C'],
            ['Aufsicht', 100.0, 'L'],
        ]);
        $pdf->drawHead();

        if ($rows === []) {
            $pdf->emptyState('Keine aktiven Aussteller vorhanden.');
            $pdf->emit('Raumzuteilung_' . date('Y-m-d') . '.pdf');
        }

        foreach ($rows as $row) {
            $roomId = $row['room_id'] !== null ? (int) $row['room_id'] : 0;
            $supervisorList = $supervisors[$roomId] ?? [];

            $pdf->ensureSpace(7.0, true);
            $pdf->drawRow([
                (string) $row['name'],
                $roomId > 0
                    ? $this->roomLabel($row['room_number'] ?? null, $row['room_name'] ?? null)
                    : 'kein Raum',
                $roomId > 0 ? (string) (int) $row['capacity'] : '-',
                (string) (int) $row['slot_count'],
                (string) (int) $row['assigned_count'],
                $supervisorList === [] ? 'noch offen' : implode(', ', $supervisorList),
            ], 6.4);
        }

        $pdf->Ln(3);
        $pdf->note(
            'Summe zugeteilter Anmeldungen: '
            . array_sum(array_map(static fn (array $r): int => (int) $r['assigned_count'], $rows)),
        );

        $pdf->emit('Raumzuteilung_' . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 5. Abwesenheitsliste
    // =====================================================================

    /** GET /{school}/admin/druckzentrale/abwesenheit?timeslot_id=… */
    public function absent(array $params): never
    {
        $edition = $this->boot($params['school'], Permissions::BERICHTE_DRUCKEN);
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $db = $this->ctx->db;

        $timeslotId = (int) ($_GET['timeslot_id'] ?? 0);

        $sql = 'SELECT t.id AS slot_id, t.slot_number, t.slot_name, t.start_time, t.end_time,
                       u.class, u.firstname, u.lastname,
                       ex.name AS exhibitor_name, rm.room_number, rm.room_name
                FROM registrations r
                JOIN users u ON u.id = r.user_id AND u.role = \'student\' AND u.school_id = ?
                JOIN exhibitors ex ON ex.id = r.exhibitor_id AND ex.edition_id = ?
                JOIN timeslots t ON t.id = r.timeslot_id AND t.edition_id = ?
                LEFT JOIN rooms rm ON rm.id = ex.room_id AND rm.edition_id = ?
                LEFT JOIN attendance a ON a.user_id = r.user_id
                     AND a.exhibitor_id = r.exhibitor_id
                     AND a.timeslot_id = r.timeslot_id
                     AND a.edition_id = ?
                WHERE r.edition_id = ? AND r.timeslot_id IS NOT NULL AND a.id IS NULL';
        $args = [$schoolId, $editionId, $editionId, $editionId, $editionId, $editionId];

        if ($timeslotId > 0) {
            $sql .= ' AND t.id = ?';
            $args[] = $timeslotId;
        }
        $sql .= ' ORDER BY t.start_time, t.slot_number, u.class, u.lastname, u.firstname';

        $rows = $db->fetchAll($sql, $args);

        $grouped = [];
        $slotInfo = [];
        foreach ($rows as $row) {
            $slotId = (int) $row['slot_id'];
            $slotInfo[$slotId] ??= $row;
            $class = trim((string) ($row['class'] ?? ''));
            $grouped[$slotId][$class === '' ? 'Ohne Klasse' : $class][] = $row;
        }

        $pdf = $this->newPdf('Abwesenheitsliste');
        $pdf->AddPage();
        $pdf->heading('Angemeldet, aber nicht eingecheckt');
        $pdf->note(
            'Grundlage: zugeteilte Anmeldungen ohne passenden Check-in-Eintrag. '
            . 'Slots, die noch laufen, erscheinen hier ebenfalls.',
        );
        $pdf->Ln(2);

        if ($rows === []) {
            $pdf->emptyState('Keine offenen Fälle — alle zugeteilten Anmeldungen sind eingecheckt.');
            $pdf->emit('Abwesenheitsliste_' . date('Y-m-d') . '.pdf');
        }

        $pdf->heading(self::plural(count($rows), 'offener Eintrag', 'offene Einträge'), 11, 7);

        $pdf->setColumns([
            ['Nr.', 12.0, 'C'],
            ['Name', 62.0, 'L'],
            ['Aussteller', 76.0, 'L'],
            ['Raum', 40.0, 'L'],
        ]);

        foreach ($grouped as $slotId => $byClass) {
            $slot = $slotInfo[$slotId];
            $total = array_sum(array_map('count', $byClass));

            $pdf->ensureSpace(26.0);
            $pdf->band(
                $this->slotLabel($slot) . '  ·  ' . $this->slotTime($slot),
                $total . ' fehlend',
            );

            ksort($byClass);
            foreach ($byClass as $className => $students) {
                $pdf->ensureSpace(20.0);
                $pdf->SetFont('Helvetica', 'B', 9);
                $pdf->Cell(0, 6, $pdf->fit($className . ' (' . count($students) . ')', $pdf->contentWidth()), 0, 1, 'L');
                $pdf->drawHead(6.5);

                foreach ($students as $index => $student) {
                    $pdf->ensureSpace(7.0, true);
                    $pdf->drawRow([
                        (string) ($index + 1),
                        (string) $student['lastname'] . ', ' . (string) $student['firstname'],
                        (string) $student['exhibitor_name'],
                        $this->roomLabel($student['room_number'] ?? null, $student['room_name'] ?? null),
                    ]);
                }
                $pdf->Ln(2);
            }

            $pdf->Ln(2);
        }

        $pdf->emit('Abwesenheitsliste_' . date('Y-m-d') . '.pdf');
    }

    // =====================================================================
    // 6. Zugangsdaten-PDF (setzt Passwörter!)
    // =====================================================================

    /** POST /{school}/admin/druckzentrale/zugangsdaten */
    public function passwords(array $params): never
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BERICHTE_DRUCKEN);
        $this->requirePermission(Permissions::BENUTZER_PASSWORT_ZURUECKSETZEN);
        $this->requireCsrf();

        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $db = $this->ctx->db;

        $mode = ((string) ($_POST['mode'] ?? 'missing')) === 'reset' ? 'reset' : 'missing';
        $class = trim((string) ($_POST['class'] ?? ''));
        $role = trim((string) ($_POST['role'] ?? ''));

        if ($role !== '' && !in_array($role, self::PASSWORD_ROLES, true)) {
            throw new HttpException(400, 'Unbekannte Rolle.');
        }
        if ($mode === 'reset' && $class === '' && $role === '') {
            throw new HttpException(
                400,
                'Zum Neusetzen von Passwörtern ist eine Einschränkung auf Klasse oder Rolle erforderlich.',
            );
        }

        $placeholders = implode(',', array_fill(0, count(self::PASSWORD_ROLES), '?'));
        $sql = 'SELECT id, firstname, lastname, username, class, role FROM users
                WHERE school_id = ? AND (edition_id = ? OR edition_id IS NULL)
                  AND role IN (' . $placeholders . ') AND id <> ?';
        $args = array_merge([$schoolId, $editionId], self::PASSWORD_ROLES, [(int) $this->ctx->auth->id()]);

        if ($mode === 'missing') {
            $sql .= ' AND (password IS NULL OR password = \'\')';
        }
        if ($class !== '') {
            $sql .= ' AND class = ?';
            $args[] = $class;
        }
        if ($role !== '') {
            $sql .= ' AND role = ?';
            $args[] = $role;
        }
        $sql .= ' ORDER BY COALESCE(class, \'ZZZZ\'), role, lastname, firstname';

        $users = $db->fetchAll($sql, $args);

        if ($users === []) {
            $this->flash('info', 'Es gibt keine passenden Konten — es wurde nichts geändert.');
            $this->redirect($this->ctx->schoolUrl('/admin/druckzentrale'));
        }

        // Passwörter erzeugen und speichern; Klartext existiert nur im PDF.
        $plainPasswords = [];
        foreach (array_keys($users) as $index) {
            $plainPasswords[$index] = self::randomPassword();
        }

        $db->transaction(static function (Database $database) use ($users, $plainPasswords): void {
            foreach ($users as $index => $user) {
                $database->run(
                    'UPDATE users SET password = ?, must_change_password = 1 WHERE id = ?',
                    [password_hash($plainPasswords[$index], PASSWORD_DEFAULT), (int) $user['id']],
                );
            }
        });

        foreach ($plainPasswords as $index => $plain) {
            $users[$index]['plain_password'] = $plain;
        }

        $this->ctx->audit->log(
            'Zugangsdaten-PDF erzeugt',
            'warning',
            sprintf(
                '%d Passwörter neu gesetzt (Modus: %s, Klasse: %s, Rolle: %s)',
                count($users),
                $mode === 'reset' ? 'Auswahl neu setzen' : 'nur Konten ohne Passwort',
                $class !== '' ? $class : 'alle',
                $role !== '' ? $role : 'alle',
            ),
            $schoolId,
        );

        $this->passwordPdf($users, $mode, $class, $role);
    }

    /** @param list<array<string, mixed>> $users */
    private function passwordPdf(array $users, string $mode, string $class, string $role): never
    {
        $pdf = $this->newPdf('Zugangsdaten');
        $pdf->AddPage();

        $pdf->heading('Zugangsdaten — vertraulich');
        $pdf->note(
            'Diese Passwörter sind ausschließlich hier im Klartext lesbar und lassen sich später '
            . 'nicht erneut anzeigen. Beim ersten Login muss ein eigenes Passwort gesetzt werden. '
            . 'Modus: ' . ($mode === 'reset' ? 'Passwörter für die Auswahl neu gesetzt' : 'nur Konten ohne Passwort')
            . ($class !== '' ? ' · Klasse ' . $class : '')
            . ($role !== '' ? ' · Rolle ' . (self::ROLE_LABELS[$role] ?? $role) : '')
            . ' · ' . count($users) . ' Konten.',
        );
        $pdf->Ln(3);

        $cardWidth = 90.0;
        $cardHeight = 50.0;
        $gapX = 4.0;
        $gapY = 4.0;
        $originX = 13.0;
        $originY = $pdf->GetY();
        $columns = 2;
        $rows = (int) floor((297.0 - 16.0 - $originY) / ($cardHeight + $gapY));
        $rows = max(1, $rows);
        $perPage = $columns * $rows;

        foreach (array_values($users) as $index => $user) {
            $position = $index % $perPage;
            if ($index > 0 && $position === 0) {
                $pdf->AddPage();
                $originY = $pdf->GetY();
            }

            $x = $originX + ($position % $columns) * ($cardWidth + $gapX);
            $y = $originY + intdiv($position, $columns) * ($cardHeight + $gapY);

            $pdf->SetDrawColor(170, 178, 188);
            $pdf->SetLineWidth(0.25);
            $pdf->Rect($x, $y, $cardWidth, $cardHeight);

            $pdf->SetXY($x + 4, $y + 4);
            $pdf->SetFont('Helvetica', 'B', 12);
            $pdf->Cell(
                $cardWidth - 8,
                6,
                $pdf->fit((string) $user['lastname'] . ', ' . (string) $user['firstname'], $cardWidth - 8),
                0,
                2,
                'L',
            );

            $pdf->SetFont('Helvetica', '', 8.5);
            $pdf->SetTextColor(100, 108, 118);
            $classLabel = trim((string) ($user['class'] ?? ''));
            $pdf->Cell(
                $cardWidth - 8,
                5,
                $pdf->fit(
                    (self::ROLE_LABELS[(string) $user['role']] ?? (string) $user['role'])
                    . ($classLabel !== '' ? ' · Klasse ' . $classLabel : ''),
                    $cardWidth - 8,
                ),
                0,
                2,
                'L',
            );
            $pdf->SetTextColor(0, 0, 0);

            $pdf->SetXY($x + 4, $y + 21);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(100, 108, 118);
            $pdf->Cell(24, 5, $pdf->t('Benutzername'), 0, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Courier', '', 10);
            $pdf->Cell($cardWidth - 32, 5, $pdf->fit((string) $user['username'], $cardWidth - 32), 0, 1, 'L');

            $pdf->SetXY($x + 4, $y + 28);
            $pdf->SetFont('Helvetica', '', 8);
            $pdf->SetTextColor(100, 108, 118);
            $pdf->Cell(24, 6, $pdf->t('Passwort'), 0, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
            $pdf->SetFont('Courier', 'B', 12);
            $pdf->Cell($cardWidth - 32, 6, $pdf->fit((string) $user['plain_password'], $cardWidth - 32), 0, 1, 'L');

            $pdf->SetXY($x + 4, $y + 38);
            $pdf->SetFont('Helvetica', 'I', 7);
            $pdf->SetTextColor(120, 128, 138);
            $pdf->MultiCell(
                $cardWidth - 8,
                3.6,
                $pdf->t('Bitte vertraulich behandeln. Beim ersten Login wird ein eigenes Passwort gesetzt.'),
                0,
                'L',
            );
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->emit('Zugangsdaten_' . date('Y-m-d_H-i') . '.pdf');
    }

    // =====================================================================
    // 7. QR-Scheckkarten
    // =====================================================================

    /** GET /{school}/admin/druckzentrale/qr-karten?class=… */
    public function qrCards(array $params): never
    {
        $edition = $this->boot($params['school'], Permissions::BERICHTE_DRUCKEN);
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();
        $db = $this->ctx->db;

        $class = trim((string) ($_GET['class'] ?? ''));

        $sql = 'SELECT id, firstname, lastname, class FROM users
                WHERE role = \'student\' AND school_id = ? AND edition_id = ?';
        $args = [$schoolId, $editionId];
        if ($class !== '') {
            $sql .= ' AND class = ?';
            $args[] = $class;
        }
        $sql .= ' ORDER BY class, lastname, firstname';

        $students = $db->fetchAll($sql, $args);
        if ($students === []) {
            throw new HttpException(404, 'Keine passenden Schülerinnen und Schüler gefunden.');
        }

        $schoolName = (string) ($this->ctx->school['name'] ?? '');

        // Scheckkartenformat 85,6 × 54 mm — 2 × 5 Karten je A4-Seite.
        $cardWidth = 85.6;
        $cardHeight = 54.0;
        $columns = 2;
        $rows = 5;
        $perPage = $columns * $rows;
        $originX = (210.0 - $columns * $cardWidth) / 2;
        $originY = (297.0 - $rows * $cardHeight) / 2;

        $pdf = $this->newPdf('QR-Karten', 'P', false);
        $pdf->SetAutoPageBreak(false);

        foreach ($students as $index => $student) {
            $position = $index % $perPage;
            if ($position === 0) {
                $pdf->AddPage();
                $this->cutMarks($pdf, $originX, $originY, $cardWidth, $cardHeight, $columns, $rows);
            }

            $x = $originX + ($position % $columns) * $cardWidth;
            $y = $originY + intdiv($position, $columns) * $cardHeight;

            $pdf->SetDrawColor(205, 210, 216);
            $pdf->SetLineWidth(0.15);
            $pdf->Rect($x, $y, $cardWidth, $cardHeight);

            // Schulname
            $pdf->SetXY($x + 4, $y + 3.5);
            $pdf->SetFont('Helvetica', 'B', 7.5);
            $pdf->SetTextColor(120, 128, 138);
            $pdf->Cell($cardWidth - 8, 4, $pdf->fit($schoolName, $cardWidth - 8), 0, 0, 'L');
            $pdf->SetTextColor(0, 0, 0);

            // QR links
            $pdf->qr(
                $this->qr()->studentTokenFor((int) $student['id'], $editionId),
                $x + 3.0,
                $y + 9.0,
                36.0,
            );

            // Text rechts
            $textX = $x + 42.0;
            $textWidth = $cardWidth - 46.0;

            $pdf->SetXY($textX, $y + 13.0);
            $pdf->SetFont('Helvetica', 'B', 11);
            $pdf->MultiCell(
                $textWidth,
                5.2,
                $pdf->t((string) $student['firstname'] . ' ' . (string) $student['lastname']),
                0,
                'L',
            );

            $classLabel = trim((string) ($student['class'] ?? ''));
            $pdf->SetXY($textX, $y + 28.0);
            $pdf->SetFont('Helvetica', '', 9.5);
            $pdf->Cell($textWidth, 5, $pdf->fit('Klasse ' . ($classLabel !== '' ? $classLabel : '-'), $textWidth), 0, 0, 'L');

            $pdf->SetXY($textX, $y + 40.0);
            $pdf->SetFont('Helvetica', '', 6.8);
            $pdf->SetTextColor(120, 128, 138);
            $pdf->MultiCell($textWidth, 3.2, $pdf->t('Persönlicher Check-in-Code — der Aufsicht zeigen.'), 0, 'L');
            $pdf->SetTextColor(0, 0, 0);
        }

        $pdf->emit('QR-Karten_' . ($class !== '' ? $class . '_' : '') . date('Y-m-d') . '.pdf');
    }

    /** Schnittmarken am Rand des Kartenrasters. */
    private function cutMarks(
        Pdf $pdf,
        float $originX,
        float $originY,
        float $cardWidth,
        float $cardHeight,
        int $columns,
        int $rows,
    ): void {
        $pdf->SetDrawColor(120, 128, 138);
        $pdf->SetLineWidth(0.2);
        $length = 5.0;

        for ($c = 0; $c <= $columns; $c++) {
            $x = $originX + $c * $cardWidth;
            $pdf->Line($x, $originY - $length, $x, $originY - 1.0);
            $pdf->Line($x, $originY + $rows * $cardHeight + 1.0, $x, $originY + $rows * $cardHeight + $length);
        }

        for ($r = 0; $r <= $rows; $r++) {
            $y = $originY + $r * $cardHeight;
            $pdf->Line($originX - $length, $y, $originX - 1.0, $y);
            $pdf->Line($originX + $columns * $cardWidth + 1.0, $y, $originX + $columns * $cardWidth + $length, $y);
        }
    }

    // =====================================================================
    // 8. Exporte
    // =====================================================================

    /** GET /{school}/admin/druckzentrale/export?type=…&format=csv|xlsx */
    public function export(array $params): never
    {
        $edition = $this->boot($params['school'], Permissions::BERICHTE_DRUCKEN);
        $editionId = (int) $edition['id'];
        $schoolId = (int) $this->ctx->schoolId();

        $type = (string) ($_GET['type'] ?? 'anmeldungen');
        $format = strtolower((string) ($_GET['format'] ?? 'csv')) === 'xlsx' ? 'xlsx' : 'csv';
        $class = trim((string) ($_GET['class'] ?? ''));
        $exhibitorId = (int) ($_GET['exhibitor_id'] ?? 0);
        $timeslotId = (int) ($_GET['timeslot_id'] ?? 0);

        if ($type === 'anwesenheit') {
            [$header, $rows, $name] = $this->attendanceExport($editionId, $schoolId, $class, $exhibitorId, $timeslotId);
        } elseif ($type === 'nicht-eingeschrieben') {
            [$header, $rows, $name] = $this->unregisteredExport($editionId, $schoolId, $class);
        } elseif ($type === 'anmeldungen') {
            [$header, $rows, $name] = $this->registrationExport($editionId, $schoolId, $class, $exhibitorId, $timeslotId);
        } else {
            throw new HttpException(400, 'Unbekannter Exporttyp.');
        }

        Exports::deliver($format, $header, $rows, $name);
    }

    /** @return array{0: list<string>, 1: list<list<string>>, 2: string} */
    private function registrationExport(
        int $editionId,
        int $schoolId,
        string $class,
        int $exhibitorId,
        int $timeslotId,
    ): array {
        $sql = 'SELECT u.lastname, u.firstname, u.class, u.username,
                       ex.name AS exhibitor_name, r.priority, r.registration_type, r.registered_at,
                       t.slot_name, t.slot_number, t.start_time, t.end_time,
                       rm.room_number
                FROM registrations r
                JOIN users u ON u.id = r.user_id AND u.role = \'student\' AND u.school_id = ?
                JOIN exhibitors ex ON ex.id = r.exhibitor_id AND ex.edition_id = ?
                LEFT JOIN timeslots t ON t.id = r.timeslot_id AND t.edition_id = ?
                LEFT JOIN rooms rm ON rm.id = ex.room_id AND rm.edition_id = ?
                WHERE r.edition_id = ?';
        $args = [$schoolId, $editionId, $editionId, $editionId, $editionId];

        if ($class !== '') {
            $sql .= ' AND u.class = ?';
            $args[] = $class;
        }
        if ($exhibitorId > 0) {
            $sql .= ' AND r.exhibitor_id = ?';
            $args[] = $exhibitorId;
        }
        if ($timeslotId > 0) {
            $sql .= ' AND r.timeslot_id = ?';
            $args[] = $timeslotId;
        }
        $sql .= ' ORDER BY u.class, u.lastname, u.firstname, t.start_time, t.slot_number';

        $header = [
            'Nachname', 'Vorname', 'Klasse', 'Benutzername', 'Aussteller',
            'Priorität', 'Slot', 'Von', 'Bis', 'Raum', 'Typ', 'Angemeldet am',
        ];

        $rows = [];
        foreach ($this->ctx->db->fetchAll($sql, $args) as $row) {
            $rows[] = [
                (string) $row['lastname'],
                (string) $row['firstname'],
                (string) ($row['class'] ?? ''),
                (string) $row['username'],
                (string) $row['exhibitor_name'],
                $row['priority'] !== null ? (string) (int) $row['priority'] : '',
                $row['slot_number'] !== null ? $this->slotLabel($row) : 'nicht zugeteilt',
                $this->time($row['start_time'] ?? null),
                $this->time($row['end_time'] ?? null),
                (string) ($row['room_number'] ?? ''),
                self::REGISTRATION_TYPE_LABELS[(string) $row['registration_type']]
                    ?? (string) $row['registration_type'],
                $this->dateTime($row['registered_at'] ?? null),
            ];
        }

        return [$header, $rows, 'Anmeldungen'];
    }

    /** @return array{0: list<string>, 1: list<list<string>>, 2: string} */
    private function attendanceExport(
        int $editionId,
        int $schoolId,
        string $class,
        int $exhibitorId,
        int $timeslotId,
    ): array {
        $sql = 'SELECT u.lastname, u.firstname, u.class,
                       ex.name AS exhibitor_name,
                       t.slot_name, t.slot_number, t.start_time,
                       rm.room_number AS actual_room,
                       planned.room_number AS planned_room,
                       a.checkin_method, a.wrong_room, a.checked_in_at,
                       ci.firstname AS checker_firstname, ci.lastname AS checker_lastname
                FROM attendance a
                JOIN users u ON u.id = a.user_id AND u.school_id = ?
                JOIN exhibitors ex ON ex.id = a.exhibitor_id AND ex.edition_id = ?
                JOIN timeslots t ON t.id = a.timeslot_id AND t.edition_id = ?
                LEFT JOIN rooms rm ON rm.id = a.actual_room_id AND rm.edition_id = ?
                LEFT JOIN rooms planned ON planned.id = ex.room_id AND planned.edition_id = ?
                LEFT JOIN users ci ON ci.id = a.checked_in_by
                WHERE a.edition_id = ?';
        $args = [$schoolId, $editionId, $editionId, $editionId, $editionId, $editionId];

        if ($class !== '') {
            $sql .= ' AND u.class = ?';
            $args[] = $class;
        }
        if ($exhibitorId > 0) {
            $sql .= ' AND a.exhibitor_id = ?';
            $args[] = $exhibitorId;
        }
        if ($timeslotId > 0) {
            $sql .= ' AND a.timeslot_id = ?';
            $args[] = $timeslotId;
        }
        $sql .= ' ORDER BY t.start_time, t.slot_number, u.class, u.lastname, u.firstname';

        $header = [
            'Nachname', 'Vorname', 'Klasse', 'Aussteller', 'Slot',
            'Geplanter Raum', 'Tatsächlicher Raum', 'Falscher Raum',
            'Methode', 'Check-in um', 'Eingecheckt von',
        ];

        $rows = [];
        foreach ($this->ctx->db->fetchAll($sql, $args) as $row) {
            $checker = trim(
                (string) ($row['checker_firstname'] ?? '') . ' ' . (string) ($row['checker_lastname'] ?? ''),
            );
            $rows[] = [
                (string) $row['lastname'],
                (string) $row['firstname'],
                (string) ($row['class'] ?? ''),
                (string) $row['exhibitor_name'],
                $this->slotLabel($row),
                (string) ($row['planned_room'] ?? ''),
                (string) ($row['actual_room'] ?? ''),
                (int) $row['wrong_room'] === 1 ? 'ja' : 'nein',
                self::CHECKIN_METHOD_LABELS[(string) $row['checkin_method']] ?? (string) $row['checkin_method'],
                $this->dateTime($row['checked_in_at'] ?? null),
                $checker,
            ];
        }

        return [$header, $rows, 'Anwesenheit'];
    }

    /** @return array{0: list<string>, 1: list<list<string>>, 2: string} */
    private function unregisteredExport(int $editionId, int $schoolId, string $class): array
    {
        $sql = 'SELECT u.lastname, u.firstname, u.class, u.username,
                       (u.password IS NULL OR u.password = \'\') AS no_password
                FROM users u
                LEFT JOIN registrations r ON r.user_id = u.id AND r.edition_id = ?
                WHERE u.role = \'student\' AND u.school_id = ? AND u.edition_id = ? AND r.id IS NULL';
        $args = [$editionId, $schoolId, $editionId];

        if ($class !== '') {
            $sql .= ' AND u.class = ?';
            $args[] = $class;
        }
        $sql .= ' ORDER BY u.class, u.lastname, u.firstname';

        $header = ['Nachname', 'Vorname', 'Klasse', 'Benutzername', 'Konto ohne Passwort'];

        $rows = [];
        foreach ($this->ctx->db->fetchAll($sql, $args) as $row) {
            $rows[] = [
                (string) $row['lastname'],
                (string) $row['firstname'],
                (string) ($row['class'] ?? ''),
                (string) $row['username'],
                (int) $row['no_password'] === 1 ? 'ja' : 'nein',
            ];
        }

        return [$header, $rows, 'Nicht_eingeschrieben'];
    }

    // =====================================================================
    // Helfer
    // =====================================================================

    /**
     * Standard-Guards für alle erzeugenden Endpunkte.
     *
     * @return array<string, mixed> Aktive Edition.
     */
    private function boot(string $schoolSlug, string $permission): array
    {
        $this->requireSchool($schoolSlug);
        $this->requirePermission($permission);

        return $this->ctx->requireEdition();
    }

    private function newPdf(string $documentTitle, string $orientation = 'P', bool $showHeader = true): Pdf
    {
        return new Pdf(
            $orientation,
            (string) ($this->ctx->school['name'] ?? 'Berufsmesse'),
            (string) ($this->ctx->edition['name'] ?? ''),
            $documentTitle,
            $showHeader,
        );
    }

    /** QR-Dienst (erzeugt Schüler-Token bei Bedarf lazy). */
    private function qr(): QrService
    {
        return $this->qrService ??= new QrService($this->ctx->db, $this->ctx->settings);
    }

    /** @return list<array<string, mixed>> Alle Slots der Edition, chronologisch. */
    private function timeslots(int $editionId): array
    {
        return $this->ctx->db->fetchAll(
            'SELECT id, slot_number, slot_name, start_time, end_time, is_managed, is_break
             FROM timeslots WHERE edition_id = ? ORDER BY start_time, slot_number',
            [$editionId],
        );
    }

    /** @param array<string, mixed> $slot */
    private function slotLabel(array $slot): string
    {
        $name = trim((string) ($slot['slot_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return 'Slot ' . (string) (int) ($slot['slot_number'] ?? 0);
    }

    /** @param array<string, mixed> $slot */
    private function slotTime(array $slot): string
    {
        $start = $this->time($slot['start_time'] ?? null);
        $end = $this->time($slot['end_time'] ?? null);

        if ($start === '' && $end === '') {
            return '';
        }

        return $start . ' - ' . $end;
    }

    private function time(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? '' : substr($text, 0, 5);
    }

    private function dateTime(mixed $value): string
    {
        $text = trim((string) ($value ?? ''));
        if ($text === '') {
            return '';
        }

        $timestamp = strtotime($text);

        return $timestamp === false ? $text : date('d.m.Y H:i', $timestamp);
    }

    private function roomLabel(mixed $number, mixed $name): string
    {
        $number = trim((string) ($number ?? ''));
        $name = trim((string) ($name ?? ''));

        if ($number === '' && $name === '') {
            return '-';
        }
        if ($number === '') {
            return $name;
        }
        if ($name === '') {
            return $number;
        }

        return $number . ' (' . $name . ')';
    }

    /** Zahl mit passender Singular-/Pluralform. */
    private static function plural(int $count, string $singular, string $plural): string
    {
        return $count . ' ' . ($count === 1 ? $singular : $plural);
    }

    /** Zufallspasswort ohne verwechselbare Zeichen. */
    private static function randomPassword(): string
    {
        $alphabet = self::PASSWORD_ALPHABET;
        $max = strlen($alphabet) - 1;
        $password = '';

        for ($i = 0; $i < self::PASSWORD_LENGTH; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }
}
