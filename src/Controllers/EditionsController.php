<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\EditionCloner;

/**
 * Messe-Editionen im Global-Admin (/global-admin/editionen).
 *
 * Pro Schule darf höchstens eine Edition den Status 'active' haben — beim
 * Aktivieren wird eine bestehende aktive Edition derselben Schule in einer
 * Transaktion archiviert.
 */
final class EditionsController extends Controller
{
    private const STATUSES = ['draft', 'active', 'archived'];

    /** GET /global-admin/editionen */
    public function index(array $params): string
    {
        $this->requireAdmin();

        $schools = $this->ctx->db->fetchAll('SELECT * FROM schools ORDER BY name');
        $editions = $this->ctx->db->fetchAll(
            'SELECT me.*,
                    (SELECT COUNT(*) FROM timeslots t WHERE t.edition_id = me.id) AS timeslot_count,
                    (SELECT COUNT(*) FROM exhibitors e WHERE e.edition_id = me.id) AS exhibitor_count,
                    (SELECT COUNT(*) FROM registrations r WHERE r.edition_id = me.id) AS registration_count
             FROM messe_editions me
             ORDER BY me.year DESC, me.id DESC',
        );

        $bySchool = [];
        foreach ($editions as $edition) {
            $bySchool[(int) $edition['school_id']][] = $edition;
        }

        return $this->render('pages/global/editionen', [
            'title' => 'Messe-Editionen',
            'schools' => $schools,
            'editionsBySchool' => $bySchool,
            'statuses' => self::STATUSES,
            'old' => $this->ctx->session->pullOldInput(),
        ]);
    }

    /** POST /global-admin/editionen */
    public function store(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $back = $this->ctx->url('/global-admin/editionen');
        $this->ctx->session->rememberInput($_POST);

        $schoolId = (int) ($_POST['school_id'] ?? 0);
        $school = $this->ctx->db->fetchOne('SELECT * FROM schools WHERE id = ?', [$schoolId]);
        if ($school === null) {
            $this->flash('error', 'Bitte wähle eine Schule aus.');
            $this->redirect($back);
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $year = (int) ($_POST['year'] ?? 0);
        if ($name === '') {
            $this->flash('error', 'Bitte gib eine Bezeichnung für die Edition an.');
            $this->redirect($back);
        }
        if ($year < 2000 || $year > 2100) {
            $this->flash('error', 'Bitte gib ein gültiges Jahr zwischen 2000 und 2100 an.');
            $this->redirect($back);
        }

        $status = in_array((string) ($_POST['status'] ?? 'draft'), self::STATUSES, true)
            ? (string) $_POST['status']
            : 'draft';
        $copySlots = isset($_POST['copy_timeslots']);

        $newId = $this->ctx->db->transaction(function () use ($schoolId, $name, $year, $status, $copySlots): int {
            if ($status === 'active') {
                $this->ctx->db->run(
                    'UPDATE messe_editions SET status = \'archived\' WHERE school_id = ? AND status = \'active\'',
                    [$schoolId],
                );
            }

            $this->ctx->db->run(
                'INSERT INTO messe_editions (school_id, name, year, status, max_registrations_per_student)
                 VALUES (?, ?, ?, ?, 3)',
                [$schoolId, mb_substr($name, 0, 200), $year, $status],
            );
            $editionId = $this->ctx->db->lastInsertId();

            if ($copySlots) {
                $sourceId = $this->ctx->db->fetchValue(
                    'SELECT me.id FROM messe_editions me
                     WHERE me.school_id = ? AND me.id <> ?
                       AND EXISTS (SELECT 1 FROM timeslots t WHERE t.edition_id = me.id)
                     ORDER BY me.year DESC, me.id DESC LIMIT 1',
                    [$schoolId, $editionId],
                );
                if ($sourceId !== null) {
                    $this->ctx->db->run(
                        'INSERT INTO timeslots (edition_id, slot_number, slot_name, start_time, end_time, is_managed, is_break)
                         SELECT ?, slot_number, slot_name, start_time, end_time, is_managed, is_break
                         FROM timeslots WHERE edition_id = ?',
                        [$editionId, (int) $sourceId],
                    );
                }
            }

            return $editionId;
        });

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'Messe-Edition erstellt',
            'info',
            $name . ' (' . $year . ', Status ' . $status . ')' . ($copySlots ? ' — Zeitslots übernommen' : ''),
            $schoolId,
        );
        $this->flash('success', 'Edition angelegt (ID ' . $newId . ').');
        $this->redirect($back);
    }

