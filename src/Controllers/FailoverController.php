<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\CancellationService;
use App\Services\Capacity;
use App\Services\Notifications;
use App\Services\Rebooking;

/**
 * Ausfall und Neuzuteilung: betroffene Schüler:innen eines Ausstellers
 * finden, auf freie Plätze umbuchen und benachrichtigen.
 *
 * Bei einem noch aktiven Aussteller wird zuerst über den zentralen
 * Absage-Ablauf (CancellationService) abgesagt; seine Buchungen werden
 * dadurch ruhend. Bei einem bereits abgesagten Aussteller werden nur die
 * noch ruhenden Buchungen neu zugeteilt. Wer keinen Platz bekommt, behält
 * seine ruhende Buchung.
 *
 * Bewusst zweistufig: erst Vorschau, dann Ausführung.
 */
final class FailoverController extends Controller
{
    /** GET /{school}/admin/ausfall */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANMELDUNGEN_SEHEN);
        $edition = $this->ctx->requireEdition();

        $exhibitors = $this->ctx->db->fetchAll(
            'SELECT e.id, e.name, e.active, e.pipeline_status,
                    (SELECT COUNT(*) FROM registrations r
                      WHERE r.exhibitor_id = e.id AND r.timeslot_id IS NOT NULL) AS betroffen,
                    (SELECT COUNT(*) FROM registrations_on_hold h
                      WHERE h.exhibitor_id = e.id AND h.timeslot_id IS NOT NULL) AS ruhend
             FROM exhibitors e
             WHERE e.edition_id = ?
             ORDER BY e.active DESC, ruhend DESC, e.name',
            [(int) $edition['id']],
        );

        return $this->render('pages/attendance/ausfall', [
            'title' => 'Ausfall & Neuzuteilung',
            'exhibitors' => $exhibitors,
            'plan' => null,
            'exhibitor' => null,
        ]);
    }

    /** GET /{school}/admin/ausfall/{id}: Vorschau der Umbuchung. */
    public function preview(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANMELDUNGEN_SEHEN);
        $edition = $this->ctx->requireEdition();

        $exhibitor = $this->loadExhibitor((int) $params['id']);
        $plan = $this->rebooking()->preview((int) $edition['id'], (int) $exhibitor['id']);

        return $this->render('pages/attendance/ausfall', [
            'title' => ((int) $exhibitor['active'] === 1 ? 'Ausfall: ' : 'Neu zuteilen: ') . (string) $exhibitor['name'],
            'exhibitors' => [],
            'plan' => $plan,
            'exhibitor' => $exhibitor,
            'canExecute' => $this->ctx->auth->can(Permissions::ANMELDUNGEN_ERSTELLEN, $this->ctx->schoolId())
                && $this->ctx->auth->can(Permissions::ANMELDUNGEN_LOESCHEN, $this->ctx->schoolId()),
        ]);
    }

    /** POST /{school}/admin/ausfall/{id}/umbuchen */
    public function execute(array $params): string
    {
        $this->requireSchool($params['school']);
        // Umbuchen heißt: Anmeldungen anlegen UND entfernen.
        $this->requirePermission(Permissions::ANMELDUNGEN_ERSTELLEN);
        $this->requirePermission(Permissions::ANMELDUNGEN_LOESCHEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();
        $schoolId = (int) $this->ctx->schoolId();

        $exhibitor = $this->loadExhibitor((int) $params['id']);
        $wasActive = (int) $exhibitor['active'] === 1;

        // Noch aktiv: erst absagen (Buchungen werden ruhend), dann neu zuteilen.
        // Die Schüler:innen informiert hier die Umbuchung selbst, nicht die Absage.
        if ($wasActive) {
            (new CancellationService($this->ctx))->execute(
                $exhibitor,
                $edition,
                $schoolId,
                'Ausfall am Messetag',
                'failover',
                $this->ctx->auth->id(),
                'cancelled',
                false,
            );
        }

        $result = $this->rebooking()->execute(
            (int) $edition['id'],
            (int) $exhibitor['id'],
            $schoolId,
            (string) $exhibitor['name'],
            $wasActive,
        );

        $this->ctx->audit->log(
            $wasActive ? 'Ausfall verarbeitet' : 'Ruhende Buchungen neu zugeteilt',
            'warning',
            sprintf(
                'Aussteller #%d „%s“: %d umgebucht, %d ohne Ersatz (Buchung bleibt ruhend)',
                (int) $exhibitor['id'],
                (string) $exhibitor['name'],
                $result['moved'],
                $result['unplaced'],
            ),
            $schoolId,
        );
        $this->flash('success', sprintf(
            '%d Schüler:innen umgebucht und in der App benachrichtigt.',
            $result['moved'],
        ));
        if ($result['unplaced'] > 0) {
            $this->flash('warning', sprintf(
                'Für %d Buchung(en) war nirgends Platz. Sie bleiben ruhend und lassen sich später neu zuteilen, '
                . 'zum Beispiel über diese Seite oder die manuelle Zuteilung.',
                $result['unplaced'],
            ));
        }
        $this->redirect($this->ctx->schoolUrl($wasActive ? '/admin/leitstand' : '/admin/ausfall'));
    }

    // ---------- Helfer ----------

    private function rebooking(): Rebooking
    {
        return new Rebooking(
            $this->ctx->db,
            new Capacity($this->ctx->db),
            new Notifications($this->ctx->db),
        );
    }

    /** @return array<string, mixed> */
    private function loadExhibitor(int $id): array
    {
        $edition = $this->ctx->requireEdition();
        $exhibitor = $this->ctx->db->fetchOne(
            'SELECT * FROM exhibitors WHERE id = ? AND edition_id = ?',
            [$id, (int) $edition['id']],
        );
        if ($exhibitor === null) {
            throw new HttpException(404, 'Diesen Aussteller gibt es hier nicht.');
        }

        return $exhibitor;
    }
}
