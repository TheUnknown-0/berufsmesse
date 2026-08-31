<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\Capacity;

/**
 * Visuelle Raumplanung: Aussteller per Drag & Drop auf Räume verteilen,
 * gruppiert nach Gebäude und Etage, mit Kapazitätswarnung.
 *
 * Die Zuteilung selbst ist dieselbe wie in RoomsController::assign
 * (`exhibitors.room_id`) — hier nur über einen JSON-Endpunkt, damit das
 * Verschieben ohne Seitenwechsel funktioniert.
 */
final class RoomPlanController extends Controller
{
    /** GET /{school}/admin/raumplan */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::RAEUME_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $slotCount = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM timeslots WHERE edition_id = ? AND is_managed = 1 AND is_break = 0',
            [$editionId],
        );

        $rooms = $this->ctx->db->fetchAll(
            'SELECT * FROM rooms WHERE edition_id = ? ORDER BY building, floor, room_number',
            [$editionId],
        );

        // Nachfrage je Aussteller: wie viele Wünsche liegen vor?
        $exhibitors = $this->ctx->db->fetchAll(
            'SELECT e.id, e.name, e.room_id, e.total_slots, e.active, e.pipeline_status,
                    (SELECT COUNT(*) FROM registrations r WHERE r.exhibitor_id = e.id) AS demand
             FROM exhibitors e
             WHERE e.edition_id = ?
             ORDER BY e.name',
            [$editionId],
        );

        $unassigned = [];
        $byRoom = [];
        foreach ($exhibitors as $exhibitor) {
            $roomId = $exhibitor['room_id'] !== null ? (int) $exhibitor['room_id'] : null;
            if ($roomId === null) {
                $unassigned[] = $exhibitor;
                continue;
            }
            $byRoom[$roomId][] = $exhibitor;
        }

        // Gruppierung nach Gebäude → Etage, damit die Wege stimmen
        $grouped = [];
        foreach ($rooms as $room) {
            $roomId = (int) $room['id'];
            $room['exhibitors'] = $byRoom[$roomId] ?? [];
            $room['seats'] = (int) $room['capacity'] * $slotCount;
            $room['demand'] = array_sum(array_map(
                static fn (array $e): int => (int) $e['demand'],
                $room['exhibitors'],
            ));

            $building = trim((string) ($room['building'] ?? '')) ?: 'Ohne Gebäude';
            $floor = trim((string) ($room['floor'] ?? '')) ?: 'Ohne Etage';
            $grouped[$building][$floor][] = $room;
        }

        return $this->render('pages/rooms/plan', [
            'title' => 'Raumplanung',
            'grouped' => $grouped,
            'unassigned' => $unassigned,
            'roomCount' => count($rooms),
            'slotCount' => $slotCount,
            'assignedCount' => count($exhibitors) - count($unassigned),
            'canEdit' => $this->ctx->auth->can(Permissions::RAEUME_BEARBEITEN, $this->ctx->schoolId()),
            'pageScripts' => ['room-plan.js'],
        ]);
    }

    /**
     * POST /{school}/api/raumplan/zuteilen — Aussteller in einen Raum
     * schieben (room_id = 0 löst die Zuteilung).
     */
    public function apiAssign(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::RAEUME_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $payload = $this->jsonBody();
        $exhibitorId = (int) ($payload['exhibitor_id'] ?? 0);
        $roomId = (int) ($payload['room_id'] ?? 0);

        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT id, name FROM exhibitors WHERE id = ? AND edition_id = ?',
            [$exhibitorId, $editionId],
        );
        if ($exhibitor === null) {
            return $this->jsonError('Diesen Aussteller gibt es hier nicht.', 404);
        }

        $room = null;
        if ($roomId > 0) {
            $room = $this->ctx->db->fetchOne(
                'SELECT id, room_number, room_name, capacity FROM rooms WHERE id = ? AND edition_id = ?',
                [$roomId, $editionId],
            );
            if ($room === null) {
                return $this->jsonError('Diesen Raum gibt es hier nicht.', 404);
            }
        }

        $this->ctx->db->run(
            'UPDATE exhibitors SET room_id = ? WHERE id = ? AND edition_id = ?',
            [$room === null ? null : (int) $room['id'], $exhibitorId, $editionId],
        );
        // Kapazitäten hängen am Raum — Cache verwerfen.
        (new Capacity($this->ctx->db))->refresh($editionId);

        $this->ctx->audit->log(
            'Raum zugeteilt',
            'info',
            sprintf(
                '%s → %s (Raumplanung)',
                (string) $exhibitor['name'],
                $room === null ? 'kein Raum' : 'Raum ' . (string) $room['room_number'],
            ),
            $this->ctx->schoolId(),
        );

        // Belegung des Zielraums für die Warnung im UI zurückgeben
        $occupants = $room === null ? 0 : (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM exhibitors WHERE room_id = ? AND edition_id = ?',
            [(int) $room['id'], $editionId],
        );

        return [
            'success' => true,
            'exhibitor' => ['id' => $exhibitorId, 'name' => (string) $exhibitor['name']],
            'room_id' => $room === null ? 0 : (int) $room['id'],
            'occupants' => $occupants,
        ];
    }

    /**
     * JSON-Rumpf der Anfrage.
     *
     * @return array<string, mixed>
     */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new HttpException(400, 'Ungültige Anfrage.');
        }

        return $decoded;
    }
}