    /**
     * POST /global-admin/editionen/{id}/klonen
     *
     * Legt eine neue Edition an und übernimmt die Struktur der gewählten
     * Quell-Edition (Zeitraster, Räume, Aussteller …). Durchführungsdaten
     * — Anmeldungen, Anwesenheiten, Feedback-Antworten — bleiben außen vor;
     * die Details stehen in Services\EditionCloner.
     */
    public function duplicate(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();
        $back = $this->ctx->url('/global-admin/editionen');

        $source = $this->ctx->db->fetchOne(
            'SELECT * FROM messe_editions WHERE id = ?',
            [(int) $params['id']],
        );
        if ($source === null) {
            throw new HttpException(404, 'Diese Edition existiert nicht.');
        }
        $schoolId = (int) $source['school_id'];

        $name = trim((string) ($_POST['name'] ?? ''));
        $year = (int) ($_POST['year'] ?? 0);
        if ($name === '') {
            $this->flash('error', 'Bitte gib eine Bezeichnung für die neue Edition an.');
            $this->redirect($back);
        }
        if ($year < 2000 || $year > 2100) {
            $this->flash('error', 'Bitte gib ein gültiges Jahr zwischen 2000 und 2100 an.');
            $this->redirect($back);
        }

        $parts = [];
        foreach (array_keys(EditionCloner::PARTS) as $key) {
            if (isset($_POST['parts'][$key])) {
                $parts[] = $key;
            }
        }
        // Zugänge und Orga-Zuordnungen hängen an den Ausstellern.
        if (!in_array('exhibitors', $parts, true)) {
            $parts = array_values(array_diff($parts, ['exhibitor_users', 'orga_team']));
        }

        [$newId, $stats] = $this->ctx->db->transaction(
            function () use ($schoolId, $name, $year, $source, $parts): array {
                $this->ctx->db->run(
                    "INSERT INTO messe_editions
                        (school_id, name, year, status, max_registrations_per_student)
                     VALUES (?, ?, ?, 'draft', ?)",
                    [$schoolId, mb_substr($name, 0, 200), $year, (int) $source['max_registrations_per_student']],
                );
                $editionId = $this->ctx->db->lastInsertId();

                $cloner = new EditionCloner($this->ctx->db);

                return [$editionId, $cloner->copy((int) $source['id'], $editionId, $parts)];
            },
        );

        $summary = [];
        foreach ($stats as $key => $count) {
            $summary[] = $count . ' ' . (EditionCloner::PARTS[$key] ?? $key);
        }

        $this->ctx->audit->log(
            'Messe-Edition geklont',
            'info',
            sprintf(
                '„%s“ (#%d) → „%s“ (#%d, %d): %s',
                (string) $source['name'],
                (int) $source['id'],
                $name,
                $newId,
                $year,
                $summary === [] ? 'nur Rahmendaten' : implode(', ', $summary),
            ),
            $schoolId,
        );
        $this->flash(
            'success',
            sprintf(
                'Edition „%s“ angelegt (Entwurf). Übernommen: %s.',
                $name,
                $summary === [] ? 'nichts — nur die Rahmendaten' : implode(', ', $summary),
            ),
        );
        if (in_array('exhibitors', $parts, true)) {
            $this->flash(
                'info',
                'Die übernommenen Aussteller stehen auf „Lead“ und sind noch nicht sichtbar — bestätige sie in der Aussteller-Pipeline, sobald sie zugesagt haben.',
            );
        }
        $this->redirect($back);
    }

