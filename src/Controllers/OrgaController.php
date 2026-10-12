<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Announcements;
use App\Services\OrgaService;

/**
 * Orga-Tool: Einstieg (/{school}/orga) und „Meine Aufgaben“.
 */
final class OrgaController extends OrgaBaseController
{
    /** GET /{school}/orga */
    public function index(array $params): string
    {
        $access = $this->orga($params);
        $service = $this->service();
        $editionId = $access->editionId();

        $teams = $access->visibleTeams();
        $stats = $service->stats(array_map(static fn (array $t): int => (int) $t['id'], $teams));
        $memberships = $access->memberships();

        $myOpen = $this->ctx->db->fetchAll(
            "SELECT t.*, tm.name AS team_name, tm.color AS team_color
             FROM orga_tasks t JOIN orga_teams tm ON tm.id = t.team_id
             WHERE tm.edition_id = ? AND t.assignee_id = ? AND t.status <> 'done'
             ORDER BY t.due_date IS NULL, t.due_date, FIELD(t.priority, 'high', 'normal', 'low'), t.id
             LIMIT 6",
            [$editionId, $access->userId()],
        );
        $myOpenCount = (int) $this->ctx->db->fetchValue(
            "SELECT COUNT(*) FROM orga_tasks t JOIN orga_teams tm ON tm.id = t.team_id
             WHERE tm.edition_id = ? AND t.assignee_id = ? AND t.status <> 'done'",
            [$editionId, $access->userId()],
        );

        $settings = $service->settings($editionId);

        return $this->render('pages/orga/index', [
            'title' => 'Orga-Tool',
            'access' => $access,
            'teams' => $teams,
            'stats' => $stats,
            'memberships' => $memberships,
            'spaces' => $access->readableSpaces(),
            'unread' => $access->unreadCounts(),
            'deadline' => OrgaService::deadlineFor(null, $settings),
            'myOpen' => $myOpen,
            'myOpenCount' => $myOpenCount,
            'announcements' => (new Announcements($this->ctx->db))
                ->activeFor((int) $this->ctx->schoolId(), $this->ctx->auth->role()),
            'pageScripts' => ['orga.js'],
        ]);
    }

    /** GET /{school}/orga/meine-aufgaben */
    public function myTasks(array $params): string
    {
        $access = $this->orga($params);
        $editionId = $access->editionId();
        $showDone = ($_GET['erledigt'] ?? '') === '1';

        $mine = $this->ctx->db->fetchAll(
            "SELECT t.*, tm.name AS team_name, tm.color AS team_color, e.name AS exhibitor_name, g.title AS goal_title,
                    (SELECT COUNT(*) FROM orga_task_checklist c WHERE c.task_id = t.id) AS check_total,
                    (SELECT COUNT(*) FROM orga_task_checklist c WHERE c.task_id = t.id AND c.is_done = 1) AS check_done
             FROM orga_tasks t
             JOIN orga_teams tm ON tm.id = t.team_id
             LEFT JOIN exhibitors e ON e.id = t.exhibitor_id
             LEFT JOIN orga_goals g ON g.id = t.goal_id
             WHERE tm.edition_id = ? AND t.assignee_id = ?" . ($showDone ? '' : " AND t.status <> 'done'") . "
             ORDER BY t.status = 'done', t.due_date IS NULL, t.due_date, FIELD(t.priority, 'high', 'normal', 'low'), t.id",
            [$editionId, $access->userId()],
        );

        // Unverteilte Punkte in Teams, die ich leite: Die Leitung soll sie sehen.
        $ledIds = array_keys(array_filter($access->memberships()));
        $unassigned = [];
        if ($ledIds !== []) {
            $placeholders = implode(',', array_fill(0, count($ledIds), '?'));
            $unassigned = $this->ctx->db->fetchAll(
                "SELECT t.*, tm.name AS team_name, tm.color AS team_color
                 FROM orga_tasks t JOIN orga_teams tm ON tm.id = t.team_id
                 WHERE t.team_id IN ({$placeholders}) AND t.assignee_id IS NULL AND t.status <> 'done'
                 ORDER BY t.due_date IS NULL, t.due_date, t.id",
                $ledIds,
            );
        }

        return $this->render('pages/orga/my-tasks', [
            'title' => 'Meine Aufgaben',
            'access' => $access,
            'tasks' => $mine,
            'unassigned' => $unassigned,
            'showDone' => $showDone,
        ]);
    }
}
