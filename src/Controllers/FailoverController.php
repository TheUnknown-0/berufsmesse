<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\Capacity;
use App\Services\Notifications;
use App\Services\Rebooking;

/**
 * Kurzfristiger Ausfall eines Ausstellers am Messetag: betroffene
 * Schüler:innen finden, auf freie Plätze umbuchen und benachrichtigen.
 *
 * Bewusst zweistufig — erst Vorschau, dann Ausführung: Die Umbuchung
 * verschiebt Anmeldungen quer über den Tag und lässt sich nicht mit einem
 * Klick zurücknehmen.
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
            'SELECT e.id, e.name, e.active,
                    (SELECT COUNT(*) FROM registrations r
                      WHERE r.exhibitor_id = e.id AND r.timeslot_id IS NOT NULL) AS betroffen
             FROM exhibitors e
             WHERE e.edition_id = ?
             ORDER BY e.active DESC, e.name',
            [(int) $edition['id']],
        );

        return $this->render('pages/attendance/ausfall', [
            'title' => 'Ausfall melden',
            'exhibitors' => $exhibitors,
            'plan' => null,
            'exhibitor' => null,
        ]);
    }

    /** GET /{school}/admin/ausfall/{id} — Vorschau der Umbuchung. */
    public function preview(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::ANMELDUNGEN_SEHEN);
        $edition = $this->ctx->requireEdition();
        $exhibitor = $this->loadExhibitor((int) $params['id']);

        $plan = $this->rebooking()->preview((int) $edition['id'], (int) $exhibitor['id']);

        return $this->render('pages/attendance/ausfall', [
            'title' => 'Ausfall: ' . (string) $exhibitor['name'],
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
        $exhibitor = $this->loadExhibitor((int) $params['id']);

        $result = $this->rebooking()->execute(
            (int) $edition['id'],
            (int) $exhibitor['id'],
            (int) $this->ctx->schoolId(),
            (string) $exhibitor['name'],
        );

        $this->ctx->audit->log(
            'Ausfall verarbeitet',
            'warning',
            sprintf(
                'Aussteller #%d „%s“ ausgefallen: %d Anmeldungen umgebucht, %d ohne Ersatz entfernt',
                (int) $exhibitor['id'],
                (string) $exhibitor['name'],
                $result['moved'],
                $result['unplaced'],
            ),
            $this->ctx->schoolId(),
        );

        $this->flash('success', sprintf(
            '%d Anmeldungen umgebucht, %d ohne Ersatz entfernt. Alle Betroffenen wurden in der App benachrichtigt.',
            $result['moved'],
            $result['unplaced'],
        ));
        if ($result['unplaced'] > 0) {
            $this->flash('warning', 'Für ' . $result['unplaced'] . ' Anmeldung(en) war nirgends Platz — diese Schüler:innen brauchen eine Ansage vor Ort.');
        }
        $this->redirect($this->ctx->schoolUrl('/admin/leitstand'));
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
