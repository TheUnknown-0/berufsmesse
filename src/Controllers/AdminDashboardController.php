<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions;
use App\Services\AutoAssign;
use App\Services\Capacity;

/**
 * Admin-Dashboard: Kennzahlen, Diagramme, Raumplan-Kurzübersicht sowie
 * die Steuerung der automatischen Zuteilung (Phase 1, Phase 2, Reset).
 */
final class AdminDashboardController extends Controller
{
    private const TOP_EXHIBITORS = 15;

    /** GET /{school}/admin/dashboard */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::DASHBOARD_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        $capacity = new Capacity($this->ctx->db);

        $slots = $this->ctx->db->fetchAll(
            'SELECT id, slot_number, slot_name, start_time, end_time, is_managed, is_break
             FROM timeslots WHERE edition_id = ? ORDER BY start_time, slot_number',
            [$editionId],
        );
        $managedSlots = array_values(array_filter(
            $slots,
            static fn (array $s): bool => (int) $s['is_managed'] === 1 && (int) $s['is_break'] === 0,
        ));

        // Raumplan-Kurzübersicht: Aussteller × fester Slot
        $exhibitors = $this->ctx->db->fetchAll(
            'SELECT e.id, e.name, e.room_id, r.room_number, r.room_name
             FROM exhibitors e
             LEFT JOIN rooms r ON r.id = e.room_id
             WHERE e.edition_id = ? AND e.active = 1
             ORDER BY e.name',
            [$editionId],
        );