    /** POST /global-admin/editionen/{id} */
    public function update(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $back = $this->ctx->url('/global-admin/editionen');
        $edition = $this->requireEditionRow((int) $params['id']);

        $name = trim((string) ($_POST['name'] ?? ''));
        $year = (int) ($_POST['year'] ?? 0);
        if ($name === '' || $year < 2000 || $year > 2100) {
            $this->flash('error', 'Bitte gib Bezeichnung und ein gültiges Jahr an.');
            $this->redirect($back);
        }

        $max = (int) ($_POST['max_registrations_per_student'] ?? 3);
        $max = max(1, min(20, $max));

        $this->ctx->db->run(
            'UPDATE messe_editions
             SET name = ?, year = ?, event_date = ?, registration_start = ?, registration_end = ?,
                 max_registrations_per_student = ?
             WHERE id = ?',
            [
                mb_substr($name, 0, 200),
                $year,
                $this->dateValue('event_date', 'Y-m-d'),
                $this->dateValue('registration_start', 'Y-m-d H:i:s'),
                $this->dateValue('registration_end', 'Y-m-d H:i:s'),
                $max,
                (int) $edition['id'],
            ],
        );

        $this->ctx->audit->log(
            'Messe-Edition bearbeitet',
            'info',
            $name . ' (' . $year . ')',
            (int) $edition['school_id'],
        );
        $this->flash('success', 'Edition gespeichert.');
        $this->redirect($back);
    }

    /** POST /global-admin/editionen/{id}/status */
    public function status(array $params): string
    {
        $this->requireAdmin();
        $this->requireCsrf();

        $back = $this->ctx->url('/global-admin/editionen');
        $edition = $this->requireEditionRow((int) $params['id']);

        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, self::STATUSES, true)) {
            $this->flash('error', 'Unbekannter Status.');
            $this->redirect($back);
        }

        $archived = $this->ctx->db->transaction(function () use ($edition, $status): int {
            $count = 0;
            if ($status === 'active') {
                $count = $this->ctx->db->run(
                    'UPDATE messe_editions SET status = \'archived\'
                     WHERE school_id = ? AND status = \'active\' AND id <> ?',
                    [(int) $edition['school_id'], (int) $edition['id']],
                )->rowCount();
            }

            $this->ctx->db->run(
                'UPDATE messe_editions SET status = ? WHERE id = ?',
                [$status, (int) $edition['id']],
            );

            return $count;
        });

        $this->ctx->audit->log(
            'Status einer Messe-Edition geändert',
            'warning',
            (string) $edition['name'] . ' → ' . $status . ($archived > 0 ? ' (' . $archived . ' Edition archiviert)' : ''),
            (int) $edition['school_id'],
        );
        $this->flash('success', $archived > 0
            ? 'Edition aktiviert. Die bisher aktive Edition wurde archiviert.'
            : 'Status gespeichert.');
        $this->redirect($back);
    }

    // ---------- Helfer ----------

    private function requireEditionRow(int $id): array
    {
        $edition = $this->ctx->db->fetchOne('SELECT * FROM messe_editions WHERE id = ?', [$id]);
        if ($edition === null) {
            throw new HttpException(404, 'Diese Edition existiert nicht.');
        }

        return $edition;
    }

    /** Datums-/Zeitfeld aus dem Formular in DB-Format, sonst null. */
    private function dateValue(string $key, string $format): ?string
    {
        $raw = trim((string) ($_POST[$key] ?? ''));
        if ($raw === '') {
            return null;
        }

        try {
            return (new \DateTimeImmutable($raw))->format($format);
        } catch (\Exception) {
            return null;
        }
    }
}
