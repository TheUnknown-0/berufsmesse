<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\ExhibitorHistory;

/**
 * Akquise-Pipeline der Aussteller: Wer wurde angefragt, wer hat zugesagt,
 * bei wem steht eine Wiedervorlage an — mit Gesprächsverlauf je Unternehmen.
 *
 * Der Pipeline-Status steuert die Sichtbarkeit für Schüler:innen nicht
 * direkt; beide werden aber gekoppelt gepflegt: „zugesagt“ schaltet den
 * Aussteller sichtbar, „abgesagt“ und „storniert“ blenden ihn aus. Damit
 * gibt es genau einen Ort, an dem der Stand gepflegt wird.
 */
final class ExhibitorPipelineController extends Controller
{
    /** Stufen der Pipeline in Bearbeitungsreihenfolge. */
    public const STAGES = [
        'lead' => 'Vorgemerkt',
        'contacted' => 'Angefragt',
        'confirmed' => 'Zugesagt',
        'declined' => 'Abgesagt',
        'cancelled' => 'Storniert',
    ];

    /** Sichtbarkeit, die zu einer Stufe gehört. */
    private const VISIBILITY = [
        'lead' => 0,
        'contacted' => 0,
        'confirmed' => 1,
        'declined' => 0,
        'cancelled' => 0,
    ];

