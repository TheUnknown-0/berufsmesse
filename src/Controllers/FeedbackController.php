<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\FeedbackService;

/**
 * Feedback-Bögen ausfüllen (Schüler:innen, Lehrkräfte, Aussteller).
 *
 * Sichtbar sind ausschließlich freigeschaltete Bögen der aktiven Edition,
 * deren Zielgruppe zur Rolle des Kontos passt. Jedes Konto kann einen Bogen
 * genau einmal abgeben (feedback_participants); bei anonymen Bögen wird dort
 * NUR die Teilnahme vermerkt — die Antworten selbst bleiben ohne Personenbezug.
 */
final class FeedbackController extends Controller
{
    /** GET /{school}/feedback */
    public function index(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireLogin();
        $edition = $this->ctx->requireEdition();
        $service = new FeedbackService($this->ctx->db);

        $forms = [];
        foreach ($this->ctx->db->fetchAll(
            "SELECT * FROM feedback_forms
              WHERE edition_id = ? AND status = 'open'
              ORDER BY created_at DESC",
            [(int) $edition['id']],
        ) as $form) {
            if (!$service->isOpen($form) || !$service->isInAudience($form, $user)) {
                continue;
            }
            $form['submitted'] = $service->hasSubmitted((int) $form['id'], (int) $user['id']);
            $forms[] = $form;
        }

        return $this->render('pages/feedback/index', [
            'title' => 'Feedback',
            'forms' => $forms,
        ]);
    }

    /** GET /{school}/feedback/{id} */
    public function show(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireLogin();
        $service = new FeedbackService($this->ctx->db);
        $form = $this->loadOpenForm((int) $params['id'], $user, $service);
        $formId = (int) $form['id'];

        if ($service->hasSubmitted($formId, (int) $user['id'])) {
            return $this->render('pages/feedback/danke', [
                'title' => (string) $form['title'],
                'form' => $form,
                'alreadyDone' => true,
            ]);
        }

        return $this->render('pages/feedback/formular', [
            'title' => (string) $form['title'],
            'form' => $form,
            'questions' => $service->questions($formId),
            'service' => $service,
            'preview' => false,
            'action' => $this->ctx->schoolUrl('/feedback/' . $formId),
        ]);
    }

    /** POST /{school}/feedback/{id} */
    public function submit(array $params): string
    {
        $this->requireSchool($params['school']);
        $user = $this->requireLogin();
        $this->requireCsrf();
        $service = new FeedbackService($this->ctx->db);
        $form = $this->loadOpenForm((int) $params['id'], $user, $service);
        $formId = (int) $form['id'];
        $userId = (int) $user['id'];
        $back = $this->ctx->schoolUrl('/feedback/' . $formId);

        if ($service->hasSubmitted($formId, $userId)) {
            $this->flash('warning', 'Du hast diesen Bogen bereits abgegeben.');
            $this->redirect($this->ctx->schoolUrl('/feedback'));
        }

        $questions = $service->questions($formId);
        if ($questions === []) {
            throw new HttpException(404, 'Dieser Bogen enthält keine Fragen.');
        }

        [$answers, $error] = $this->readAnswers($questions);
        if ($error !== null) {
            $this->flash('error', $error);
            $this->redirect($back);
        }

        $anonymous = (int) $form['is_anonymous'] === 1;

        try {
            $this->ctx->db->transaction(function () use ($formId, $userId, $user, $anonymous, $answers): void {
                // Zuerst die Teilnahme — der UNIQUE-Index ist der Schutz
                // gegen doppelte Abgaben (auch bei parallelen Requests).
                $this->ctx->db->run(
                    'INSERT INTO feedback_participants (form_id, user_id) VALUES (?, ?)',
                    [$formId, $userId],
                );

                // Bei anonymen Bögen wird weder Konto noch Klasse gespeichert.
                $this->ctx->db->run(
                    'INSERT INTO feedback_responses (form_id, user_id, role, class) VALUES (?, ?, ?, ?)',
                    [
                        $formId,
                        $anonymous ? null : $userId,
                        (string) $user['role'],
                        $anonymous ? null : ($user['class'] ?? null),
                    ],
                );
                $responseId = $this->ctx->db->lastInsertId();

                foreach ($answers as $answer) {
                    $this->ctx->db->run(
                        'INSERT INTO feedback_answers (response_id, question_id, option_id, value_text, value_number)
                         VALUES (?, ?, ?, ?, ?)',
                        [$responseId, $answer['question_id'], $answer['option_id'], $answer['value_text'], $answer['value_number']],
                    );
                }
            });
        } catch (\PDOException $e) {
            // Doppelte Abgabe durch Doppelklick o. Ä.
            if ($e->getCode() === '23000') {
                $this->flash('warning', 'Du hast diesen Bogen bereits abgegeben.');
                $this->redirect($this->ctx->schoolUrl('/feedback'));
            }
            throw $e;
        }

        return $this->render('pages/feedback/danke', [
            'title' => (string) $form['title'],
            'form' => $form,
            'alreadyDone' => false,
        ]);
    }

