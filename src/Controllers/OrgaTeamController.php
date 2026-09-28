<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\OrgaAccess;
use App\Services\OrgaService;

/**
 * Orga-Tool: Teamseite mit Zielen und Organisationspunkten.
 *
 * Teamleitung (und Verwaltung) bearbeitet alles; Mitglieder lesen,
 * kommentieren und setzen Status sowie Checkliste der Punkte, die ihnen
 * zugewiesen sind (OrgaAccess::canWorkOnTask).
 */
final class OrgaTeamController extends OrgaBaseController
{
    // ---------------------------------------------------------- Seiten

    /** GET /{school}/orga/teams/{id} */
    public function show(array $params): string
    {
        $access = $this->orga($params);
        $team = $this->viewableTeam($access, (int) $params['id']);
        $teamId = (int) $team['id'];
        $service = $this->service();

        $upcoming = $this->ctx->db->fetchAll(
            "SELECT t.*, u.firstname, u.lastname, u.username
             FROM orga_tasks t LEFT JOIN users u ON u.id = t.assignee_id
             WHERE t.team_id = ? AND t.status <> 'done'
             ORDER BY t.due_date IS NULL, t.due_date, FIELD(t.priority, 'high', 'normal', 'low'), t.id
             LIMIT 8",
            [$teamId],
        );

        return $this->render('pages/orga/team', [
            'title' => $team['name'],
            'access' => $access,
            'team' => $team,
            'space' => OrgaAccess::teamSpace($team),
            'stats' => $service->stats([$teamId])[$teamId],
            'members' => $service->members($teamId),
            'goals' => $service->goals($teamId),
            'upcoming' => $upcoming,
            'deadline' => OrgaService::deadlineFor($team, $service->settings($access->editionId())),
            'canLead' => $access->canLeadTeam($teamId),
            'unread' => $access->unreadCounts(),
            'pageScripts' => ['orga.js'],
        ]);
    }

    /** GET /{school}/orga/teams/{id}/aufgaben */
    public function tasks(array $params): string
    {
        $access = $this->orga($params);
        $team = $this->viewableTeam($access, (int) $params['id']);
        $teamId = (int) $team['id'];
        $service = $this->service();

        $view = ($_GET['ansicht'] ?? 'board') === 'liste' ? 'liste' : 'board';
        $filters = [
            'q' => mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 100),
            'person' => (string) ($_GET['person'] ?? ''),
            'ziel' => (int) ($_GET['ziel'] ?? 0),
            'aussteller' => (int) ($_GET['aussteller'] ?? 0),
            'status' => array_key_exists((string) ($_GET['status'] ?? ''), OrgaService::STATUS) ? (string) $_GET['status'] : '',
        ];

        $where = ['t.team_id = ?'];
        $args = [$teamId];
        if ($filters['q'] !== '') {
            $where[] = '(t.title LIKE ? OR t.description LIKE ?)';
            $like = '%' . addcslashes($filters['q'], '%_\\') . '%';
            $args[] = $like;
            $args[] = $like;
        }
        if ($filters['person'] === 'ich') {
            $where[] = 't.assignee_id = ?';
            $args[] = $access->userId();
        } elseif ($filters['person'] === 'niemand') {
            $where[] = 't.assignee_id IS NULL';
        } elseif (ctype_digit($filters['person'])) {
            $where[] = 't.assignee_id = ?';
            $args[] = (int) $filters['person'];
        }
        if ($filters['ziel'] > 0) {
            $where[] = 't.goal_id = ?';
            $args[] = $filters['ziel'];
        }
        if ($filters['aussteller'] > 0) {
            $where[] = 't.exhibitor_id = ?';
            $args[] = $filters['aussteller'];
        }
        if ($filters['status'] !== '' && $view === 'liste') {
            $where[] = 't.status = ?';
            $args[] = $filters['status'];
        }

