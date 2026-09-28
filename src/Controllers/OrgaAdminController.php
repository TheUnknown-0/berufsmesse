<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\OrgaAccess;
use App\Services\OrgaService;

/**
 * Orga-Tool: Verwaltung (/{school}/orga/verwaltung).
 *
 * Teams anlegen, Mitglieder und Leitungen zuordnen, Abschlusstermin setzen.
 * Nur mit ORGA_TOOL_VERWALTEN (admin/school_admin implizit).
 */
final class OrgaAdminController extends OrgaBaseController
{
    /** GET /{school}/orga/verwaltung */
    public function index(array $params): string
    {
        $access = $this->orgaManager($params);
        $service = $this->service();

        $teams = $access->visibleTeams();

        return $this->render('pages/orga/admin', [
            'title' => 'Orga-Verwaltung',
            'access' => $access,
            'teams' => $teams,
            'stats' => $service->stats(array_map(static fn (array $t): int => (int) $t['id'], $teams)),
            'settings' => $service->settings($access->editionId()),
            'candidates' => $access->memberCandidates(),
            'colors' => OrgaService::COLORS,
        ]);
    }

    /** POST /{school}/orga/verwaltung/termin */
    public function saveDeadline(array $params): string
    {
        $access = $this->orgaManager($params);
        $this->requireCsrf();

        $deadline = OrgaService::dateTimeOrNull($_POST['deadline'] ?? null);
        $label = $this->text('deadline_label', 150);
        $this->service()->saveSettings($access->editionId(), $deadline, $label !== '' ? $label : null);

        $this->ctx->audit->log(
            'Orga-Abschlusstermin gesetzt',
            'info',
            $deadline !== null ? $deadline . ($label !== '' ? ' (' . $label . ')' : '') : 'entfernt',
            $this->ctx->schoolId(),
        );
        $this->flash('success', $deadline !== null ? 'Abschlusstermin gespeichert.' : 'Abschlusstermin entfernt.');
        $this->redirect($this->ctx->schoolUrl('/orga/verwaltung'));
    }

    /** POST /{school}/orga/verwaltung/teams */
    public function store(array $params): string
    {
        $access = $this->orgaManager($params);
        $this->requireCsrf();

        $data = $this->readTeamForm('/orga/verwaltung');
        $next = (int) $this->ctx->db->fetchValue(
            'SELECT COALESCE(MAX(sort_order), 0) + 1 FROM orga_teams WHERE edition_id = ?',
            [$access->editionId()],
        );

        $this->ctx->db->run(
            'INSERT INTO orga_teams (edition_id, name, color, description, deadline, sort_order, created_by)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $access->editionId(),
                $data['name'],
                $data['color'],
                $data['description'],
                $data['deadline'],
                min($next, 65535),
                $access->userId(),
            ],
        );
        $teamId = $this->ctx->db->lastInsertId();

