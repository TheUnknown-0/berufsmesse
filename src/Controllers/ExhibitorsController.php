<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;

/**
 * Aussteller-Übersicht für alle angemeldeten Rollen (Schüler:innen, Lehrkräfte,
 * Orga …). Bewusst OHNE Admin-Berechtigung — es werden ausschließlich aktive
 * Aussteller der aktiven Edition und nur freigegebene Profilfelder angezeigt.
 *
 * Filter & Suche laufen serverseitig (funktioniert auch ohne JavaScript).
 */
final class ExhibitorsController extends Controller
{
    /** GET /{school}/aussteller */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $edition = $this->ctx->requireEdition();

        $search = trim((string) ($_GET['q'] ?? ''));
        $branche = trim((string) ($_GET['branche'] ?? ''));

        $where = ['e.edition_id = ?', 'e.active = 1'];
        $args = [(int) $edition['id']];
        if ($search !== '') {
            $where[] = '(e.name LIKE ? OR e.short_description LIKE ? OR e.description LIKE ?)';
            $like = '%' . $search . '%';
            $args[] = $like;
            $args[] = $like;
            $args[] = $like;
        }

        $rows = $this->ctx->db->fetchAll(
            'SELECT e.id, e.name, e.short_description, e.categories, e.logo, e.offer_types,
                    r.room_number, r.room_name, r.building, r.floor
             FROM exhibitors e
             LEFT JOIN rooms r ON r.id = e.room_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY e.name ASC',
            $args,
        );

        // Branchen: Stammdaten + tatsächlich verwendete Namen (auch gelöschte)
        $industryRows = $this->ctx->db->fetchAll(
            'SELECT name FROM industries ORDER BY sort_order ASC, name ASC',
        );
        $branchen = array_map(static fn (array $row): string => (string) $row['name'], $industryRows);

        $exhibitors = [];
        foreach ($rows as $row) {
            $row['category_list'] = ExhibitorsAdminController::decodeList($row['categories']);
            $row['offer_list'] = ExhibitorsAdminController::decodeOffers($row['offer_types']);
            foreach ($row['category_list'] as $name) {
                if (!in_array($name, $branchen, true)) {
                    $branchen[] = $name;
                }
            }
            if ($branche !== '' && !in_array($branche, $row['category_list'], true)) {
                continue;
            }
            $exhibitors[] = $row;
        }

        return $this->render('pages/exhibitors/index', [
            'title' => 'Aussteller',
            'exhibitors' => $exhibitors,
            'branchen' => $branchen,
            'branche' => $branche,
            'search' => $search,
        ]);
    }

    /** GET /{school}/aussteller/{id} */
    public function show(array $params): string
    {
        $this->requireSchool($params['school']);
        $edition = $this->ctx->requireEdition();

        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT e.*, r.room_number, r.room_name, r.building, r.floor
             FROM exhibitors e
             LEFT JOIN rooms r ON r.id = e.room_id
             WHERE e.id = ? AND e.edition_id = ? AND e.active = 1',
            [(int) $params['id'], (int) $edition['id']],
        );
        if ($exhibitor === null) {
            throw new HttpException(404, 'Dieser Aussteller ist nicht (mehr) verfügbar.');
        }

        $documents = $this->ctx->db->fetchAll(
            'SELECT id, original_name, file_type, file_size
             FROM exhibitor_documents
             WHERE exhibitor_id = ? AND visible_for_students = 1
             ORDER BY uploaded_at DESC',
            [(int) $exhibitor['id']],
        );

        return $this->render('pages/exhibitors/show', [
            'title' => (string) $exhibitor['name'],
            'exhibitor' => $exhibitor,
            'categoryList' => ExhibitorsAdminController::decodeList($exhibitor['categories']),
            'offerList' => ExhibitorsAdminController::decodeOffers($exhibitor['offer_types']),
            'visibleFields' => ExhibitorsAdminController::decodeVisibleFields($exhibitor['visible_fields']),
            'fieldLabels' => ExhibitorsAdminController::VISIBLE_FIELDS,
            'documents' => $documents,
        ]);
    }
}
