<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;

/**
 * Ankündigungen einer Schule (/{school}/admin/ankuendigungen).
 *
 * Daten-Konvention für anzeigende Module: sichtbar ist eine Ankündigung,
 * wenn is_active = 1 UND (expires_at IS NULL OR expires_at > NOW())
 * UND target_role IN ('all', <Rolle>).
 */
final class AnnouncementsController extends Controller
{
    private const TYPES = ['info', 'warning', 'success', 'error'];
    private const TARGET_ROLES = ['all', 'student', 'teacher', 'admin'];

    /** GET /{school}/admin/ankuendigungen */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANKUENDIGUNGEN_VERWALTEN);

        $announcements = $this->ctx->db->fetchAll(
            'SELECT a.*, u.username AS creator
             FROM announcements a
             LEFT JOIN users u ON u.id = a.created_by
             WHERE a.school_id = ?
             ORDER BY a.is_active DESC, a.created_at DESC',
            [(int) $this->ctx->schoolId()],
        );

        $editId = (int) ($_GET['bearbeiten'] ?? 0);
        $editing = null;
        foreach ($announcements as $announcement) {
            if ((int) $announcement['id'] === $editId) {
                $editing = $announcement;
                break;
            }
        }

        return $this->render('pages/announcements/index', [
            'title' => 'Ankündigungen',
            'announcements' => $announcements,
            'editing' => $editing,
            'types' => self::TYPES,
            'targetRoles' => self::TARGET_ROLES,
            'typeLabels' => $this->typeLabels(),
            'roleLabels' => $this->roleLabels(),
        ]);
    }

    /** POST /{school}/admin/ankuendigungen */
    public function store(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANKUENDIGUNGEN_VERWALTEN);
        $this->requireCsrf();

        $back = $this->ctx->schoolUrl('/admin/ankuendigungen');
        $data = $this->readForm($back);

        $this->ctx->db->run(
            'INSERT INTO announcements (school_id, title, body, type, target_role, expires_at, is_active, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                (int) $this->ctx->schoolId(),
                $data['title'],
                $data['body'],
                $data['type'],
                $data['target_role'],
                $data['expires_at'],
                $data['is_active'],
                $this->ctx->auth->id(),
            ],
        );

        $this->ctx->audit->log('Ankündigung erstellt', 'info', $data['title'], $this->ctx->schoolId());
        $this->flash('success', 'Ankündigung erstellt.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/ankuendigungen/{id} */
    public function update(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANKUENDIGUNGEN_VERWALTEN);
        $this->requireCsrf();

        $back = $this->ctx->schoolUrl('/admin/ankuendigungen');
        $announcement = $this->requireAnnouncement((int) $params['id']);
        $data = $this->readForm($back);

        $this->ctx->db->run(
            'UPDATE announcements
             SET title = ?, body = ?, type = ?, target_role = ?, expires_at = ?, is_active = ?
             WHERE id = ? AND school_id = ?',
            [
                $data['title'],
                $data['body'],
                $data['type'],
                $data['target_role'],
                $data['expires_at'],
                $data['is_active'],
                (int) $announcement['id'],
                (int) $this->ctx->schoolId(),
            ],
        );

        $this->ctx->audit->log('Ankündigung bearbeitet', 'info', $data['title'], $this->ctx->schoolId());
        $this->flash('success', 'Ankündigung gespeichert.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/ankuendigungen/{id}/umschalten */
    public function toggle(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANKUENDIGUNGEN_VERWALTEN);
        $this->requireCsrf();

        $announcement = $this->requireAnnouncement((int) $params['id']);
        $active = (int) $announcement['is_active'] === 1 ? 0 : 1;

        $this->ctx->db->run(
            'UPDATE announcements SET is_active = ? WHERE id = ? AND school_id = ?',
            [$active, (int) $announcement['id'], (int) $this->ctx->schoolId()],
        );

        $this->ctx->audit->log(
            'Ankündigung ' . ($active === 1 ? 'aktiviert' : 'deaktiviert'),
            'info',
            (string) $announcement['title'],
            $this->ctx->schoolId(),
        );
        $this->flash('success', $active === 1 ? 'Ankündigung ist aktiv.' : 'Ankündigung ist deaktiviert.');
        $this->redirect($this->ctx->schoolUrl('/admin/ankuendigungen'));
    }

    /** POST /{school}/admin/ankuendigungen/{id}/loeschen */
    public function delete(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANKUENDIGUNGEN_VERWALTEN);
        $this->requireCsrf();

        $announcement = $this->requireAnnouncement((int) $params['id']);
        $this->ctx->db->run(
            'DELETE FROM announcements WHERE id = ? AND school_id = ?',
            [(int) $announcement['id'], (int) $this->ctx->schoolId()],
        );

        $this->ctx->audit->log('Ankündigung gelöscht', 'warning', (string) $announcement['title'], $this->ctx->schoolId());
        $this->flash('success', 'Ankündigung gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/admin/ankuendigungen'));
    }

    // ---------- Helfer ----------

    private function requireAnnouncement(int $id): array
    {
        $announcement = $this->ctx->db->fetchOne(
            'SELECT * FROM announcements WHERE id = ? AND school_id = ?',
            [$id, (int) $this->ctx->schoolId()],
        );
        if ($announcement === null) {
            throw new HttpException(404, 'Diese Ankündigung existiert nicht.');
        }

        return $announcement;
    }

    /**
     * @return array{title: string, body: ?string, type: string, target_role: string, expires_at: ?string, is_active: int}
     */
    private function readForm(string $back): array
    {
        $title = trim((string) ($_POST['title'] ?? ''));
        if ($title === '') {
            $this->flash('error', 'Bitte gib einen Titel an.');
            $this->redirect($back);
        }

        $body = trim((string) ($_POST['body'] ?? ''));
        $type = (string) ($_POST['type'] ?? 'info');
        $targetRole = (string) ($_POST['target_role'] ?? 'all');

        $expiresRaw = trim((string) ($_POST['expires_at'] ?? ''));
        $expires = null;
        if ($expiresRaw !== '') {
            try {
                $expires = (new \DateTimeImmutable($expiresRaw))->format('Y-m-d H:i:s');
            } catch (\Exception) {
                $this->flash('error', 'Das Ablaufdatum ist ungültig.');
                $this->redirect($back);
            }
        }

        return [
            'title' => mb_substr($title, 0, 200),
            'body' => $body === '' ? null : $body,
            'type' => in_array($type, self::TYPES, true) ? $type : 'info',
            'target_role' => in_array($targetRole, self::TARGET_ROLES, true) ? $targetRole : 'all',
            'expires_at' => $expires,
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];
    }

    /** @return array<string, string> */
    private function typeLabels(): array
    {
        return ['info' => 'Information', 'warning' => 'Warnung', 'success' => 'Erfolg', 'error' => 'Wichtig'];
    }

    /** @return array<string, string> */
    private function roleLabels(): array
    {
        return ['all' => 'Alle', 'student' => 'Schüler:innen', 'teacher' => 'Lehrkräfte', 'admin' => 'Verwaltung'];
    }
}