    // ---------- Helfer ----------

    /**
     * Lädt einen Bogen, der für dieses Konto gerade ausfüllbar ist.
     *
     * @param  array<string, mixed> $user
     * @return array<string, mixed>
     */
    private function loadOpenForm(int $id, array $user, FeedbackService $service): array
    {
        $edition = $this->ctx->requireEdition();
        $form = $this->ctx->db->fetchOne(
            'SELECT * FROM feedback_forms WHERE id = ? AND edition_id = ?',
            [$id, (int) $edition['id']],
        );
        if ($form === null) {
            throw new HttpException(404, 'Diesen Feedback-Bogen gibt es nicht.');
        }
        if (!$service->isOpen($form)) {
            throw new HttpException(403, 'Dieser Feedback-Bogen ist derzeit nicht freigeschaltet.');
        }
        if (!$service->isInAudience($form, $user)) {
            throw new HttpException(403, 'Dieser Feedback-Bogen richtet sich an eine andere Gruppe.');
        }

        return $form;
    }

    /**
     * Liest und prüft die Antworten zum Fragenkatalog.
     *
     * @param  list<array<string, mixed>> $questions
     * @return array{0: list<array<string, mixed>>, 1: string|null}
     */
    private function readAnswers(array $questions): array
    {
        $input = $_POST['antwort'] ?? [];
        $input = is_array($input) ? $input : [];
        $answers = [];

        foreach ($questions as $question) {
            $questionId = (int) $question['id'];
            $type = (string) $question['type'];
            $required = (int) $question['is_required'] === 1;
            $raw = $input[$questionId] ?? null;
            $label = (string) $question['label'];

            if (in_array($type, ['short_text', 'long_text'], true)) {
                $text = trim((string) (is_array($raw) ? '' : ($raw ?? '')));
                if ($text === '') {
                    if ($required) {
                        return [[], sprintf('Bitte beantworte die Pflichtfrage „%s“.', $label)];
                    }
                    continue;
                }
                $answers[] = [
                    'question_id' => $questionId,
                    'option_id' => null,
                    'value_text' => mb_substr($text, 0, 5000),
                    'value_number' => null,
                ];
                continue;
            }

            if ($type === 'yes_no') {
                $value = is_array($raw) ? '' : (string) ($raw ?? '');
                if ($value !== '1' && $value !== '0') {
                    if ($required) {
                        return [[], sprintf('Bitte beantworte die Pflichtfrage „%s“.', $label)];
                    }
                    continue;
                }
                $answers[] = [
                    'question_id' => $questionId,
                    'option_id' => null,
                    'value_text' => null,
                    'value_number' => (int) $value,
                ];
                continue;
            }

            if ($type === 'scale') {
                $value = is_array($raw) ? '' : (string) ($raw ?? '');
                if ($value === '') {
                    if ($required) {
                        return [[], sprintf('Bitte beantworte die Pflichtfrage „%s“.', $label)];
                    }
                    continue;
                }
                $number = (int) $value;
                if ($number < (int) $question['scale_min'] || $number > (int) $question['scale_max']) {
                    return [[], sprintf('Der Wert bei „%s“ liegt außerhalb der Skala.', $label)];
                }
                $answers[] = [
                    'question_id' => $questionId,
                    'option_id' => null,
                    'value_text' => null,
                    'value_number' => $number,
                ];
                continue;
            }

            // Auswahl-Fragen: gültig ist nur, was auch zur Frage gehört.
            $validOptions = array_map(static fn (array $o): int => (int) $o['id'], $question['options']);
            $selected = is_array($raw) ? $raw : ($raw === null || $raw === '' ? [] : [$raw]);
            $chosen = [];
            foreach ($selected as $value) {
                $optionId = (int) $value;
                if (!in_array($optionId, $validOptions, true)) {
                    return [[], sprintf('Ungültige Auswahl bei „%s“.', $label)];
                }
                $chosen[] = $optionId;
            }
            $chosen = array_values(array_unique($chosen));

            if ($chosen === []) {
                if ($required) {
                    return [[], sprintf('Bitte beantworte die Pflichtfrage „%s“.', $label)];
                }
                continue;
            }
            if ($type !== 'multi_choice' && count($chosen) > 1) {
                return [[], sprintf('Bei „%s“ ist nur eine Antwort möglich.', $label)];
            }

            foreach ($chosen as $optionId) {
                $answers[] = [
                    'question_id' => $questionId,
                    'option_id' => $optionId,
                    'value_text' => null,
                    'value_number' => null,
                ];
            }
        }

        return [$answers, null];
    }
}
