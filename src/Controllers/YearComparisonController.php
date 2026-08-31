<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Permissions;
use App\Services\Exports;

/**
 * Jahresvergleich: Kennzahlen aller Messe-Editionen einer Schule
 * nebeneinander — Teilnahme, Aussteller-Treue, Auslastung, Feedback.
 *
 * Anders als das Dashboard, das die laufende Messe zeigt, blickt diese
 * Seite bewusst über alle Jahrgänge: Material für Schulleitung, Sponsoren
 * und die Planung des nächsten Durchgangs.
 */
final class YearComparisonController extends Controller
{
    /** GET /{school}/admin/jahresvergleich */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BERICHTE_SEHEN);
        $schoolId = (int) $this->ctx->schoolId();

        $editions = $this->editionStats($schoolId);

        return $this->render('pages/admin-dashboard/jahresvergleich', [
            'title' => 'Jahresvergleich',
            'editions' => $editions,
            'loyalty' => $this->exhibitorLoyalty($schoolId),
            'canExport' => $this->ctx->auth->can(Permissions::BERICHTE_DRUCKEN, $schoolId),
        ]);
    }

    /** GET /{school}/admin/jahresvergleich/export */
    public function export(array $params): never
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::BERICHTE_DRUCKEN);
        $schoolId = (int) $this->ctx->schoolId();

        $header = [
            'Jahr', 'Messe', 'Status', 'Messetag', 'Schüler:innen', 'Aussteller',
            'Anmeldungen', 'zugeteilt', 'Check-ins', 'Anwesenheitsquote %',
            'Feedback-Rückmeldungen',
        ];
        $rows = [];
        foreach ($this->editionStats($schoolId) as $edition) {
            $rows[] = [
                (string) $edition['year'],
                (string) $edition['name'],
                (string) $edition['status'],
                $edition['event_date'] !== null ? format_date($edition['event_date']) : '',
                (string) $edition['students'],
                (string) $edition['exhibitors'],
                (string) $edition['registrations'],
                (string) $edition['assigned'],
                (string) $edition['attendances'],
                (string) $edition['quote'],
                (string) $edition['feedback'],
            ];
        }

        Exports::deliver(
            (string) ($_GET['format'] ?? 'csv'),
            $header,
            $rows,
            'jahresvergleich',
        );
    }

    /**
     * Kennzahlen je Edition, älteste zuerst (damit Verläufe von links nach
     * rechts lesbar sind).
     *
     * @return list<array<string, mixed>>
     */
    private function editionStats(int $schoolId): array
    {
        $rows = $this->ctx->db->fetchAll(
            "SELECT me.id, me.name, me.year, me.status, me.event_date,
                    (SELECT COUNT(*) FROM users u
                      WHERE u.edition_id = me.id AND u.role = 'student') AS students,
                    (SELECT COUNT(*) FROM exhibitors e
                      WHERE e.edition_id = me.id AND e.active = 1) AS exhibitors,
                    (SELECT COUNT(*) FROM registrations r WHERE r.edition_id = me.id) AS registrations,
                    (SELECT COUNT(*) FROM registrations r
                      WHERE r.edition_id = me.id AND r.timeslot_id IS NOT NULL) AS assigned,
                    (SELECT COUNT(*) FROM attendance a WHERE a.edition_id = me.id) AS attendances,
                    (SELECT COUNT(*) FROM feedback_responses fr
                      JOIN feedback_forms ff ON ff.id = fr.form_id
                      WHERE ff.edition_id = me.id) AS feedback
             FROM messe_editions me
             WHERE me.school_id = ?
             ORDER BY me.year, me.id",
            [$schoolId],
        );

        $result = [];
        foreach ($rows as $row) {
            $assigned = (int) $row['assigned'];
            $row['students'] = (int) $row['students'];
            $row['exhibitors'] = (int) $row['exhibitors'];
            $row['registrations'] = (int) $row['registrations'];
            $row['assigned'] = $assigned;
            $row['attendances'] = (int) $row['attendances'];
            $row['feedback'] = (int) $row['feedback'];
            $row['quote'] = $assigned > 0 ? (int) round((int) $row['attendances'] / $assigned * 100) : 0;
            $result[] = $row;
        }

        return $result;
    }

    /**
     * Aussteller-Treue: welche Unternehmen sind wie oft dabei gewesen.
     *
     * Grundlage ist die Kette `previous_exhibitor_id`, die beim Klonen einer
     * Edition gesetzt wird. Gezählt wird je Kette, wie viele Jahrgänge sie
     * umfasst — der jüngste Eintrag steht dabei für das Unternehmen.
     *
     * @return array{returning: list<array<string, mixed>>, new_count: int, chains: int}
     */
    private function exhibitorLoyalty(int $schoolId): array
    {
        $all = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT e.id, e.name, e.previous_exhibitor_id, me.year
             FROM exhibitors e
             JOIN messe_editions me ON me.id = e.edition_id
             WHERE me.school_id = ?
             ORDER BY me.year, e.id',
            [$schoolId],
        ) as $row) {
            $all[(int) $row['id']] = $row;
        }

        // Ein Eintrag ist das Ende einer Kette, wenn kein anderer auf ihn zeigt.
        $referenced = [];
        foreach ($all as $row) {
            if ($row['previous_exhibitor_id'] !== null) {
                $referenced[(int) $row['previous_exhibitor_id']] = true;
            }
        }

        $chains = [];
        foreach ($all as $id => $row) {
            if (isset($referenced[$id])) {
                continue; // nicht das jüngste Glied
            }

            $years = [];
            $currentId = $id;
            $seen = [];
            while ($currentId !== null && isset($all[$currentId]) && !isset($seen[$currentId])) {
                $seen[$currentId] = true;
                $years[] = (int) $all[$currentId]['year'];
                $previous = $all[$currentId]['previous_exhibitor_id'];
                $currentId = $previous !== null ? (int) $previous : null;
            }

            $chains[] = [
                'name' => (string) $row['name'],
                'count' => count($years),
                'years' => array_reverse($years),
            ];
        }

        usort($chains, static function (array $a, array $b): int {
            return $b['count'] <=> $a['count'] ?: strcmp($a['name'], $b['name']);
        });

        $returning = array_values(array_filter($chains, static fn (array $c): bool => $c['count'] > 1));

        return [
            'returning' => array_slice($returning, 0, 20),
            'new_count' => count($chains) - count($returning),
            'chains' => count($chains),
        ];
    }
}