        $tasks = $this->ctx->db->fetchAll(
            'SELECT t.*, u.firstname, u.lastname, u.username, e.name AS exhibitor_name, g.title AS goal_title,
                    (SELECT COUNT(*) FROM orga_task_checklist c WHERE c.task_id = t.id) AS check_total,
                    (SELECT COUNT(*) FROM orga_task_checklist c WHERE c.task_id = t.id AND c.is_done = 1) AS check_done,
                    (SELECT COUNT(*) FROM orga_task_comments c WHERE c.task_id = t.id) AS comment_count
             FROM orga_tasks t
             LEFT JOIN users u ON u.id = t.assignee_id
             LEFT JOIN exhibitors e ON e.id = t.exhibitor_id
             LEFT JOIN orga_goals g ON g.id = t.goal_id
             WHERE ' . implode(' AND ', $where) . "
             ORDER BY FIELD(t.priority, 'high', 'normal', 'low'), t.due_date IS NULL, t.due_date, t.sort_order, t.id",
            $args,
        );

        return $this->render('pages/orga/tasks', [
            'title' => 'Organisationspunkte · ' . $team['name'],
            'access' => $access,
            'team' => $team,
            'space' => OrgaAccess::teamSpace($team),
            'tasks' => $tasks,
            'mode' => $view,
            'filters' => $filters,
            'members' => $service->members($teamId),
            'goals' => $service->goals($teamId),
            'exhibitors' => $service->exhibitors($access->editionId()),
            'canLead' => $access->canLeadTeam($teamId),
            'unread' => $access->unreadCounts(),
            'pageScripts' => ['orga.js'],
        ]);
    }

    /** GET /{school}/orga/aufgaben/{id} */
    public function task(array $params): string
    {
        $access = $this->orga($params);
        $task = $this->viewableTask($access, (int) $params['id']);
        $team = (array) $access->findTeam((int) $task['team_id']);
        $service = $this->service();

        $detail = $this->ctx->db->fetchOne(
            'SELECT t.*, u.firstname, u.lastname, u.username, e.name AS exhibitor_name, g.title AS goal_title,
                    cu.firstname AS creator_firstname, cu.lastname AS creator_lastname, cu.username AS creator_username
             FROM orga_tasks t
             LEFT JOIN users u ON u.id = t.assignee_id
             LEFT JOIN users cu ON cu.id = t.created_by
             LEFT JOIN exhibitors e ON e.id = t.exhibitor_id
             LEFT JOIN orga_goals g ON g.id = t.goal_id
             WHERE t.id = ?',
            [(int) $task['id']],
        );
        $checklist = $this->ctx->db->fetchAll(
            'SELECT * FROM orga_task_checklist WHERE task_id = ? ORDER BY sort_order, id',
            [(int) $task['id']],
        );
        $comments = $this->ctx->db->fetchAll(
            'SELECT c.*, u.firstname, u.lastname, u.username
             FROM orga_task_comments c LEFT JOIN users u ON u.id = c.user_id
             WHERE c.task_id = ? ORDER BY c.id',
            [(int) $task['id']],
        );

        return $this->render('pages/orga/task', [
            'title' => (string) $task['title'],
            'access' => $access,
            'team' => $team,
            'space' => OrgaAccess::teamSpace($team),
            'task' => $detail,
            'checklist' => $checklist,
            'comments' => $comments,
            'members' => $service->members((int) $team['id']),
            'goals' => $service->goals((int) $team['id']),
            'exhibitors' => $service->exhibitors($access->editionId()),
            'canLead' => $access->canLeadTeam((int) $team['id']),
            'canWork' => $access->canWorkOnTask($task),
            'canComment' => $access->canCommentInTeam((int) $team['id']),
            'unread' => $access->unreadCounts(),
        ]);
    }

    // ---------------------------------------------------------- Ziele

    /** POST /{school}/orga/teams/{id}/ziele */
    public function storeGoal(array $params): string
    {
        $access = $this->orga($params);
        $team = $this->ledTeam($access, (int) $params['id']);
        $this->requireCsrf();

        $title = $this->text('title', 200);
        if ($title === '') {
            $this->flash('error', 'Bitte gib dem Ziel einen Titel.');
            $this->back('/orga/teams/' . (int) $team['id']);
        }

        $next = (int) $this->ctx->db->fetchValue(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM orga_goals WHERE team_id = ?',
            [(int) $team['id']],
        );
        $this->ctx->db->run(
            'INSERT INTO orga_goals (team_id, title, description, due_date, sort_order) VALUES (?, ?, ?, ?, ?)',
            [
                (int) $team['id'],
                $title,
                $this->text('description', 2000) ?: null,
                OrgaService::dateOrNull($_POST['due_date'] ?? null),
                min($next, 65535),
            ],
        );

        $this->flash('success', 'Ziel hinzugefügt.');
        $this->back('/orga/teams/' . (int) $team['id']);
    }

    /** POST /{school}/orga/ziele/{id} */
    public function updateGoal(array $params): string
    {
        $access = $this->orga($params);
        $goal = $this->ledGoal($access, (int) $params['id']);
        $this->requireCsrf();

        $title = $this->text('title', 200);
        if ($title === '') {
            $this->flash('error', 'Bitte gib dem Ziel einen Titel.');
            $this->back('/orga/teams/' . (int) $goal['team_id']);
        }

        $this->ctx->db->run(
            'UPDATE orga_goals SET title = ?, description = ?, due_date = ? WHERE id = ?',
            [
                $title,
                $this->text('description', 2000) ?: null,
                OrgaService::dateOrNull($_POST['due_date'] ?? null),
                (int) $goal['id'],
            ],
        );

        $this->flash('success', 'Ziel gespeichert.');
        $this->back('/orga/teams/' . (int) $goal['team_id']);
    }

    /** POST /{school}/orga/ziele/{id}/abhaken */
    public function toggleGoal(array $params): string
    {
        $access = $this->orga($params);
        $goal = $this->ledGoal($access, (int) $params['id']);
        $this->requireCsrf();

        $done = (int) $goal['is_done'] === 1 ? 0 : 1;
        $this->ctx->db->run(
            'UPDATE orga_goals SET is_done = ?, done_at = ?, done_by = ? WHERE id = ?',
            [
                $done,
                $done === 1 ? date('Y-m-d H:i:s') : null,
                $done === 1 ? $access->userId() : null,
                (int) $goal['id'],
            ],
        );

        $this->flash('success', $done === 1 ? 'Ziel abgehakt.' : 'Ziel wieder geöffnet.');
        $this->back('/orga/teams/' . (int) $goal['team_id']);
    }

    /** POST /{school}/orga/ziele/{id}/loeschen */
    public function destroyGoal(array $params): string
    {
        $access = $this->orga($params);
        $goal = $this->ledGoal($access, (int) $params['id']);
        $this->requireCsrf();

        // Verknüpfte Punkte bleiben erhalten (goal_id wird per Fremdschlüssel NULL).
        $this->ctx->db->run('DELETE FROM orga_goals WHERE id = ?', [(int) $goal['id']]);
        $this->ctx->audit->log('Orga-Ziel gelöscht', 'info', (string) $goal['title'], $this->ctx->schoolId());

        $this->flash('success', 'Ziel gelöscht.');
        $this->back('/orga/teams/' . (int) $goal['team_id']);
    }

    // ---------------------------------------------------------- Organisationspunkte

    /** POST /{school}/orga/teams/{id}/aufgaben */
    public function storeTask(array $params): string
    {
        $access = $this->orga($params);
        $team = $this->ledTeam($access, (int) $params['id']);
        $this->requireCsrf();
        $teamId = (int) $team['id'];

        $data = $this->readTaskForm($access, $teamId, '/orga/teams/' . $teamId . '/aufgaben');
        $next = (int) $this->ctx->db->fetchValue(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM orga_tasks WHERE team_id = ?',
            [$teamId],
        );

        $this->ctx->db->run(
            'INSERT INTO orga_tasks
                (team_id, goal_id, exhibitor_id, title, description, status, priority, due_date,
                 assignee_id, sort_order, created_by, completed_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $teamId,
                $data['goal_id'],
                $data['exhibitor_id'],
                $data['title'],
                $data['description'],
                $data['status'],
                $data['priority'],
                $data['due_date'],
                $data['assignee_id'],
                $next,
                $access->userId(),
                $data['status'] === 'done' ? date('Y-m-d H:i:s') : null,
            ],
        );
        $taskId = $this->ctx->db->lastInsertId();

        if ($data['assignee_id'] !== null) {
            $this->notify(
                [$data['assignee_id']],
                sprintf('Dir wurde im Team „%s“ ein Organisationspunkt zugewiesen: %s', $team['name'], $data['title']),
                '/orga/aufgaben/' . $taskId,
            );
        }

        $this->flash('success', 'Organisationspunkt angelegt.');
        $this->back('/orga/teams/' . $teamId . '/aufgaben');
    }

    /** POST /{school}/orga/aufgaben/{id} */
    public function updateTask(array $params): string
    {
        $access = $this->orga($params);
        $task = $this->viewableTask($access, (int) $params['id']);
        $team = $this->ledTeam($access, (int) $task['team_id']);
        $this->requireCsrf();

        $path = '/orga/aufgaben/' . (int) $task['id'];
        $data = $this->readTaskForm($access, (int) $team['id'], $path);

        $this->ctx->db->run(
            'UPDATE orga_tasks
             SET goal_id = ?, exhibitor_id = ?, title = ?, description = ?, status = ?, priority = ?,
                 due_date = ?, assignee_id = ?, completed_at = ?
             WHERE id = ?',
            [
                $data['goal_id'],
                $data['exhibitor_id'],
                $data['title'],
                $data['description'],
                $data['status'],
                $data['priority'],
                $data['due_date'],
                $data['assignee_id'],
                $this->completedAt($task, $data['status']),
                (int) $task['id'],
            ],
        );

        $previous = $task['assignee_id'] !== null ? (int) $task['assignee_id'] : null;
        if ($data['assignee_id'] !== null && $data['assignee_id'] !== $previous) {
            $this->notify(
                [$data['assignee_id']],
                sprintf('Dir wurde im Team „%s“ ein Organisationspunkt zugewiesen: %s', $team['name'], $data['title']),
                $path,
            );
        }

        $this->flash('success', 'Organisationspunkt gespeichert.');
        $this->back($path);
    }

    /** POST /{school}/orga/aufgaben/{id}/status */
    public function setStatus(array $params): string
    {
        $access = $this->orga($params);
        $task = $this->workableTask($access, (int) $params['id']);
        $this->requireCsrf();

        $status = (string) ($_POST['status'] ?? '');
        if (!array_key_exists($status, OrgaService::STATUS)) {
            throw new HttpException(400, 'Unbekannter Status.');
        }
        $this->applyStatus($task, $status);

        $this->flash('success', 'Status: ' . OrgaService::STATUS[$status] . '.');
        $this->back('/orga/aufgaben/' . (int) $task['id']);
    }

    /** POST /{school}/api/orga/aufgaben/{id}/status (Ziehen auf dem Board) */
    public function apiStatus(array $params): array
    {
        $access = $this->orga($params);
        $task = $this->workableTask($access, (int) $params['id']);
        $this->requireCsrf();

        $input = $this->jsonInput();
        $status = (string) ($input['status'] ?? '');
        if (!array_key_exists($status, OrgaService::STATUS)) {
            return $this->jsonError('Unbekannter Status.');
        }
        $this->applyStatus($task, $status);

        return ['success' => true, 'status' => $status, 'label' => OrgaService::STATUS[$status]];
    }

    /** POST /{school}/orga/aufgaben/{id}/loeschen */
    public function destroyTask(array $params): string
    {
        $access = $this->orga($params);
        $task = $this->viewableTask($access, (int) $params['id']);
        $team = $this->ledTeam($access, (int) $task['team_id']);
        $this->requireCsrf();

        $this->ctx->db->run('DELETE FROM orga_tasks WHERE id = ?', [(int) $task['id']]);
        $this->ctx->audit->log(
            'Organisationspunkt gelöscht',
            'info',
            sprintf('%s (Team %s)', $task['title'], $team['name']),
            $this->ctx->schoolId(),
        );

        $this->flash('success', 'Organisationspunkt gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/orga/teams/' . (int) $team['id'] . '/aufgaben'));
    }

    // ---------------------------------------------------------- Checkliste & Kommentare

    /** POST /{school}/orga/aufgaben/{id}/checkliste */
    public function addChecklistItem(array $params): string
    {
        $access = $this->orga($params);
        $task = $this->viewableTask($access, (int) $params['id']);
        $this->ledTeam($access, (int) $task['team_id']);
        $this->requireCsrf();

        $label = $this->text('label', 300);
        if ($label !== '') {
            $next = (int) $this->ctx->db->fetchValue(
                'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM orga_task_checklist WHERE task_id = ?',
                [(int) $task['id']],
            );
            $this->ctx->db->run(
                'INSERT INTO orga_task_checklist (task_id, label, sort_order) VALUES (?, ?, ?)',
                [(int) $task['id'], $label, min($next, 65535)],
            );
        }

        $this->back('/orga/aufgaben/' . (int) $task['id']);
    }

    /** POST /{school}/orga/checkliste/{id}/umschalten */
    public function toggleChecklistItem(array $params): string
    {
        $access = $this->orga($params);
        $item = $this->checklistItem($access, (int) $params['id']);
        $this->workableTask($access, (int) $item['task_id']);
        $this->requireCsrf();

        $this->ctx->db->run(
            'UPDATE orga_task_checklist SET is_done = 1 - is_done WHERE id = ?',
            [(int) $item['id']],
        );

        $this->back('/orga/aufgaben/' . (int) $item['task_id']);
    }

    /** POST /{school}/orga/checkliste/{id}/loeschen */
    public function destroyChecklistItem(array $params): string
    {
        $access = $this->orga($params);
        $item = $this->checklistItem($access, (int) $params['id']);
        $task = $this->viewableTask($access, (int) $item['task_id']);
        $this->ledTeam($access, (int) $task['team_id']);
        $this->requireCsrf();

        $this->ctx->db->run('DELETE FROM orga_task_checklist WHERE id = ?', [(int) $item['id']]);

        $this->back('/orga/aufgaben/' . (int) $item['task_id']);
    }

    /** POST /{school}/orga/aufgaben/{id}/kommentare */
    public function addComment(array $params): string
    {
        $access = $this->orga($params);
        $task = $this->viewableTask($access, (int) $params['id']);
        if (!$access->canCommentInTeam((int) $task['team_id'])) {
            throw new HttpException(403, 'Nur Teammitglieder können kommentieren.');
        }
        $this->requireCsrf();

        $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, 4000);
        $path = '/orga/aufgaben/' . (int) $task['id'];
        if ($body === '') {
            $this->back($path);
        }

        $this->ctx->db->run(
            'INSERT INTO orga_task_comments (task_id, user_id, body) VALUES (?, ?, ?)',
            [(int) $task['id'], $access->userId(), $body],
        );

        if ($task['assignee_id'] !== null) {
            $this->notify(
                [(int) $task['assignee_id']],
                sprintf('Neuer Kommentar zu „%s“', $task['title']),
                $path,
            );
        }

        $this->back($path);
    }

    // ---------------------------------------------------------- Helfer

    /**
     * Punkt, an dem der Nutzer arbeiten darf (Leitung oder zugewiesen).
     *
     * @return array<string, mixed>
     */
    private function workableTask(OrgaAccess $access, int $taskId): array
    {
        $task = $this->viewableTask($access, $taskId);
        if (!$access->canWorkOnTask($task)) {
            throw new HttpException(403, 'Den Status ändern nur die Teamleitung und die zugewiesene Person.');
        }

        return $task;
    }

    /** @return array<string, mixed> */
    private function checklistItem(OrgaAccess $access, int $itemId): array
    {
        $item = $this->ctx->db->fetchOne(
            'SELECT c.* FROM orga_task_checklist c
             JOIN orga_tasks t ON t.id = c.task_id
             JOIN orga_teams tm ON tm.id = t.team_id
             WHERE c.id = ? AND tm.edition_id = ?',
            [$itemId, $access->editionId()],
        );
        if ($item === null) {
            throw new HttpException(404);
        }

        return $item;
    }

    /** @param array<string, mixed> $task */
    private function applyStatus(array $task, string $status): void
    {
        $this->ctx->db->run(
            'UPDATE orga_tasks SET status = ?, completed_at = ? WHERE id = ?',
            [$status, $this->completedAt($task, $status), (int) $task['id']],
        );
    }

    /** Erledigt-Zeitpunkt: bleibt beim erneuten Speichern stehen, entfällt beim Wiederöffnen. */
    private function completedAt(array $task, string $status): ?string
    {
        if ($status !== 'done') {
            return null;
        }

        return $task['status'] === 'done' && !empty($task['completed_at'])
            ? (string) $task['completed_at']
            : date('Y-m-d H:i:s');
    }

    /**
     * @return array{title: string, description: string|null, status: string, priority: string, due_date: string|null,
     *               assignee_id: int|null, goal_id: int|null, exhibitor_id: int|null}
     */
    private function readTaskForm(OrgaAccess $access, int $teamId, string $backPath): array
    {
        $title = $this->text('title', 200);
        if ($title === '') {
            $this->flash('error', 'Bitte gib dem Organisationspunkt einen Titel.');
            $this->back($backPath);
        }

        $status = (string) ($_POST['status'] ?? 'open');
        $priority = (string) ($_POST['priority'] ?? 'normal');
        $service = $this->service();

        $assignee = (int) ($_POST['assignee_id'] ?? 0);
        if ($assignee > 0 && !$service->isTeamMember($teamId, $assignee)) {
            $this->flash('error', 'Zuweisen lässt sich nur an Mitglieder dieses Teams.');
            $this->back($backPath);
        }

        $goal = (int) ($_POST['goal_id'] ?? 0);
        if ($goal > 0 && $this->ctx->db->fetchValue(
            'SELECT 1 FROM orga_goals WHERE id = ? AND team_id = ?',
            [$goal, $teamId],
        ) === null) {
            $goal = 0;
        }

        $exhibitor = (int) ($_POST['exhibitor_id'] ?? 0);
        if ($exhibitor > 0 && !$service->exhibitorInEdition($exhibitor, $access->editionId())) {
            $exhibitor = 0;
        }

        return [
            'title' => $title,
            'description' => $this->text('description', 5000) ?: null,
            'status' => array_key_exists($status, OrgaService::STATUS) ? $status : 'open',
            'priority' => array_key_exists($priority, OrgaService::PRIORITY) ? $priority : 'normal',
            'due_date' => OrgaService::dateOrNull($_POST['due_date'] ?? null),
            'assignee_id' => $assignee > 0 ? $assignee : null,
            'goal_id' => $goal > 0 ? $goal : null,
            'exhibitor_id' => $exhibitor > 0 ? $exhibitor : null,
        ];
    }
}