        return $this->render('pages/admin-dashboard/index', [
            'title' => 'Dashboard',
            'edition' => $edition,
            'stats' => $this->stats($editionId),
            'chartData' => $this->chartData($editionId, $capacity),
            'slots' => $slots,
            'managedSlots' => $managedSlots,
            'exhibitors' => $exhibitors,
            'occupancy' => $capacity->overview($editionId),
            'canAssign' => $this->ctx->auth->can(Permissions::ZUTEILUNG_AUSFUEHREN, $this->ctx->schoolId()),
            'canReset' => $this->ctx->auth->can(Permissions::ZUTEILUNG_ZURUECKSETZEN, $this->ctx->schoolId()),
            'statsUrl' => $this->ctx->schoolUrl('/api/dashboard/stats'),
            'pageScripts' => ['vendor/chart.umd.min.js', 'admin-dashboard.js'],
        ]);
    }

    /** GET /{school}/api/dashboard/stats — Diagrammdaten als JSON. */
    public function apiStats(array $params): array
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::DASHBOARD_SEHEN);
        $edition = $this->ctx->requireEdition();
        $editionId = (int) $edition['id'];

        return [
            'success' => true,
            'stats' => $this->stats($editionId),
            'charts' => $this->chartData($editionId, new Capacity($this->ctx->db)),
        ];
    }

    // ---------- Zuteilung ----------

    /** POST /{school}/admin/zuteilung/ausfuehren — Phase 1. */
    public function runAssign(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ZUTEILUNG_AUSFUEHREN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $result = $this->autoAssign()->assignPending((int) $edition['id']);

        $details = sprintf(
            'Zugeteilt: %d, gelöscht (Überschuss): %d, ohne freien Slot: %d',
            $result['assigned'],
            $result['deleted'],
            $result['skipped'],
        );
        $this->ctx->audit->log('Automatische Zuteilung ausgeführt', 'info', $details, $this->ctx->schoolId());

        $this->flash('success', sprintf(
            '%d Anmeldungen wurden zugeteilt. %d überschüssige Anmeldungen entfernt, %d ohne freien Platz.',
            $result['assigned'],
            $result['deleted'],
            $result['skipped'],
        ));
        $this->redirect($this->ctx->schoolUrl('/admin/dashboard'));
    }

    /** POST /{school}/admin/zuteilung/auffuellen — Phase 2. */
    public function runFill(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ZUTEILUNG_AUSFUEHREN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $result = $this->autoAssign()->fillIncomplete((int) $edition['id'], (int) $this->ctx->schoolId());

        $details = sprintf(
            'Neue Zuteilungen: %d für %d Schüler:innen, ohne Aussteller: %d',
            $result['created'],
            $result['students'],
            $result['skipped'],
        );
        $this->ctx->audit->log('Unvollständige Zuteilungen aufgefüllt', 'info', $details, $this->ctx->schoolId());

        $this->flash('success', sprintf(
            '%d Zuteilungen für %d Schüler:innen ergänzt. %d Slots blieben ohne freien Aussteller.',
            $result['created'],
            $result['students'],
            $result['skipped'],
        ));
        $this->redirect($this->ctx->schoolUrl('/admin/dashboard'));
    }

    /**
     * POST /{school}/admin/zuteilung/simulation
     *
     * Probelauf der Zuteilung: rechnet das Ergebnis vollständig durch und
     * verwirft es wieder (siehe AutoAssign::simulate). Zeigt, wie gut die
     * Verteilung ausfiele, BEVOR sie scharf geschaltet wird.
     */
    public function simulateAssign(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ZUTEILUNG_AUSFUEHREN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $withFill = isset($_POST['auffuellen']);
        $result = $this->autoAssign()->simulate(
            (int) $edition['id'],
            (int) $this->ctx->schoolId(),
            $withFill,
        );

        // Kein Redirect: Das Ergebnis wird direkt gerendert, damit ein
        // versehentliches Neuladen keinen zweiten Probelauf auslöst.
        return $this->render('pages/admin-dashboard/simulation', [
            'title' => 'Probelauf der Zuteilung',
            'result' => $result,
            'withFill' => $withFill,
        ]);
    }

    /** POST /{school}/admin/zuteilung/zuruecksetzen */
    public function resetAssign(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ZUTEILUNG_ZURUECKSETZEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $result = $this->autoAssign()->reset((int) $edition['id']);

        $this->ctx->audit->log(
            'Zuteilung zurückgesetzt',
            'warning',
            sprintf('Slots geleert: %d, automatische Anmeldungen gelöscht: %d', $result['cleared'], $result['removed']),
            $this->ctx->schoolId(),
        );
        $this->flash('success', sprintf(
            'Zuteilung zurückgesetzt: %d Slots geleert, %d automatische Anmeldungen gelöscht.',
            $result['cleared'],
            $result['removed'],
        ));
        $this->redirect($this->ctx->schoolUrl('/admin/dashboard'));
    }

    // ---------- Datenbeschaffung ----------

    private function autoAssign(): AutoAssign
    {
        return new AutoAssign($this->ctx->db, new Capacity($this->ctx->db));
    }

    /**
     * Kennzahlen für die Stat-Kacheln.
     *
     * @return array<string, int>
     */
    private function stats(int $editionId): array
    {
        $schoolId = (int) $this->ctx->schoolId();

        $managedCount = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM timeslots WHERE edition_id = ? AND is_managed = 1 AND is_break = 0',
            [$editionId],
        );

        $studentsTotal = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM users WHERE role = \'student\' AND school_id = ? AND edition_id = ?',
            [$schoolId, $editionId],
        );

        $studentsRegistered = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(DISTINCT r.user_id)
             FROM registrations r
             JOIN users u ON u.id = r.user_id
             WHERE r.edition_id = ? AND u.role = \'student\' AND u.school_id = ? AND u.edition_id = ?',
            [$editionId, $schoolId, $editionId],
        );

        $studentsComplete = 0;
        if ($managedCount > 0) {
            $studentsComplete = (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM (
                     SELECT r.user_id
                     FROM registrations r
                     JOIN timeslots t ON t.id = r.timeslot_id AND t.is_managed = 1 AND t.is_break = 0
                     JOIN users u ON u.id = r.user_id
                     WHERE r.edition_id = ? AND u.role = \'student\'
                       AND u.school_id = ? AND u.edition_id = ?
                     GROUP BY r.user_id
                     HAVING COUNT(DISTINCT r.timeslot_id) >= ?
                 ) AS vollstaendig',
                [$editionId, $schoolId, $editionId, $managedCount],
            );
        }

        return [
            'students_total' => $studentsTotal,
            'students_registered' => $studentsRegistered,
            'students_complete' => $studentsComplete,
            'students_without' => max(0, $studentsTotal - $studentsRegistered),
            'exhibitors_active' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM exhibitors WHERE edition_id = ? AND active = 1',
                [$editionId],
            ),
            'rooms' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM rooms WHERE edition_id = ?',
                [$editionId],
            ),
            'registrations_total' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM registrations WHERE edition_id = ?',
                [$editionId],
            ),
            'registrations_pending' => (int) $this->ctx->db->fetchValue(
                'SELECT COUNT(*) FROM registrations WHERE edition_id = ? AND timeslot_id IS NULL',
                [$editionId],
            ),
            'managed_slots' => $managedCount,
        ];
    }

    /**
     * Daten für die beiden Diagramme (Chart.js).
     *
     * @return array{exhibitors: array{labels: list<string>, values: list<int>},
     *               slots: array{labels: list<string>, used: list<int>, capacity: list<int>}}
     */
    private function chartData(int $editionId, Capacity $capacity): array
    {
        $topRows = $this->ctx->db->fetchAll(
            'SELECT e.name, COUNT(r.id) AS anzahl
             FROM exhibitors e
             LEFT JOIN registrations r ON r.exhibitor_id = e.id AND r.edition_id = ?
             WHERE e.edition_id = ? AND e.active = 1
             GROUP BY e.id, e.name
             ORDER BY anzahl DESC, e.name
             LIMIT ' . self::TOP_EXHIBITORS,
            [$editionId, $editionId],
        );

        $slotRows = $this->ctx->db->fetchAll(
            'SELECT t.id, t.slot_number, t.slot_name, t.start_time, t.end_time,
                    COUNT(r.id) AS belegt
             FROM timeslots t
             LEFT JOIN registrations r ON r.timeslot_id = t.id AND r.edition_id = ?
             WHERE t.edition_id = ? AND t.is_break = 0
             GROUP BY t.id, t.slot_number, t.slot_name, t.start_time, t.end_time
             ORDER BY t.start_time, t.slot_number',
            [$editionId, $editionId],
        );
        $totals = $capacity->slotTotals($editionId);

        $slotLabels = [];
        $slotUsed = [];
        $slotCapacity = [];
        foreach ($slotRows as $row) {
            $name = (string) ($row['slot_name'] ?? '');
            $slotLabels[] = ($name !== '' ? $name : 'Slot ' . (int) $row['slot_number'])
                . ' (' . substr((string) $row['start_time'], 0, 5) . ')';
            $slotUsed[] = (int) $row['belegt'];
            $slotCapacity[] = $totals[(int) $row['id']]['capacity'] ?? 0;
        }

        return [
            'exhibitors' => [
                'labels' => array_map(static fn (array $r): string => (string) $r['name'], $topRows),
                'values' => array_map(static fn (array $r): int => (int) $r['anzahl'], $topRows),
            ],
            'slots' => [
                'labels' => $slotLabels,
                'used' => $slotUsed,
                'capacity' => $slotCapacity,
            ],
        ];
    }
}
