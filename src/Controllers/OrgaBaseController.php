<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\Notifications;
use App\Services\OrgaAccess;
use App\Services\OrgaService;
use App\Services\Uploads;

/**
 * Gemeinsame Guards und Lader der Orga-Tool-Controller.
 *
 * Jede Aktion beginnt mit orga(): Schule, Anmeldung, Rolle und aktive
 * Edition. Alles Weitere (Team sehen, leiten, Chat schreiben …) entscheidet
 * OrgaAccess; Verstöße enden mit HTTP 403.
 */
abstract class OrgaBaseController extends Controller
{
    protected function orga(array $params): OrgaAccess
    {
        $this->requireSchool($params['school']);
        if (!in_array($this->ctx->auth->role(), OrgaAccess::ROLES, true)) {
            throw new HttpException(403, 'Das Orga-Tool steht nur dem Orga-Team und der Verwaltung offen.');
        }
        $edition = $this->ctx->requireEdition();

        return new OrgaAccess($this->ctx->db, $this->ctx->auth, (int) $this->ctx->schoolId(), (int) $edition['id']);
    }

    /** Nur Verwaltung (Teams anlegen, Zuordnungen, Abschlusstermin). */
    protected function orgaManager(array $params): OrgaAccess
    {
        $access = $this->orga($params);
        if (!$access->manages()) {
            throw new HttpException(403);
        }

        return $access;
    }

    protected function service(): OrgaService
    {
        return new OrgaService($this->ctx->db);
    }

    /** @return array<string, mixed> */
    protected function viewableTeam(OrgaAccess $access, int $teamId): array
    {
        $team = $access->findTeam($teamId);
        if ($team === null) {
            throw new HttpException(404, 'Dieses Team gibt es nicht.');
        }
        if (!$access->canViewTeam($teamId)) {
            throw new HttpException(403, 'Du gehörst diesem Team nicht an.');
        }

        return $team;
    }

    /** @return array<string, mixed> */
    protected function ledTeam(OrgaAccess $access, int $teamId): array
    {
        $team = $this->viewableTeam($access, $teamId);
        if (!$access->canLeadTeam($teamId)) {
            throw new HttpException(403, 'Das darf nur die Teamleitung.');
        }

        return $team;
    }

    /**
     * Organisationspunkt dieser Edition, den der Nutzer sehen darf.
     *
     * @return array<string, mixed>
     */
    protected function viewableTask(OrgaAccess $access, int $taskId): array
    {
        $task = $this->ctx->db->fetchOne(
            'SELECT t.* FROM orga_tasks t
             JOIN orga_teams tm ON tm.id = t.team_id
             WHERE t.id = ? AND tm.edition_id = ?',
            [$taskId, $access->editionId()],
        );
        if ($task === null) {
            throw new HttpException(404, 'Diesen Organisationspunkt gibt es nicht.');
        }
        if (!$access->canViewTeam((int) $task['team_id'])) {
            throw new HttpException(403);
        }

        return $task;
    }

    /** @return array<string, mixed> Ziel dieser Edition, dessen Team der Nutzer leitet. */
    protected function ledGoal(OrgaAccess $access, int $goalId): array
    {
        $goal = $this->ctx->db->fetchOne(
            'SELECT g.* FROM orga_goals g
             JOIN orga_teams tm ON tm.id = g.team_id
             WHERE g.id = ? AND tm.edition_id = ?',
            [$goalId, $access->editionId()],
        );
        if ($goal === null) {
            throw new HttpException(404, 'Dieses Ziel gibt es nicht.');
        }
        $this->ledTeam($access, (int) $goal['team_id']);

        return $goal;
    }

    /**
     * Raum aus dem URL-Segment, den der Nutzer lesen darf.
     *
     * @return array{channel: string, team_id: int|null, key: string, slug: string, title: string, color: string|null}
     */
    protected function readableSpace(OrgaAccess $access, string $slug): array
    {
        $space = $access->space($slug);
        if ($space === null) {
            throw new HttpException(404, 'Diesen Bereich gibt es nicht.');
        }
        if (!$access->canReadSpace($space)) {
            throw new HttpException(403, 'Du hast keinen Zugriff auf diesen Bereich.');
        }

        return $space;
    }

    /** Benachrichtigt andere Personen (nie sich selbst). */
    protected function notify(array $userIds, string $message, string $path): void
    {
        $self = (int) $this->ctx->auth->id();
        $targets = array_values(array_filter(
            array_map('intval', $userIds),
            static fn (int $id): bool => $id !== $self,
        ));
        if ($targets === []) {
            return;
        }

        (new Notifications($this->ctx->db))->sendMany(
            $targets,
            $this->ctx->schoolId(),
            $message,
            'info',
            null,
            $this->ctx->schoolUrl($path),
        );
    }

    /** Unterordner der Chat-Anhänge (außerhalb des Webroots). */
    protected const ATTACHMENT_DIR = 'orga';

    protected function uploads(): Uploads
    {
        return new Uploads($this->ctx->config['uploads']['dir']);
    }

    /** @param list<mixed> $files Dateinamen gelöschter Anhänge */
    protected function deleteAttachments(array $files): void
    {
        $uploads = $this->uploads();
        foreach ($files as $file) {
            if (is_string($file) && $file !== '') {
                $uploads->delete(self::ATTACHMENT_DIR, $file);
            }
        }
    }

    /** Kurzer Pflichttext aus dem Formular (getrimmt, gekürzt). */
    protected function text(string $key, int $max): string
    {
        return mb_substr(trim((string) ($_POST[$key] ?? '')), 0, $max);
    }

    /** Zurück zur aufrufenden Seite, sofern sie innerhalb der Schule liegt. */
    protected function back(string $fallbackPath): never
    {
        $target = (string) ($_POST['_back'] ?? '');
        $prefix = $this->ctx->schoolUrl('/orga');
        if ($target !== '' && str_starts_with($target, $prefix)
            && !str_contains(substr($target, 1), '//') && !str_contains($target, '\\')) {
            $this->redirect($target);
        }
        $this->redirect($this->ctx->schoolUrl($fallbackPath));
    }
}
