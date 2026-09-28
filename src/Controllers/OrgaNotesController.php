<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\OrgaAccess;
use App\Services\OrgaService;

/**
 * Orga-Tool: Beschlüsse und Protokolle je Raum (Team, Gesamt, Leitungsrunde).
 * Lesen darf, wer den Raum lesen darf; schreiben regelt OrgaAccess::canWriteNotes.
 */
final class OrgaNotesController extends OrgaBaseController
{
    /** GET /{school}/orga/protokolle/{space} */
    public function index(array $params): string
    {
        $access = $this->orga($params);
        $space = $this->readableSpace($access, (string) $params['space']);

        $kind = array_key_exists((string) ($_GET['art'] ?? ''), OrgaService::NOTE_KINDS) ? (string) $_GET['art'] : '';
        $q = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100);

        [$where, $args] = $this->spaceWhere($access, $space);
        if ($kind !== '') {
            $where .= ' AND n.kind = ?';
            $args[] = $kind;
        }
        if ($q !== '') {
            $where .= ' AND (n.title LIKE ? OR n.body LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            $args[] = $like;
            $args[] = $like;
        }

        $notes = $this->ctx->db->fetchAll(
            "SELECT n.*, cu.firstname, cu.lastname, cu.username,
                    uu.firstname AS upd_firstname, uu.lastname AS upd_lastname, uu.username AS upd_username
             FROM orga_notes n
             LEFT JOIN users cu ON cu.id = n.created_by
             LEFT JOIN users uu ON uu.id = n.updated_by
             WHERE {$where}
             ORDER BY COALESCE(n.meeting_date, DATE(n.created_at)) DESC, n.id DESC",
            $args,
        );

        $editing = null;
        $editId = (int) ($_GET['bearbeiten'] ?? 0);
        foreach ($notes as $note) {
            if ((int) $note['id'] === $editId) {
                $editing = $note;
            }
        }

        return $this->render('pages/orga/notes', [
            'title' => 'Beschlüsse & Protokolle · ' . $space['title'],
            'access' => $access,
            'space' => $space,
            'team' => $space['team_id'] !== null ? $access->findTeam((int) $space['team_id']) : null,
            'notes' => $notes,
            'kind' => $kind,
            'q' => $q,
            'editing' => $editing,
            'canWrite' => $access->canWriteNotes($space),
            'unread' => $access->unreadCounts(),
        ]);
    }

    /** POST /{school}/orga/protokolle/{space} */
    public function store(array $params): string
    {
        $access = $this->orga($params);
        $space = $this->writableNotesSpace($access, (string) $params['space']);
        $this->requireCsrf();

        $path = '/orga/protokolle/' . $space['slug'];
        $data = $this->readForm($path);

        $this->ctx->db->run(
            'INSERT INTO orga_notes (edition_id, channel, team_id, kind, title, body, meeting_date, created_by, updated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $access->editionId(),
                $space['channel'],
                $space['team_id'],
                $data['kind'],
                $data['title'],
                $data['body'],
                $data['meeting_date'],
                $access->userId(),
                $access->userId(),
            ],
        );

        $this->flash('success', OrgaService::NOTE_KINDS[$data['kind']] . ' gespeichert.');
        $this->redirect($this->ctx->schoolUrl($path));
    }

    /** POST /{school}/orga/protokolle/eintrag/{id} */
    public function update(array $params): string
    {
        $access = $this->orga($params);
        [$note, $space] = $this->note($access, (int) $params['id']);
        $this->requireCsrf();

        $path = '/orga/protokolle/' . $space['slug'];
        $data = $this->readForm($path . '?bearbeiten=' . (int) $note['id']);

        $this->ctx->db->run(
            'UPDATE orga_notes SET kind = ?, title = ?, body = ?, meeting_date = ?, updated_by = ? WHERE id = ?',
            [$data['kind'], $data['title'], $data['body'], $data['meeting_date'], $access->userId(), (int) $note['id']],
        );

        $this->flash('success', 'Eintrag gespeichert.');
        $this->redirect($this->ctx->schoolUrl($path));
    }

    /** POST /{school}/orga/protokolle/eintrag/{id}/loeschen */
    public function destroy(array $params): string
    {
        $access = $this->orga($params);
        [$note, $space] = $this->note($access, (int) $params['id']);
        $this->requireCsrf();

        $this->ctx->db->run('DELETE FROM orga_notes WHERE id = ?', [(int) $note['id']]);
        $this->ctx->audit->log(
            'Orga: ' . OrgaService::NOTE_KINDS[(string) $note['kind']] . ' gelöscht',
            'info',
            sprintf('%s (%s)', $note['title'], $space['title']),
            $this->ctx->schoolId(),
        );

        $this->flash('success', 'Eintrag gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/orga/protokolle/' . $space['slug']));
    }

    // ---------------------------------------------------------- Helfer

    /** @return array{channel: string, team_id: int|null, key: string, slug: string, title: string, color: string|null} */
    private function writableNotesSpace(OrgaAccess $access, string $slug): array
    {
        $space = $this->readableSpace($access, $slug);
        if (!$access->canWriteNotes($space)) {
            throw new HttpException(403, 'Beschlüsse und Protokolle führt hier die Leitung.');
        }

        return $space;
    }

    /**
     * Eintrag dieser Edition, dessen Raum der Nutzer beschreiben darf.
     *
     * @return array{0: array<string, mixed>, 1: array{channel: string, team_id: int|null, key: string, slug: string, title: string, color: string|null}}
     */
    private function note(OrgaAccess $access, int $noteId): array
    {
        $note = $this->ctx->db->fetchOne(
            'SELECT * FROM orga_notes WHERE id = ? AND edition_id = ?',
            [$noteId, $access->editionId()],
        );
        if ($note === null) {
            throw new HttpException(404, 'Diesen Eintrag gibt es nicht.');
        }

        $slug = match ((string) $note['channel']) {
            'all' => 'gesamt',
            'leads' => 'leitung',
            default => 'team-' . (int) $note['team_id'],
        };

        return [$note, $this->writableNotesSpace($access, $slug)];
    }

    /**
     * @param array{channel: string, team_id: int|null} $space
     * @return array{string, list<mixed>}
     */
    private function spaceWhere(OrgaAccess $access, array $space): array
    {
        if ($space['channel'] === 'team') {
            return ["n.edition_id = ? AND n.channel = 'team' AND n.team_id = ?", [$access->editionId(), (int) $space['team_id']]];
        }

        return ['n.edition_id = ? AND n.channel = ? AND n.team_id IS NULL', [$access->editionId(), $space['channel']]];
    }

    /** @return array{kind: string, title: string, body: string, meeting_date: string|null} */
    private function readForm(string $backPath): array
    {
        $title = $this->text('title', 200);
        $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 60000);
        if ($title === '' || $body === '') {
            $this->flash('error', 'Bitte Titel und Inhalt ausfüllen.');
            $this->redirect($this->ctx->schoolUrl($backPath));
        }

        $kind = (string) ($_POST['kind'] ?? 'protocol');

        return [
            'kind' => array_key_exists($kind, OrgaService::NOTE_KINDS) ? $kind : 'protocol',
            'title' => $title,
            'body' => $body,
            'meeting_date' => OrgaService::dateOrNull($_POST['meeting_date'] ?? null),
        ];
    }
}
