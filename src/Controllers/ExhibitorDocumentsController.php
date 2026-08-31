<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\Uploads;

/**
 * Dokumente je Aussteller: Upload, Sichtbarkeit, Löschen und der
 * permissions-geprüfte Download-Endpunkt.
 *
 * Eigentum wird immer über die Kette Dokument → Aussteller → Edition → Schule
 * geprüft; Dateien liegen außerhalb des Webroots und werden ausschließlich
 * über stream() ausgeliefert.
 */
final class ExhibitorDocumentsController extends Controller
{
    /** Zugelassene Dateiendungen für Aussteller-Dokumente. */
    private const DOCUMENT_EXTENSIONS = [
        'pdf', 'doc', 'docx', 'ppt', 'pptx', 'jpg', 'jpeg', 'png', 'gif', 'webp',
    ];

    /** POST /{school}/admin/aussteller/{id}/dokumente */
    public function upload(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_DOKUMENTE_VERWALTEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT id, name FROM exhibitors WHERE id = ? AND edition_id = ?',
            [(int) $params['id'], (int) $edition['id']],
        );
        if ($exhibitor === null) {
            throw new HttpException(404, 'Dieser Aussteller existiert nicht.');
        }
        $back = $this->ctx->schoolUrl('/admin/aussteller/' . (int) $exhibitor['id']);

        $file = $_FILES['document'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            $this->flash('error', 'Bitte wähle eine Datei aus.');
            $this->redirect($back);
        }

        $uploads = new Uploads((string) $this->ctx->config['uploads']['dir']);
        try {
            $stored = $uploads->store(
                $file,
                'documents',
                self::DOCUMENT_EXTENSIONS,
                (int) ($this->ctx->config['uploads']['max_document_bytes'] ?? 10485760),
            );
        } catch (HttpException $e) {
            $this->flash('error', $e->getMessage());
            $this->redirect($back);
        }

        $extension = strtolower(pathinfo($stored['original_name'], PATHINFO_EXTENSION));
        $this->ctx->db->run(
            'INSERT INTO exhibitor_documents
                (exhibitor_id, filename, original_name, file_type, file_size, visible_for_students)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                (int) $exhibitor['id'],
                $stored['filename'],
                mb_substr($stored['original_name'], 0, 255),
                $extension !== '' ? $extension : null,
                $stored['size'],
                isset($_POST['visible_for_students']) ? 1 : 0,
            ],
        );

        $this->ctx->audit->log(
            'Aussteller-Dokument hochgeladen',
            'info',
            'Aussteller: ' . (string) $exhibitor['name'] . ' — Datei: ' . $stored['original_name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Das Dokument wurde hochgeladen.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/dokumente/{id}/sichtbarkeit */
    public function toggleVisibility(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_DOKUMENTE_VERWALTEN);
        $this->requireCsrf();

        $document = $this->findDocument((int) $params['id']);
        $visible = (int) $document['visible_for_students'] === 1 ? 0 : 1;

        $this->ctx->db->run(
            'UPDATE exhibitor_documents SET visible_for_students = ? WHERE id = ?',
            [$visible, (int) $document['id']],
        );

        $this->ctx->audit->log(
            'Dokument-Sichtbarkeit geändert',
            'info',
            'Datei: ' . (string) $document['original_name']
                . ' — für Schüler ' . ($visible === 1 ? 'sichtbar' : 'verborgen'),
            $this->ctx->schoolId(),
        );
        $this->flash('success', $visible === 1
            ? 'Das Dokument ist jetzt für Schüler:innen sichtbar.'
            : 'Das Dokument ist jetzt verborgen.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller/' . (int) $document['exhibitor_id']));
    }

    /** POST /{school}/admin/dokumente/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_DOKUMENTE_VERWALTEN);
        $this->requireCsrf();

        $document = $this->findDocument((int) $params['id']);

        $this->ctx->db->run('DELETE FROM exhibitor_documents WHERE id = ?', [(int) $document['id']]);
        $uploads = new Uploads((string) $this->ctx->config['uploads']['dir']);
        $uploads->delete('documents', (string) $document['filename']);

        $this->ctx->audit->log(
            'Aussteller-Dokument gelöscht',
            'warning',
            'Datei: ' . (string) $document['original_name'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Das Dokument wurde gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller/' . (int) $document['exhibitor_id']));
    }

    /**
     * GET /{school}/api/dokumente/download/{id}
     *
     * Schüler:innen erhalten nur Dokumente aktiver Aussteller, die als
     * sichtbar markiert sind — alles andere erfordert AUSSTELLER_SEHEN.
     */
    public function download(array $params): string
    {
        $this->requireSchool($params['school']);
        $edition = $this->ctx->requireEdition();

        $document = $this->ctx->db->fetchOne(
            'SELECT d.id, d.filename, d.original_name, d.visible_for_students, e.active
             FROM exhibitor_documents d
             JOIN exhibitors e ON e.id = d.exhibitor_id
             WHERE d.id = ? AND e.edition_id = ?',
            [(int) $params['id'], (int) $edition['id']],
        );
        if ($document === null) {
            throw new HttpException(404, 'Dieses Dokument existiert nicht.');
        }

        $publiclyVisible = (int) $document['visible_for_students'] === 1
            && (int) $document['active'] === 1;
        if (!$publiclyVisible) {
            $this->requirePermission(Permissions::AUSSTELLER_SEHEN);
        }

        $uploads = new Uploads((string) $this->ctx->config['uploads']['dir']);
        $uploads->stream('documents', (string) $document['filename'], (string) $document['original_name']);
    }

    // ---------- Helfer ----------

    /** @return array<string, mixed> Dokument der aktiven Edition (sonst 404). */
    private function findDocument(int $id): array
    {
        $document = $this->ctx->db->fetchOne(
            'SELECT d.*
             FROM exhibitor_documents d
             JOIN exhibitors e ON e.id = d.exhibitor_id
             WHERE d.id = ? AND e.edition_id = ?',
            [$id, (int) $this->ctx->requireEdition()['id']],
        );
        if ($document === null) {
            throw new HttpException(404, 'Dieses Dokument existiert nicht.');
        }

        return $document;
    }
}
