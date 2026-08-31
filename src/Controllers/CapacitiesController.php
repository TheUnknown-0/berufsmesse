<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions;

/**
 * Slot-Kapazitäten: Matrix aus Räumen (Zeilen) und verwalteten Zeitslots
 * (Spalten, is_managed = 1).
 *
 * Ein leeres Feld bedeutet „kein eigener Wert" — dann gilt rooms.capacity.
 * Gespeichert wird als Upsert; geleerte Felder löschen den Eintrag.
 */
final class CapacitiesController extends Controller
{
    /** GET /{school}/admin/kapazitaeten */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::KAPAZITAETEN_SEHEN);
        $edition = $this->ctx->requireEdition();

        [$rooms, $slots, $capacities] = $this->matrix((int) $edition['id']);

        return $this->render('pages/capacities/index', [
            'title' => 'Slot-Kapazitäten',
            'rooms' => $rooms,
            'slots' => $slots,
            'capacities' => $capacities,
            'canEdit' => $this->ctx->auth->can(Permissions::KAPAZITAETEN_BEARBEITEN, $this->ctx->schoolId()),
        ]);
    }

    /** POST /{school}/admin/kapazitaeten */
    public function save(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::KAPAZITAETEN_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        [$rooms, $slots, $capacities] = $this->matrix((int) $edition['id']);
        $input = is_array($_POST['capacity'] ?? null) ? $_POST['capacity'] : [];

        $saved = 0;
        $removed = 0;

        // Es wird ausschließlich über Räume/Slots der aktiven Edition iteriert —
        // IDs aus dem Request werden nie ungeprüft übernommen.
        $this->ctx->db->transaction(function () use ($rooms, $slots, $capacities, $input, &$saved, &$removed): void {
            foreach ($rooms as $room) {
                $roomId = (int) $room['id'];
                foreach ($slots as $slot) {
                    $slotId = (int) $slot['id'];
                    $raw = $input[$roomId][$slotId] ?? null;
                    $raw = is_scalar($raw) ? trim((string) $raw) : '';
                    $existing = $capacities[$roomId][$slotId] ?? null;

                    if ($raw === '') {
                        if ($existing !== null) {
                            $this->ctx->db->run(
                                'DELETE FROM room_slot_capacities WHERE room_id = ? AND timeslot_id = ?',
                                [$roomId, $slotId],
                            );
                            $removed++;
                        }
                        continue;
                    }

                    $value = max(0, min(9999, (int) $raw));
                    if ($existing !== null && $existing === $value) {
                        continue;
                    }
                    $this->ctx->db->run(
                        'INSERT INTO room_slot_capacities (room_id, timeslot_id, capacity)
                         VALUES (?, ?, ?)
                         ON DUPLICATE KEY UPDATE capacity = VALUES(capacity)',
                        [$roomId, $slotId, $value],
                    );
                    $saved++;
                }
            }
        });

        $this->ctx->audit->log(
            'Slot-Kapazitäten gespeichert',
            'info',
            $saved . ' Werte gesetzt, ' . $removed . ' Werte auf Standard zurückgesetzt',
            $this->ctx->schoolId(),
        );
        $this->flash('success', "Kapazitäten gespeichert ({$saved} gesetzt, {$removed} zurückgesetzt).");
        $this->redirect($this->ctx->schoolUrl('/admin/kapazitaeten'));
    }

    // ---------- Helfer ----------

    /**
     * Lädt Räume, verwaltete Slots und die vorhandenen Kapazitäten der Edition.
     *
     * @return array{0: list<array<string, mixed>>, 1: list<array<string, mixed>>, 2: array<int, array<int, int>>}
     */
    private function matrix(int $editionId): array
    {
        $rooms = $this->ctx->db->fetchAll(
            'SELECT id, room_number, room_name, building, floor, capacity
             FROM rooms WHERE edition_id = ? ORDER BY room_number ASC',
            [$editionId],
        );

        $slots = $this->ctx->db->fetchAll(
            'SELECT id, slot_number, slot_name, start_time, end_time
             FROM timeslots
             WHERE edition_id = ? AND is_managed = 1
             ORDER BY slot_number ASC',
            [$editionId],
        );

        $rows = $this->ctx->db->fetchAll(
            'SELECT rsc.room_id, rsc.timeslot_id, rsc.capacity
             FROM room_slot_capacities rsc
             JOIN rooms r ON r.id = rsc.room_id
             JOIN timeslots t ON t.id = rsc.timeslot_id
             WHERE r.edition_id = ? AND t.edition_id = ?',
            [$editionId, $editionId],
        );

        $capacities = [];
        foreach ($rows as $row) {
            $capacities[(int) $row['room_id']][(int) $row['timeslot_id']] = (int) $row['capacity'];
        }

        return [$rooms, $slots, $capacities];
    }
}
