<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Permissions;
use App\Services\Exports;
use App\Services\FeedbackService;

/**
 * Feedback-Bögen verwalten: anlegen, Fragen bauen, freischalten, auswerten.
 *
 * Zugriff läuft ausschließlich über die granularen FEEDBACK_*-Rechte —
 * damit können Orga-Konten Bögen betreuen, ohne weitere Admin-Rechte zu
 * bekommen; school_admin und admin haben sie implizit.
 */
final class FeedbackAdminController extends Controller
{
    /** GET /{school}/admin/feedback */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_SEHEN);
        $edition = $this->ctx->requireEdition();
        $service = new FeedbackService($this->ctx->db);

        $forms = $this->ctx->db->fetchAll(
            'SELECT f.*,
                    (SELECT COUNT(*) FROM feedback_questions q WHERE q.form_id = f.id) AS question_count,
                    (SELECT COUNT(*) FROM feedback_responses r WHERE r.form_id = f.id) AS response_count,
                    u.firstname, u.lastname
             FROM feedback_forms f
             LEFT JOIN users u ON u.id = f.created_by
             WHERE f.edition_id = ?
             ORDER BY f.created_at DESC',
            [(int) $edition['id']],
        );

        return $this->render('pages/feedback-admin/index', [
            'title' => 'Feedback',
            'forms' => $forms,
            'service' => $service,
            'canCreate' => $this->ctx->auth->can(Permissions::FEEDBACK_ERSTELLEN, $this->ctx->schoolId()),
            'canEdit' => $this->ctx->auth->can(Permissions::FEEDBACK_BEARBEITEN, $this->ctx->schoolId()),
            'canDelete' => $this->ctx->auth->can(Permissions::FEEDBACK_LOESCHEN, $this->ctx->schoolId()),
            'canRelease' => $this->ctx->auth->can(Permissions::FEEDBACK_FREISCHALTEN, $this->ctx->schoolId()),
            'canEvaluate' => $this->ctx->auth->can(Permissions::FEEDBACK_AUSWERTEN, $this->ctx->schoolId()),
        ]);
    }

    /** GET /{school}/admin/feedback/neu */
    public function create(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_ERSTELLEN);
        $edition = $this->ctx->requireEdition();

        return $this->render('pages/feedback-admin/form', [
            'title' => 'Neuer Feedback-Bogen',
            'form' => null,
            'questions' => [],
            'responseCount' => 0,
            'old' => $this->ctx->session->pullOldInput(),
            'edition' => $edition,
            'action' => $this->ctx->schoolUrl('/admin/feedback/neu'),
            'pageScripts' => ['feedback-builder.js'],
        ]);
    }

    /** POST /{school}/admin/feedback/neu */
    public function store(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_ERSTELLEN);
        $this->requireCsrf();
        $edition = $this->ctx->requireEdition();
        $back = $this->ctx->schoolUrl('/admin/feedback/neu');

        $data = $this->readForm();
        $this->ctx->session->rememberInput($_POST);

        if ($error = $this->validate($data)) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $questions = $this->readQuestions();
        if ($error = $this->validateQuestions($questions)) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $formId = $this->ctx->db->transaction(function () use ($data, $edition, $questions): int {
            $this->ctx->db->run(
                'INSERT INTO feedback_forms
                    (edition_id, title, description, status, opens_at, closes_at, is_anonymous,
                     audience_students, audience_teachers, audience_exhibitors, thank_you_text, created_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    (int) $edition['id'],
                    $data['title'],
                    $data['description'],
                    $data['status'],
                    $data['opens_at'],
                    $data['closes_at'],
                    $data['is_anonymous'],
                    $data['audience_students'],
                    $data['audience_teachers'],
                    $data['audience_exhibitors'],
                    $data['thank_you_text'],
                    $this->ctx->auth->id(),
                ],
            );
            $newId = $this->ctx->db->lastInsertId();
            $this->writeQuestions($newId, $questions);

            return $newId;
        });

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'Feedback-Bogen erstellt',
            'info',
            sprintf('Bogen #%d "%s" mit %d Frage(n), Status: %s', $formId, $data['title'], count($questions), $data['status']),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Der Feedback-Bogen wurde angelegt.');
        $this->redirect($this->ctx->schoolUrl('/admin/feedback/' . $formId . '/bearbeiten'));
    }

    /** GET /{school}/admin/feedback/{id}/bearbeiten */
    public function edit(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_BEARBEITEN);
        $form = $this->loadForm((int) $params['id']);
        $service = new FeedbackService($this->ctx->db);

        return $this->render('pages/feedback-admin/form', [
            'title' => 'Feedback-Bogen bearbeiten',
            'form' => $form,
            'questions' => $service->questions((int) $form['id']),
            'responseCount' => $service->responseCount((int) $form['id']),
            'old' => $this->ctx->session->pullOldInput(),
            'edition' => $this->ctx->requireEdition(),
            'action' => $this->ctx->schoolUrl('/admin/feedback/' . (int) $form['id'] . '/bearbeiten'),
            'pageScripts' => ['feedback-builder.js'],
        ]);
    }

    /** POST /{school}/admin/feedback/{id}/bearbeiten */
    public function update(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_BEARBEITEN);
        $this->requireCsrf();
        $form = $this->loadForm((int) $params['id']);
        $formId = (int) $form['id'];
        $back = $this->ctx->schoolUrl('/admin/feedback/' . $formId . '/bearbeiten');

        $data = $this->readForm();
        $this->ctx->session->rememberInput($_POST);

        if ($error = $this->validate($data)) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        // Freischalten ist ein eigenes Recht: ohne dieses bleibt der bisherige
        // Status stehen, alles andere lässt sich trotzdem bearbeiten.
        if (!$this->ctx->auth->can(Permissions::FEEDBACK_FREISCHALTEN, $this->ctx->schoolId())) {
            $data['status'] = (string) $form['status'];
        }

        $questions = $this->readQuestions();
        if ($error = $this->validateQuestions($questions)) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $this->ctx->db->transaction(function () use ($data, $formId, $questions): void {
            $this->ctx->db->run(
                'UPDATE feedback_forms
                    SET title = ?, description = ?, status = ?, opens_at = ?, closes_at = ?,
                        is_anonymous = ?, audience_students = ?, audience_teachers = ?,
                        audience_exhibitors = ?, thank_you_text = ?
                  WHERE id = ?',
                [
                    $data['title'],
                    $data['description'],
                    $data['status'],
                    $data['opens_at'],
                    $data['closes_at'],
                    $data['is_anonymous'],
                    $data['audience_students'],
                    $data['audience_teachers'],
                    $data['audience_exhibitors'],
                    $data['thank_you_text'],
                    $formId,
                ],
            );
            $this->writeQuestions($formId, $questions);
        });

        $this->ctx->session->pullOldInput();
        $this->ctx->audit->log(
            'Feedback-Bogen bearbeitet',
            'info',
            sprintf('Bogen #%d "%s" — %d Frage(n), Status: %s', $formId, $data['title'], count($questions), $data['status']),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Die Änderungen wurden gespeichert.');
        $this->redirect($back);
    }

    /** POST /{school}/admin/feedback/{id}/status — Bogen freischalten/schließen. */
    public function setStatus(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_FREISCHALTEN);
        $this->requireCsrf();
        $form = $this->loadForm((int) $params['id']);
        $formId = (int) $form['id'];
        $list = $this->ctx->schoolUrl('/admin/feedback');

        $status = (string) ($_POST['status'] ?? '');
        if (!in_array($status, ['draft', 'open', 'closed'], true)) {
            $this->flash('error', 'Unbekannter Status.');
            $this->redirect($list);
        }

        $questionCount = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM feedback_questions WHERE form_id = ?',
            [$formId],
        );
        if ($status === 'open' && $questionCount === 0) {
            $this->flash('error', 'Ein Bogen ohne Fragen kann nicht freigeschaltet werden.');
            $this->redirect($list);
        }
        if ($status === 'open' && $this->audienceOf($form) === []) {
            $this->flash('error', 'Ohne Zielgruppe kann der Bogen nicht freigeschaltet werden.');
            $this->redirect($list);
        }

        $this->ctx->db->run('UPDATE feedback_forms SET status = ? WHERE id = ?', [$status, $formId]);

        $labels = ['draft' => 'Entwurf', 'open' => 'freigeschaltet', 'closed' => 'geschlossen'];
        $this->ctx->audit->log(
            'Feedback-Bogen: Status geändert',
            'info',
            sprintf('Bogen #%d "%s" → %s', $formId, (string) $form['title'], $labels[$status]),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Der Bogen ist jetzt: ' . $labels[$status] . '.');
        $this->redirect($list);
    }

    /** POST /{school}/admin/feedback/{id}/loeschen */
    public function destroy(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_LOESCHEN);
        $this->requireCsrf();
        $form = $this->loadForm((int) $params['id']);
        $formId = (int) $form['id'];

        $responses = (int) $this->ctx->db->fetchValue(
            'SELECT COUNT(*) FROM feedback_responses WHERE form_id = ?',
            [$formId],
        );

        // Fragen, Optionen, Antworten und Teilnahmen hängen per FK CASCADE.
        $this->ctx->db->run('DELETE FROM feedback_forms WHERE id = ?', [$formId]);

        $this->ctx->audit->log(
            'Feedback-Bogen gelöscht',
            'warning',
            sprintf('Bogen #%d "%s" inkl. %d Antwortbogen/-bögen', $formId, (string) $form['title'], $responses),
            $this->ctx->schoolId(),
        );
        $this->flash('success', 'Der Feedback-Bogen wurde gelöscht.');
        $this->redirect($this->ctx->schoolUrl('/admin/feedback'));
    }

    /** GET /{school}/admin/feedback/{id}/vorschau */
    public function preview(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_SEHEN);
        $form = $this->loadForm((int) $params['id']);
        $service = new FeedbackService($this->ctx->db);

        return $this->render('pages/feedback/formular', [
            'title' => 'Vorschau: ' . (string) $form['title'],
            'form' => $form,
            'questions' => $service->questions((int) $form['id']),
            'service' => $service,
            'preview' => true,
            'action' => '',
        ]);
    }

    /** GET /{school}/admin/feedback/{id}/auswertung */
    public function results(array $params): string
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_AUSWERTEN);
        $form = $this->loadForm((int) $params['id']);
        $formId = (int) $form['id'];
        $service = new FeedbackService($this->ctx->db);

        $roleFilter = (string) ($_GET['rolle'] ?? '');
        if (!in_array($roleFilter, $service->audienceRoles($form), true)) {
            $roleFilter = '';
        }

        $questions = $service->questions($formId);

        $byRole = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT role, COUNT(*) AS anzahl FROM feedback_responses WHERE form_id = ? GROUP BY role',
            [$formId],
        ) as $row) {
            $byRole[(string) $row['role']] = (int) $row['anzahl'];
        }

        return $this->render('pages/feedback-admin/auswertung', [
            'title' => 'Auswertung: ' . (string) $form['title'],
            'form' => $form,
            'service' => $service,
            'results' => $service->results($formId, $questions, $roleFilter !== '' ? $roleFilter : null),
            'total' => $service->responseCount($formId),
            'byRole' => $byRole,
            'roleFilter' => $roleFilter,
        ]);
    }

    /** GET /{school}/admin/feedback/{id}/export */
    public function export(array $params): never
    {
        $this->requireSchool($params['school']);
        $this->requirePermission(Permissions::FEEDBACK_AUSWERTEN);
        $form = $this->loadForm((int) $params['id']);
        $service = new FeedbackService($this->ctx->db);

        $format = (string) ($_GET['format'] ?? 'csv');
        [$header, $rows] = $service->exportTable($form, $service->questions((int) $form['id']));

        $this->ctx->audit->log(
            'Feedback exportiert',
            'info',
            sprintf('Bogen #%d "%s" — %d Antwortbogen/-bögen als %s', (int) $form['id'], (string) $form['title'], count($rows), strtoupper($format)),
            $this->ctx->schoolId(),
        );

        Exports::deliver($format, $header, $rows, 'feedback-' . (int) $form['id']);
    }

    // ---------- Helfer ----------

    /**
     * Lädt einen Bogen strikt innerhalb der aktiven Edition der Schule.
     *
     * @return array<string, mixed>
     */
    private function loadForm(int $id): array
    {
        $edition = $this->ctx->requireEdition();
        $form = $this->ctx->db->fetchOne(
            'SELECT * FROM feedback_forms WHERE id = ? AND edition_id = ?',
            [$id, (int) $edition['id']],
        );
        if ($form === null) {
            throw new HttpException(404, 'Diesen Feedback-Bogen gibt es hier nicht.');
        }

        return $form;
    }

    /** @return list<string> */
    private function audienceOf(array $form): array
    {
        return (new FeedbackService($this->ctx->db))->audienceRoles($form);
    }

    /** @return array<string, mixed> */
    private function readForm(): array
    {
        $description = trim((string) ($_POST['description'] ?? ''));
        $thankYou = trim((string) ($_POST['thank_you_text'] ?? ''));
        $status = (string) ($_POST['status'] ?? 'draft');

        return [
            'title' => trim((string) ($_POST['title'] ?? '')),
            'description' => $description !== '' ? $description : null,
            'status' => in_array($status, ['draft', 'open', 'closed'], true) ? $status : 'draft',
            'opens_at' => self::readDateTime('opens_at'),
            'closes_at' => self::readDateTime('closes_at'),
            'is_anonymous' => isset($_POST['is_anonymous']) ? 1 : 0,
            'audience_students' => isset($_POST['audience_students']) ? 1 : 0,
            'audience_teachers' => isset($_POST['audience_teachers']) ? 1 : 0,
            'audience_exhibitors' => isset($_POST['audience_exhibitors']) ? 1 : 0,
            'thank_you_text' => $thankYou !== '' ? $thankYou : null,
        ];
    }

    /** Wandelt ein datetime-local-Feld in ein DB-Datum um (oder NULL). */
    private static function readDateTime(string $field): ?string
    {
        $raw = trim((string) ($_POST[$field] ?? ''));
        if ($raw === '') {
            return null;
        }
        $timestamp = strtotime($raw);

        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    /** @param array<string, mixed> $data */
    private function validate(array $data): ?string
    {
        if ($data['title'] === '') {
            return 'Der Bogen braucht einen Titel.';
        }
        if (mb_strlen((string) $data['title']) > 200) {
            return 'Der Titel darf höchstens 200 Zeichen lang sein.';
        }
        if ($data['audience_students'] === 0 && $data['audience_teachers'] === 0 && $data['audience_exhibitors'] === 0) {
            return 'Bitte mindestens eine Zielgruppe auswählen.';
        }
        if ($data['opens_at'] !== null && $data['closes_at'] !== null
            && strtotime((string) $data['closes_at']) <= strtotime((string) $data['opens_at'])) {
            return 'Das Ende des Zeitfensters muss nach dem Beginn liegen.';
        }
        if ($data['thank_you_text'] !== null && mb_strlen((string) $data['thank_you_text']) > 1000) {
            return 'Der Dankestext darf höchstens 1000 Zeichen lang sein.';
        }

        return null;
    }

    /**
     * Liest die Fragen aus dem Builder-Formular.
     * Erwartet questions[i][...] in Anzeigereihenfolge; `id` erhält
     * bestehende Fragen (und damit deren Antworten).
     *
     * @return list<array<string, mixed>>
     */
    private function readQuestions(): array
    {
        $raw = $_POST['questions'] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $questions = [];
        foreach ($raw as $item) {
            if (!is_array($item)) {
                continue;
            }
            $label = trim((string) ($item['label'] ?? ''));
            $type = (string) ($item['type'] ?? '');
            if ($label === '' && $type === '') {
                continue;
            }

            $help = trim((string) ($item['help_text'] ?? ''));
            $minLabel = trim((string) ($item['scale_min_label'] ?? ''));
            $maxLabel = trim((string) ($item['scale_max_label'] ?? ''));

            // Optionen kommen als Textarea — eine Option pro Zeile.
            $options = [];
            foreach (preg_split('/\R/', (string) ($item['options'] ?? '')) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '') {
                    $options[] = mb_substr($line, 0, 300);
                }
            }

            $questions[] = [
                'id' => (int) ($item['id'] ?? 0),
                'type' => isset(FeedbackService::TYPES[$type]) ? $type : 'short_text',
                'label' => $label,
                'help_text' => $help !== '' ? mb_substr($help, 0, 500) : null,
                'is_required' => !empty($item['is_required']) ? 1 : 0,
                'scale_min' => max(0, min(9, (int) ($item['scale_min'] ?? 1))),
                'scale_max' => max(2, min(10, (int) ($item['scale_max'] ?? 5))),
                'scale_min_label' => $minLabel !== '' ? mb_substr($minLabel, 0, 100) : null,
                'scale_max_label' => $maxLabel !== '' ? mb_substr($maxLabel, 0, 100) : null,
                'options' => $options,
            ];
        }

        return $questions;
    }

    /** @param list<array<string, mixed>> $questions */
    private function validateQuestions(array $questions): ?string
    {
        foreach ($questions as $index => $question) {
            $position = $index + 1;
            if ($question['label'] === '') {
                return sprintf('Frage %d braucht einen Fragetext.', $position);
            }
            if (mb_strlen((string) $question['label']) > 500) {
                return sprintf('Der Text von Frage %d ist zu lang (max. 500 Zeichen).', $position);
            }
            if (in_array($question['type'], FeedbackService::CHOICE_TYPES, true) && count($question['options']) < 2) {
                return sprintf('Frage %d braucht mindestens zwei Antwortoptionen.', $position);
            }
            if ($question['type'] === 'scale' && $question['scale_max'] <= $question['scale_min']) {
                return sprintf('Bei Frage %d muss der Skalen-Höchstwert größer als der Startwert sein.', $position);
            }
        }

        return null;
    }

    /**
     * Schreibt die Fragen eines Bogens. Bestehende Fragen werden anhand
     * ihrer ID aktualisiert (damit bereits gegebene Antworten erhalten
     * bleiben), entfernte Fragen gelöscht.
     *
     * @param list<array<string, mixed>> $questions
     */
    private function writeQuestions(int $formId, array $questions): void
    {
        $existing = array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->ctx->db->fetchAll('SELECT id FROM feedback_questions WHERE form_id = ?', [$formId]),
        );
        $kept = [];

        foreach ($questions as $index => $question) {
            $questionId = (int) $question['id'];
            $args = [
                $index,
                $question['type'],
                $question['label'],
                $question['help_text'],
                $question['is_required'],
                $question['scale_min'],
                $question['scale_max'],
                $question['scale_min_label'],
                $question['scale_max_label'],
            ];

            if ($questionId > 0 && in_array($questionId, $existing, true)) {
                $this->ctx->db->run(
                    'UPDATE feedback_questions
                        SET sort_order = ?, type = ?, label = ?, help_text = ?, is_required = ?,
                            scale_min = ?, scale_max = ?, scale_min_label = ?, scale_max_label = ?
                      WHERE id = ? AND form_id = ?',
                    [...$args, $questionId, $formId],
                );
            } else {
                $this->ctx->db->run(
                    'INSERT INTO feedback_questions
                        (form_id, sort_order, type, label, help_text, is_required,
                         scale_min, scale_max, scale_min_label, scale_max_label)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [$formId, ...$args],
                );
                $questionId = $this->ctx->db->lastInsertId();
            }
            $kept[] = $questionId;

            $this->writeOptions($questionId, $question);
        }

        foreach (array_diff($existing, $kept) as $removedId) {
            $this->ctx->db->run(
                'DELETE FROM feedback_questions WHERE id = ? AND form_id = ?',
                [$removedId, $formId],
            );
        }
    }

    /**
     * Optionen einer Frage abgleichen: gleiche Beschriftung = gleiche Option
     * (behält die bisherigen Antworten), neue kommen dazu, übrige fliegen raus.
     *
     * @param array<string, mixed> $question
     */
    private function writeOptions(int $questionId, array $question): void
    {
        if (!in_array($question['type'], FeedbackService::CHOICE_TYPES, true)) {
            $this->ctx->db->run('DELETE FROM feedback_options WHERE question_id = ?', [$questionId]);

            return;
        }

        $existing = [];
        foreach ($this->ctx->db->fetchAll(
            'SELECT id, label FROM feedback_options WHERE question_id = ?',
            [$questionId],
        ) as $row) {
            $existing[(string) $row['label']] = (int) $row['id'];
        }

        $kept = [];
        foreach ($question['options'] as $index => $label) {
            if (isset($existing[$label])) {
                $optionId = $existing[$label];
                $this->ctx->db->run(
                    'UPDATE feedback_options SET sort_order = ? WHERE id = ?',
                    [$index, $optionId],
                );
            } else {
                $this->ctx->db->run(
                    'INSERT INTO feedback_options (question_id, sort_order, label) VALUES (?, ?, ?)',
                    [$questionId, $index, $label],
                );
                $optionId = $this->ctx->db->lastInsertId();
            }
            $kept[] = $optionId;
        }

        foreach (array_diff(array_values($existing), $kept) as $removedId) {
            $this->ctx->db->run('DELETE FROM feedback_options WHERE id = ?', [$removedId]);
        }
    }
}
