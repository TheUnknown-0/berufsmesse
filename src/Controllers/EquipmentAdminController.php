<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;

/**
 * Ausstattung: Stammdaten der wählbaren Optionen (schulgebunden) sowie die
 * Anfragen der Aussteller in der aktiven Edition.
 */
final class EquipmentAdminController extends Controller
{
    private const STATUSES = ['pending', 'approved', 'denied'];

    /** GET /{school}/admin/ausstattung */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTATTUNG_SEHEN);
        $edition = $this->ctx->requireEdition();

        $options = $this->ctx->db->fetchAll(
            'SELECT * FROM equipment_options WHERE school_id = ? ORDER BY sort_order ASC, name ASC',
            [(int) $this->ctx->schoolId()],
        );

        $status = (string) ($_GET['status'] ?? 'alle');
        if (!in_array($status, ['alle', ...self::STATUSES], true)) {
            $status = 'alle';
        }

        $where = ['req.edition_id = ?'];
        $args = [(int) $edition['id']];
        if ($status !== 'alle') {
            $where[] = 'req.status = ?';
            $args[] = $status;
        }

        $requests = $this->ctx->db->fetchAll(
            'SELECT req.*, e.name AS exhibitor_name, o.name AS option_name,
                    u.firstname, u.lastname, u.username
             FROM exhibitor_equipment_requests req
             JOIN exhibitors e ON e.id = req.exhibitor_id
             LEFT JOIN equipment_options o ON o.id = req.equipment_option_id
             LEFT JOIN users u ON u.id = req.requested_by
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY FIELD(req.status, \'pending\', \'approved\', \'denied\'), req.created_at DESC',
            $args,
        );

        $counts = $this->ctx->db->fetchOne(
            'SELECT COUNT(*) AS gesamt,
                    SUM(status = \'pending\') AS offen,
                    SUM(status = \'approved\') AS genehmigt,
                    SUM(status = \'denied\') AS abgelehnt
             FROM exhibitor_equipment_requests WHERE edition_id = ?',
            [(int) $edition['id']],
        ) ?? ['gesamt' => 0, 'offen' => 0, 'genehmigt' => 0, 'abgelehnt' => 0];

        return $this->render('pages/equipment-admin/index', [
            'title' => 'Ausstattung',
            'options' => $options,
            'requests' => $requests,
            'counts' => $counts,
            'status' => $status,
            'canManage' => $this->ctx->auth->can(Permissions::AUSSTATTUNG_VERWALTEN, $this->ctx->schoolId()),
        ]);
    }

    // ---------- Optionen ----------

    /** POST /{school}/admin/ausstattung/optionen/neu */
    public function storeOption(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTATTUNG_VERWALTEN);
        $this->requireCsrf();

        $data = $this->readOptionInput();
        $this->ctx->db->run(
            'INSERT INTO equipment_options (school_id, name, description, sort_order, is_active)
             VALUES (?, ?, ?, ?, ?)',
            [
                (int) $this->ctx->schoolId(),
                $data['name'],
                $data['description'],
                $data['sort_order'],
                $data['is_active'],
            ],
        );

        $this->ctx->audit->log(
            'Ausstattungsoption erstellt',
            'info',
            'Option: ' . $data['name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Ausstattungsoption wurde angelegt.');
        $this->redirect($this->ctx->schoolUrl('/admin/ausstattung'));
    }

    /** POST /{school}/admin/ausstattung/optionen/{id} */
    public function updateOption(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTATTUNG_VERWALTEN);
        $this->requireCsrf();

        $option = $this->findOption((int) $params['id']);
        $data = $this->readOptionInput();

        $this->ctx->db->run(
            'UPDATE equipment_options SET name = ?, description = ?, sort_order = ?, is_active = ?
             WHERE id = ? AND school_id = ?',
            [
                $data['name'],
                $data['description'],
                $data['sort_order'],
                $data['is_active'],
                (int) $option['id'],
                (int) $this->ctx->schoolId(),
            ],
        );

        $this->ctx->audit->log(
            'Ausstattungsoption bearbeitet',
            'info',
            'Option: ' . $data['name'] . ' (ID ' . (int) $option['id'] . ')',
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Ausstattungsoption wurde aktualisiert.');
        $this->redirect($this->ctx->schoolUrl('/admin/ausstattung'));
    }

    /** POST /{school}/admin/ausstattung/optionen/{id}/loeschen */
    public function destroyOption(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTATTUNG_VERWALTEN);
        $this->requireCsrf();

        $option = $this->findOption((int) $params['id']);
        $this->ctx->db->run(
            'DELETE FROM equipment_options WHERE id = ? AND school_id = ?',
            [(int) $option['id'], (int) $this->ctx->schoolId()],
        );

        $this->ctx->audit->log(
            'Ausstattungsoption gelöscht',
            'warning',
            'Option: ' . (string) $option['name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Ausstattungsoption wurde gelöscht. Bestehende Anfragen bleiben als Freitext erhalten.');
        $this->redirect($this->ctx->schoolUrl('/admin/ausstattung'));
    }

    // ---------- Anfragen ----------

    /** POST /{school}/admin/ausstattung/anfragen/{id} */
    public function updateRequest(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTATTUNG_VERWALTEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $request = $this->ctx->db->fetchOne(
            'SELECT req.id, e.name AS exhibitor_name
             FROM exhibitor_equipment_requests req
             JOIN exhibitors e ON e.id = req.exhibitor_id
             WHERE req.id = ? AND req.edition_id = ?',
            [(int) $params['id'], (int) $edition['id']],
        );
        if ($request === null) {
            throw new HttpException(404, 'Diese Anfrage existiert nicht.');
        }

        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            $this->flash('error', 'Ungültiger Status.');
            $this->redirect($this->ctx->schoolUrl('/admin/ausstattung'));
        }
        $notes = trim((string) ($_POST['admin_notes'] ?? ''));
        $notes = $notes === '' ? null : mb_substr($notes, 0, 500);

        $this->ctx->db->run(
            'UPDATE exhibitor_equipment_requests SET status = ?, admin_notes = ?
             WHERE id = ? AND edition_id = ?',
            [$status, $notes, (int) $request['id'], (int) $edition['id']],
        );

        $this->ctx->audit->log(
            'Ausstattungsanfrage bearbeitet',
            'info',
            'Aussteller: ' . (string) $request['exhibitor_name'] . ' — Status: ' . $status,
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Anfrage wurde aktualisiert.');
        $this->redirect($this->ctx->schoolUrl('/admin/ausstattung?status=' . urlencode((string) ($_POST['back_status'] ?? 'alle'))));
    }

    // ---------- Helfer ----------

    /** @return array<string, mixed> Option der aktuellen Schule (sonst 404). */
    private function findOption(int $id): array
    {
        $option = $this->ctx->db->fetchOne(
            'SELECT * FROM equipment_options WHERE id = ? AND school_id = ?',
            [$id, (int) $this->ctx->schoolId()],
        );
        if ($option === null) {
            throw new HttpException(404, 'Diese Ausstattungsoption existiert nicht.');
        }

        return $option;
    }

    /** @return array<string, mixed> Validierte Optionsdaten. */
    private function readOptionInput(): array
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            $this->flash('error', 'Bitte gib einen Namen mit maximal 150 Zeichen an.');
            $this->redirect($this->ctx->schoolUrl('/admin/ausstattung'));
        }
        $description = trim((string) ($_POST['description'] ?? ''));

        return [
            'name' => $name,
            'description' => $description === '' ? null : mb_substr($description, 0, 500),
            'sort_order' => max(0, min(65535, (int) ($_POST['sort_order'] ?? 0))),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
    }
}
