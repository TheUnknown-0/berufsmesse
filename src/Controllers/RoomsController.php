<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;

/**
 * Räume der aktiven Edition inkl. Aussteller↔Raum-Zuteilung.
 *
 * Die Zuteilung schreibt exhibitors.room_id und verlangt RAEUME_BEARBEITEN.
 * Beim Löschen eines Raums setzt der FK exhibitors.room_id auf NULL —
 * die Anzahl betroffener Aussteller wird vorher angezeigt.
 */
final class RoomsController extends Controller
{
    /** GET /{school}/admin/raeume */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::RAEUME_SEHEN);
        $edition = $this->ctx->requireEdition();

        $rooms = $this->ctx->db->fetchAll(
            'SELECT * FROM rooms WHERE edition_id = ? ORDER BY room_number ASC',
            [(int) $edition['id']],
        );

        $exhibitors = $this->ctx->db->fetchAll(
            'SELECT id, name, short_description, room_id, active
             FROM exhibitors WHERE edition_id = ? ORDER BY name ASC',
            [(int) $edition['id']],
        );

        $assigned = [];
        $unassigned = [];
        foreach ($exhibitors as $exhibitor) {
            if ($exhibitor['room_id'] === null) {
                $unassigned[] = $exhibitor;
                continue;
            }
            $assigned[(int) $exhibitor['room_id']][] = $exhibitor;
        }

        return $this->render('pages/rooms/index', [
            'title' => 'Räume',
            'rooms' => $rooms,
            'assigned' => $assigned,
            'unassigned' => $unassigned,
            'exhibitorCount' => count($exhibitors),
            // Zuteilen per Ziehen — dieselbe Bedienung wie in der Raumplanung.
            'pageScripts' => ['room-plan.js'],
        ]);
    }

    /** POST /{school}/admin/raeume/neu */
    public function store(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::RAEUME_ERSTELLEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $data = $this->readInput();
        $this->ctx->db->run(
            'INSERT INTO rooms (edition_id, room_number, room_name, building, floor, capacity, equipment)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $edition['id'],
                $data['room_number'],
                $data['room_name'],
                $data['building'],
                $data['floor'],
                $data['capacity'],
                $data['equipment'],
            ],
        );

        $this->ctx->audit->log(
            'Raum erstellt',
            'info',
            'Raum: ' . $data['room_number'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Der Raum wurde angelegt.');
        $this->redirect($this->ctx->schoolUrl('/admin/raeume'));
    }

    /** POST /{school}/admin/raeume/{id} */
    public function update(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::RAEUME_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $room = $this->findRoom((int) $params['id']);
        $data = $this->readInput();

        $this->ctx->db->run(
            'UPDATE rooms SET room_number = ?, room_name = ?, building = ?, floor = ?,
                    capacity = ?, equipment = ?
             WHERE id = ? AND edition_id = ?',
            [
                $data['room_number'],
                $data['room_name'],
                $data['building'],
                $data['floor'],
                $data['capacity'],
                $data['equipment'],
                (int) $room['id'],
                (int) $edition['id'],
            ],
        );

        $this->ctx->audit->log(
            'Raum bearbeitet',
            'info',
            'Raum: ' . $data['room_number'] . ' (ID ' . (int) $room['id'] . ')',
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Der Raum wurde aktualisiert.');
        $this->redirect($this->ctx->schoolUrl('/admin/raeume'));
    }

    /** POST /{school}/admin/raeume/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::RAEUME_LOESCHEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $room = $this->findRoom((int) $params['id']);
        $affected = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM exhibitors WHERE room_id = ? AND edition_id = ?',
            [(int) $room['id'], (int) $edition['id']],
        );

        $this->ctx->db->run(
            'DELETE FROM rooms WHERE id = ? AND edition_id = ?',
            [(int) $room['id'], (int) $edition['id']],
        );

        $this->ctx->audit->log(
            'Raum gelöscht',
            'warning',
            'Raum: ' . (string) $room['room_number'] . ' — ' . $affected . ' Aussteller ohne Raum',
            $this->ctx->schoolId(),
        );
        $this->flash('success', $affected > 0
            ? "Der Raum wurde gelöscht. {$affected} Aussteller haben jetzt keinen Raum mehr."
            : 'Der Raum wurde gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/admin/raeume'));
    }

    // ---------- Zuteilung ----------

    /** POST /{school}/admin/raeume/zuteilen */
    public function assign(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::RAEUME_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $exhibitor = $this->findExhibitor((int) ($_POST['exhibitor_id'] ?? 0));
        $roomId = (int) ($_POST['room_id'] ?? 0);
        $room = $roomId > 0 ? $this->findRoom($roomId) : null;
        $editionId = (int) $edition['id'];
        $back = $this->ctx->schoolUrl('/admin/raeume');

        $hinweise = [];
        if ($room !== null) {
            // Ein zweiter Aussteller im selben Raum verdoppelt faktisch dessen
            // Kapazität — und der Lehrer-Scanner kann die beiden im Raum nicht
            // auseinanderhalten, weil er nur über den Raum auflöst.
            $mitbewohner = $this->ctx->db->fetchAll(
                'SELECT name FROM exhibitors
                 WHERE room_id = ? AND edition_id = ? AND id <> ? AND active = 1
                 ORDER BY name',
                [(int) $room['id'], $editionId, (int) $exhibitor['id']],
            );
            if ($mitbewohner !== []) {
                $hinweise[] = sprintf(
                    'Achtung: In diesem Raum ist bereits %s eingetragen. Check-in-Scans lassen sich dann nicht mehr eindeutig zuordnen.',
                    implode(', ', array_column($mitbewohner, 'name')),
                );
            }

            // Passt die bereits erfolgte Zuteilung noch in den neuen Raum?
            $ueberbucht = $this->ctx->db->fetchOne(
                'SELECT r.timeslot_id, COUNT(*) AS belegt,
                        COALESCE(rsc.capacity, ro.capacity) AS kapazitaet
                 FROM registrations r
                 JOIN rooms ro ON ro.id = ?
                 LEFT JOIN room_slot_capacities rsc
                        ON rsc.room_id = ro.id AND rsc.timeslot_id = r.timeslot_id
                 WHERE r.exhibitor_id = ? AND r.edition_id = ? AND r.timeslot_id IS NOT NULL
                 GROUP BY r.timeslot_id, kapazitaet
                 HAVING kapazitaet IS NOT NULL AND belegt > kapazitaet
                 ORDER BY belegt DESC
                 LIMIT 1',
                [(int) $room['id'], (int) $exhibitor['id'], $editionId],
            );
            if ($ueberbucht !== null) {
                $hinweise[] = sprintf(
                    'Achtung: In mindestens einem Zeitslot sind bereits %d Personen zugeteilt, der Raum fasst aber nur %d. Bitte Zuteilung prüfen.',
                    (int) $ueberbucht['belegt'],
                    (int) $ueberbucht['kapazitaet'],
                );
            }
        }

        $this->ctx->db->run(
            'UPDATE exhibitors SET room_id = ? WHERE id = ? AND edition_id = ?',
            [$room === null ? null : (int) $room['id'], (int) $exhibitor['id'], $editionId],
        );

        $this->ctx->audit->log(
            'Raum zugeteilt',
            $hinweise === [] ? 'info' : 'warning',
            'Aussteller: ' . (string) $exhibitor['name']
                . ' → ' . ($room === null ? 'kein Raum' : 'Raum ' . (string) $room['room_number'])
                . ($hinweise === [] ? '' : ' — ' . implode(' ', $hinweise)),
            $this->ctx->schoolId(),
        );
        $this->flash('success', $room === null
            ? 'Die Zuteilung wurde gelöst.'
            : 'Der Aussteller wurde dem Raum zugewiesen.');
        foreach ($hinweise as $hinweis) {
            $this->flash('warning', $hinweis);
        }
        $this->redirect($back);
    }

    /** POST /{school}/admin/raeume/zuteilung-loesen */
    public function unassign(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::RAEUME_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $exhibitor = $this->findExhibitor((int) ($_POST['exhibitor_id'] ?? 0));
        $this->ctx->db->run(
            'UPDATE exhibitors SET room_id = NULL WHERE id = ? AND edition_id = ?',
            [(int) $exhibitor['id'], (int) $edition['id']],
        );

        $this->ctx->audit->log(
            'Raumzuteilung gelöst',
            'info',
            'Aussteller: ' . (string) $exhibitor['name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Zuteilung wurde gelöst.');
        $this->redirect($this->ctx->schoolUrl('/admin/raeume'));
    }

    /** POST /{school}/admin/raeume/zuteilungen-aufheben */
    public function clearAssignments(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::RAEUME_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $affected = $this->ctx->db->run(
            'UPDATE exhibitors SET room_id = NULL WHERE edition_id = ? AND room_id IS NOT NULL',
            [(int) $edition['id']],
        )->rowCount();

        $this->ctx->audit->log(
            'Alle Raumzuteilungen aufgehoben',
            'warning',
            $affected . ' Aussteller betroffen',
            $this->ctx->schoolId(),
        );
        $this->flash('success', "Alle Zuteilungen wurden aufgehoben ({$affected} Aussteller).");
        $this->redirect($this->ctx->schoolUrl('/admin/raeume'));
    }

    // ---------- Helfer ----------

    /** @return array<string, mixed> Raum der aktiven Edition (sonst 404). */
    private function findRoom(int $id): array
    {
        $room = $this->ctx->db->fetchOne(
            'SELECT * FROM rooms WHERE id = ? AND edition_id = ?',
            [$id, (int) $this->ctx->requireEdition()['id']],
        );
        if ($room === null) {
            throw new HttpException(404, 'Dieser Raum existiert nicht.');
        }

        return $room;
    }

    /** @return array<string, mixed> Aussteller der aktiven Edition (sonst 404). */
    private function findExhibitor(int $id): array
    {
        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT id, name FROM exhibitors WHERE id = ? AND edition_id = ?',
            [$id, (int) $this->ctx->requireEdition()['id']],
        );
        if ($exhibitor === null) {
            throw new HttpException(404, 'Dieser Aussteller existiert nicht.');
        }

        return $exhibitor;
    }

    /** @return array<string, mixed> Validierte Raum-Formulardaten. */
    private function readInput(): array
    {
        $number = trim((string) ($_POST['room_number'] ?? ''));
        if ($number === '' || mb_strlen($number) > 50) {
            $this->flash('error', 'Bitte gib eine Raumnummer mit maximal 50 Zeichen an.');
            $this->redirect($this->ctx->schoolUrl('/admin/raeume'));
        }

        return [
            'room_number' => $number,
            'room_name' => $this->nullable($_POST['room_name'] ?? '', 200),
            'building' => $this->nullable($_POST['building'] ?? '', 100),
            'floor' => $this->nullable($_POST['floor'] ?? '', 50),
            'capacity' => max(0, min(9999, (int) ($_POST['capacity'] ?? 30))),
            'equipment' => $this->nullable($_POST['equipment'] ?? '', 500),
        ];
    }

    private function nullable(mixed $value, int $maxLength): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : mb_substr($text, 0, $maxLength);
    }
}