    /** GET /{school}/admin/aussteller/pipeline */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_SEHEN);
        $edition = $this->ctx->requireEdition();
        $schoolId = (int) $this->ctx->schoolId();

        $onlyDue = isset($_GET['faellig']);

        $exhibitors = $this->ctx->db->fetchAll(
            'SELECT e.*,
                    (SELECT COUNT(*) FROM exhibitor_notes n WHERE n.exhibitor_id = e.id) AS note_count,
                    (SELECT n.body FROM exhibitor_notes n WHERE n.exhibitor_id = e.id
                      ORDER BY n.created_at DESC, n.id DESC LIMIT 1) AS last_note,
                    (SELECT n.created_at FROM exhibitor_notes n WHERE n.exhibitor_id = e.id
                      ORDER BY n.created_at DESC, n.id DESC LIMIT 1) AS last_note_at
             FROM exhibitors e
             WHERE e.edition_id = ?
             ORDER BY e.name',
            [(int) $edition['id']],
        );

        $history = new ExhibitorHistory($this->ctx->db);
        $summaries = $history->summaries(
            $schoolId,
            array_map(static fn (array $row): int => (int) $row['id'], $exhibitors),
        );

        $today = date('Y-m-d');
        $byStage = array_fill_keys(array_keys(self::STAGES), []);
        $dueCount = 0;

        foreach ($exhibitors as $exhibitor) {
            $exhibitorId = (int) $exhibitor['id'];
            $exhibitor['summary'] = $summaries[$exhibitorId] ?? ['years' => 1, 'last_year' => null, 'last_attendances' => null];
            $exhibitor['is_due'] = $exhibitor['follow_up_at'] !== null
                && (string) $exhibitor['follow_up_at'] <= $today;
            if ($exhibitor['is_due']) {
                $dueCount++;
            }
            if ($onlyDue && !$exhibitor['is_due']) {
                continue;
            }

            $stage = (string) $exhibitor['pipeline_status'];
            $byStage[isset(self::STAGES[$stage]) ? $stage : 'lead'][] = $exhibitor;
        }

        return $this->render('pages/exhibitors-admin/pipeline', [
            'title' => 'Aussteller-Pipeline',
            'byStage' => $byStage,
            'stages' => self::STAGES,
            'total' => count($exhibitors),
            'dueCount' => $dueCount,
            'onlyDue' => $onlyDue,
            'canEdit' => $this->ctx->auth->can(Permissions::AUSSTELLER_BEARBEITEN, $schoolId),
        ]);
    }

    /** POST /{school}/admin/aussteller/{id}/pipeline */
    public function updateStage(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_BEARBEITEN);
        $this->requireCsrf();
        $exhibitor = $this->loadExhibitor((int) $params['id']);
        $exhibitorId = (int) $exhibitor['id'];
        $back = $this->ctx->schoolUrl('/admin/aussteller/pipeline');

        $stage = (string) ($_POST['pipeline_status'] ?? '');
        if (!isset(self::STAGES[$stage])) {
            $this->flash('error', 'Unbekannter Pipeline-Status.');
            $this->redirect($back);
        }

        $followUp = trim((string) ($_POST['follow_up_at'] ?? ''));
        if ($followUp !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $followUp) !== 1) {
            $this->flash('error', 'Das Wiedervorlage-Datum ist ungültig.');
            $this->redirect($back);
        }
        $note = trim((string) ($_POST['note'] ?? ''));
        $previousStage = (string) $exhibitor['pipeline_status'];

        $this->ctx->db->transaction(function () use ($exhibitorId, $stage, $followUp, $note, $previousStage): void {
            $this->ctx->db->run(
                'UPDATE exhibitors SET pipeline_status = ?, follow_up_at = ?, active = ? WHERE id = ?',
                [$stage, $followUp !== '' ? $followUp : null, self::VISIBILITY[$stage], $exhibitorId],
            );

            // Statuswechsel und Notiz landen als ein Eintrag im Verlauf.
            if ($note !== '' || $stage !== $previousStage) {
                $this->ctx->db->run(
                    'INSERT INTO exhibitor_notes (exhibitor_id, user_id, body, status_from, status_to)
                     VALUES (?, ?, ?, ?, ?)',
                    [
                        $exhibitorId,
                        $this->ctx->auth->id(),
                        $note !== '' ? mb_substr($note, 0, 2000) : 'Status geändert.',
                        $stage !== $previousStage ? $previousStage : null,
                        $stage !== $previousStage ? $stage : null,
                    ],
                );
            }
        });

        $this->ctx->audit->log(
            'Aussteller-Pipeline aktualisiert',
            'info',
            sprintf(
                'Aussteller #%d „%s“: %s → %s%s',
                $exhibitorId,
                (string) $exhibitor['name'],
                self::STAGES[$previousStage] ?? $previousStage,
                self::STAGES[$stage],
                $followUp !== '' ? ', Wiedervorlage ' . $followUp : '',
            ),
            $this->ctx->schoolId(),
        );

        $message = 'Status gespeichert: ' . self::STAGES[$stage] . '.';
        if ($stage !== $previousStage) {
            $message .= self::VISIBILITY[$stage] === 1
                ? ' Der Aussteller ist jetzt für Schüler:innen sichtbar.'
                : ' Der Aussteller ist für Schüler:innen ausgeblendet.';
        }
        $this->flash('success', $message);
        $this->redirect($back);
    }

    /** POST /{school}/admin/aussteller/{id}/notiz */
    public function addNote(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_BEARBEITEN);
        $this->requireCsrf();
        $exhibitor = $this->loadExhibitor((int) $params['id']);
        $back = (string) ($_POST['zurueck'] ?? '') === 'profil'
            ? $this->ctx->schoolUrl('/admin/aussteller/' . (int) $exhibitor['id'])
            : $this->ctx->schoolUrl('/admin/aussteller/pipeline');

        $note = trim((string) ($_POST['note'] ?? ''));
        if ($note === '') {
            $this->flash('error', 'Die Notiz darf nicht leer sein.');
            $this->redirect($back);
        }

        $this->ctx->db->run(
            'INSERT INTO exhibitor_notes (exhibitor_id, user_id, body) VALUES (?, ?, ?)',
            [(int) $exhibitor['id'], $this->ctx->auth->id(), mb_substr($note, 0, 2000)],
        );

        $this->flash('success', 'Notiz gespeichert.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/notiz/{id}/loeschen */
    public function deleteNote(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::AUSSTELLER_BEARBEITEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();

        // Schul-Isolation: Notiz nur über einen Aussteller der aktiven Edition.
        $note = $this->ctx->db->fetchOne(
            'SELECT n.id, n.exhibitor_id
             FROM exhibitor_notes n
             JOIN exhibitors e ON e.id = n.exhibitor_id
             WHERE n.id = ? AND e.edition_id = ?',
            [(int) $params['id'], (int) $edition['id']],
        );
        if ($note === null) {
            throw new HttpException(404, 'Diese Notiz existiert nicht.');
        }

        $this->ctx->db->run('DELETE FROM exhibitor_notes WHERE id = ?', [(int) $note['id']]);
        $this->flash('success', 'Notiz gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/admin/aussteller/' . (int) $note['exhibitor_id']));
    }

    /**
     * Lädt einen Aussteller strikt innerhalb der aktiven Edition.
     *
     * @return array<string, mixed>
     */
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