        $this->ctx->audit->log('Orga-Team angelegt', 'info', $data['name'], $this->ctx->schoolId());
        $this->flash('success', 'Team angelegt. Ordne jetzt Mitglieder und Leitung zu.');
        $this->redirect($this->ctx->schoolUrl('/orga/verwaltung/teams/' . $teamId));
    }

    /** GET /{school}/orga/verwaltung/teams/{id} */
    public function edit(array $params): string
    {
        $access = $this->orgaManager($params);
        $team = $this->teamOr404($access, (int) $params['id']);

        $current = [];
        foreach ($this->service()->members((int) $team['id']) as $member) {
            $current[(int) $member['id']] = (int) $member['is_leader'] === 1;
        }

        return $this->render('pages/orga/admin-team', [
            'title' => 'Team bearbeiten · ' . $team['name'],
            'access' => $access,
            'team' => $team,
            'current' => $current,
            'candidates' => $access->memberCandidates(),
            'colors' => OrgaService::COLORS,
            'pageScripts' => ['orga.js'],
        ]);
    }

    /** POST /{school}/orga/verwaltung/teams/{id} */
    public function update(array $params): string
    {
        $access = $this->orgaManager($params);
        $team = $this->teamOr404($access, (int) $params['id']);
        $this->requireCsrf();

        $path = '/orga/verwaltung/teams/' . (int) $team['id'];
        $data = $this->readTeamForm($path);

        $this->ctx->db->run(
            'UPDATE orga_teams SET name = ?, color = ?, description = ?, deadline = ? WHERE id = ? AND edition_id = ?',
            [
                $data['name'],
                $data['color'],
                $data['description'],
                $data['deadline'],
                (int) $team['id'],
                $access->editionId(),
            ],
        );

        $this->ctx->audit->log('Orga-Team bearbeitet', 'info', $data['name'], $this->ctx->schoolId());
        $this->flash('success', 'Team gespeichert.');
        $this->redirect($this->ctx->schoolUrl($path));
    }

    /**
     * POST /{school}/orga/verwaltung/teams/{id}/mitglieder
     *
     * Ersetzt die Zuordnung vollständig. Leitungen sind immer auch Mitglieder.
     * Wer das Team verlässt, verliert seine Zuweisungen darin.
     */
    public function saveMembers(array $params): string
    {
        $access = $this->orgaManager($params);
        $team = $this->teamOr404($access, (int) $params['id']);
        $this->requireCsrf();
        $teamId = (int) $team['id'];

        $allowed = array_map(
            static fn (array $u): int => (int) $u['id'],
            $access->memberCandidates(),
        );
        $members = array_values(array_intersect($allowed, array_map('intval', (array) ($_POST['members'] ?? []))));
        $leaders = array_values(array_intersect($allowed, array_map('intval', (array) ($_POST['leaders'] ?? []))));
        $members = array_values(array_unique([...$members, ...$leaders]));

        $before = [];
        foreach ($this->service()->members($teamId) as $member) {
            $before[(int) $member['id']] = (int) $member['is_leader'] === 1;
        }

        $this->ctx->db->transaction(function () use ($teamId, $members, $leaders, $before): void {
            $removed = array_diff(array_keys($before), $members);
            foreach ($removed as $userId) {
                $this->ctx->db->run(
                    'DELETE FROM orga_team_members WHERE team_id = ? AND user_id = ?',
                    [$teamId, $userId],
                );
                $this->ctx->db->run(
                    'UPDATE orga_tasks SET assignee_id = NULL WHERE team_id = ? AND assignee_id = ?',
                    [$teamId, $userId],
                );
            }
            foreach ($members as $userId) {
                $this->ctx->db->run(
                    'INSERT INTO orga_team_members (team_id, user_id, is_leader) VALUES (?, ?, ?)
                     ON DUPLICATE KEY UPDATE is_leader = VALUES(is_leader)',
                    [$teamId, $userId, in_array($userId, $leaders, true) ? 1 : 0],
                );
            }
        });

        $added = array_values(array_diff($members, array_keys($before)));
        $newLeaders = array_values(array_filter(
            $leaders,
            static fn (int $id): bool => ($before[$id] ?? false) === false,
        ));
        $this->notify(
            array_values(array_diff($added, $newLeaders)),
            sprintf('Du bist jetzt Mitglied im Orga-Team „%s“.', $team['name']),
            '/orga/teams/' . $teamId,
        );
        $this->notify(
            $newLeaders,
            sprintf('Du leitest jetzt das Orga-Team „%s“.', $team['name']),
            '/orga/teams/' . $teamId,
        );

        $this->ctx->audit->log(
            'Orga-Team: Mitglieder gespeichert',
            'info',
            sprintf('%s: %d Mitglieder, davon %d Leitung', $team['name'], count($members), count($leaders)),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Mitglieder und Leitung gespeichert.');
        $this->redirect($this->ctx->schoolUrl('/orga/verwaltung/teams/' . $teamId));
    }

    /** POST /{school}/orga/verwaltung/teams/{id}/loeschen */
    public function destroy(array $params): string
    {
        $access = $this->orgaManager($params);
        $team = $this->teamOr404($access, (int) $params['id']);
        $this->requireCsrf();

        // Anhänge des Team-Chats liegen als Dateien vor und gehen sonst verloren.
        $files = array_column($this->ctx->db->fetchAll(
            "SELECT attachment_file FROM orga_messages
             WHERE channel = 'team' AND team_id = ? AND attachment_file IS NOT NULL",
            [(int) $team['id']],
        ), 'attachment_file');

        $this->ctx->db->run(
            'DELETE FROM orga_teams WHERE id = ? AND edition_id = ?',
            [(int) $team['id'], $access->editionId()],
        );
        $this->deleteAttachments($files);

        $this->ctx->audit->log('Orga-Team gelöscht', 'warning', (string) $team['name'], $this->ctx->schoolId());
        $this->flash('success', 'Team „' . $team['name'] . '“ gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/orga/verwaltung'));
    }

    // ---------------------------------------------------------- Helfer

    /** @return array<string, mixed> */
    private function teamOr404(OrgaAccess $access, int $teamId): array
    {
        $team = $access->findTeam($teamId);
        if ($team === null) {
            throw new HttpException(404, 'Dieses Team gibt es nicht.');
        }

        return $team;
    }

    /** @return array{name: string, color: string, description: string|null, deadline: string|null} */
    private function readTeamForm(string $backPath): array
    {
        $name = $this->text('name', 100);
        if ($name === '') {
            $this->flash('error', 'Bitte gib dem Team einen Namen.');
            $this->redirect($this->ctx->schoolUrl($backPath));
        }

        $color = (string) ($_POST['color'] ?? '');
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color) !== 1) {
            $color = OrgaService::COLORS[0];
        }

        return [
            'name' => $name,
            'color' => strtolower($color),
            'description' => $this->text('description', 3000) ?: null,
            'deadline' => OrgaService::dateTimeOrNull($_POST['deadline'] ?? null),
        ];
    }
}
