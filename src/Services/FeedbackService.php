<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Fachlogik der Feedback-Bögen: Freischaltung, Zielgruppen, Fragen laden
 * und Antworten auswerten.
 *
 * Freischaltung: Ein Bogen ist genau dann ausfüllbar, wenn sein Status
 * `open` ist UND das optionale Zeitfenster (opens_at/closes_at) passt.
 * Der Status ist also der Hauptschalter, das Zeitfenster die Automatik
 * darüber — beides zusammen, damit ein Bogen weder zu früh noch nach
 * Fristende offen steht.
 */
final class FeedbackService
{
    /** Fragetypen mit Anzeigenamen. */
    public const TYPES = [
        'short_text' => 'Kurztext',
        'long_text' => 'Langtext',
        'single_choice' => 'Einfachauswahl',
        'multi_choice' => 'Mehrfachauswahl',
        'dropdown' => 'Dropdown',
        'scale' => 'Skala',
        'yes_no' => 'Ja/Nein',
    ];

    /** Typen, die Antwortoptionen brauchen. */
    public const CHOICE_TYPES = ['single_choice', 'multi_choice', 'dropdown'];

    public function __construct(private readonly Database $db)
    {
    }

    // ---------- Status & Zielgruppen ----------

    /** Ist der Bogen gerade ausfüllbar? */
    public function isOpen(array $form): bool
    {
        if ((string) $form['status'] !== 'open') {
            return false;
        }

        $now = time();
        if ($form['opens_at'] !== null && strtotime((string) $form['opens_at']) > $now) {
            return false;
        }
        if ($form['closes_at'] !== null && strtotime((string) $form['closes_at']) < $now) {
            return false;
        }

        return true;
    }

    /**
     * Warum ist ein Bogen nicht offen? Für die Anzeige im Admin-Bereich.
     */
    public function statusLabel(array $form): string
    {
        $status = (string) $form['status'];
        if ($status === 'draft') {
            return 'Entwurf';
        }
        if ($status === 'closed') {
            return 'Geschlossen';
        }

        $now = time();
        if ($form['opens_at'] !== null && strtotime((string) $form['opens_at']) > $now) {
            return 'Geplant';
        }
        if ($form['closes_at'] !== null && strtotime((string) $form['closes_at']) < $now) {
            return 'Frist abgelaufen';
        }

        return 'Offen';
    }

    /**
     * Rollen, die diesen Bogen ausfüllen dürfen.
     *
     * @return list<string>
     */
    public function audienceRoles(array $form): array
    {
        $roles = [];
        if ((int) $form['audience_students'] === 1) {
            $roles[] = 'student';
        }
        if ((int) $form['audience_teachers'] === 1) {
            $roles[] = 'teacher';
        }
        if ((int) $form['audience_exhibitors'] === 1) {
            $roles[] = 'exhibitor';
        }

        return $roles;
    }

    /** Gehört der Benutzer zur Zielgruppe des Bogens? */
    public function isInAudience(array $form, array $user): bool
    {
        return in_array((string) $user['role'], $this->audienceRoles($form), true);
    }

    /**
     * Anzahl der Bögen, die diese Rolle in der Edition gerade ausfüllen kann
     * (Zeitfenster inklusive). Für die Navigation gedacht.
     */
    public function openCountForRole(int $editionId, string $role): int
    {
        $column = match ($role) {
            'student' => 'audience_students',
            'teacher' => 'audience_teachers',
            'exhibitor' => 'audience_exhibitors',
            default => null,
        };
        if ($column === null) {
            return 0;
        }

        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM feedback_forms
              WHERE edition_id = ? AND status = 'open' AND {$column} = 1
                AND (opens_at IS NULL OR opens_at <= NOW())
                AND (closes_at IS NULL OR closes_at >= NOW())",
            [$editionId],
        );
    }

    /** Hat der Benutzer den Bogen bereits abgegeben? */
    public function hasSubmitted(int $formId, int $userId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM feedback_participants WHERE form_id = ? AND user_id = ? LIMIT 1',
            [$formId, $userId],
        ) !== null;
    }

    // ---------- Fragen ----------

    /**
     * Alle Fragen eines Bogens inklusive Optionen, in Anzeigereihenfolge.
     *
     * @return list<array<string, mixed>>
     */
    public function questions(int $formId): array
    {
        $questions = $this->db->fetchAll(
            'SELECT * FROM feedback_questions WHERE form_id = ? ORDER BY sort_order, id',
            [$formId],
        );
        if ($questions === []) {
            return [];
        }

        $options = [];
        foreach ($this->db->fetchAll(
            'SELECT o.* FROM feedback_options o
             JOIN feedback_questions q ON q.id = o.question_id
             WHERE q.form_id = ?
             ORDER BY o.sort_order, o.id',
            [$formId],
        ) as $option) {
            $options[(int) $option['question_id']][] = $option;
        }

        foreach ($questions as $i => $question) {
            $questions[$i]['options'] = $options[(int) $question['id']] ?? [];
        }

        return $questions;
    }

    /** Anzahl abgegebener Antwortbögen. */
    public function responseCount(int $formId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM feedback_responses WHERE form_id = ?',
            [$formId],
        );
    }

    // ---------- Auswertung ----------

    /**
     * Auswertung je Frage. Auswahl-/Skala-/Ja-Nein-Fragen werden aggregiert,
     * Freitextfragen als Antwortliste geliefert.
     *
     * @param  list<array<string, mixed>> $questions Ergebnis von questions()
     * @return list<array<string, mixed>>
     */
    public function results(int $formId, array $questions, ?string $roleFilter = null): array
    {
        $args = [$formId];
        $filterSql = '';
        if ($roleFilter !== null && $roleFilter !== '') {
            $filterSql = ' AND r.role = ?';
            $args[] = $roleFilter;
        }

        $answers = [];
        foreach ($this->db->fetchAll(
            'SELECT a.question_id, a.option_id, a.value_text, a.value_number
             FROM feedback_answers a
             JOIN feedback_responses r ON r.id = a.response_id
             WHERE r.form_id = ?' . $filterSql,
            $args,
        ) as $answer) {
            $answers[(int) $answer['question_id']][] = $answer;
        }

        $results = [];
        foreach ($questions as $question) {
            $questionId = (int) $question['id'];
            $type = (string) $question['type'];
            $rows = $answers[$questionId] ?? [];

            $entry = [
                'question' => $question,
                'answer_count' => count($rows),
                'buckets' => [],
                'texts' => [],
                'average' => null,
            ];

            if (in_array($type, self::CHOICE_TYPES, true)) {
                $counts = [];
                foreach ($question['options'] as $option) {
                    $counts[(int) $option['id']] = ['label' => (string) $option['label'], 'count' => 0];
                }
                foreach ($rows as $row) {
                    $optionId = $row['option_id'] !== null ? (int) $row['option_id'] : 0;
                    if (isset($counts[$optionId])) {
                        $counts[$optionId]['count']++;
                    }
                }
                $entry['buckets'] = array_values($counts);
            } elseif ($type === 'yes_no') {
                $yes = 0;
                $no = 0;
                foreach ($rows as $row) {
                    (int) $row['value_number'] === 1 ? $yes++ : $no++;
                }
                $entry['buckets'] = [
                    ['label' => 'Ja', 'count' => $yes],
                    ['label' => 'Nein', 'count' => $no],
                ];
            } elseif ($type === 'scale') {
                $min = (int) $question['scale_min'];
                $max = (int) $question['scale_max'];
                $counts = [];
                for ($i = $min; $i <= $max; $i++) {
                    $counts[$i] = ['label' => (string) $i, 'count' => 0];
                }
                $sum = 0;
                $n = 0;
                foreach ($rows as $row) {
                    $value = (int) $row['value_number'];
                    if (isset($counts[$value])) {
                        $counts[$value]['count']++;
                    }
                    $sum += $value;
                    $n++;
                }
                $entry['buckets'] = array_values($counts);
                $entry['average'] = $n > 0 ? round($sum / $n, 2) : null;
            } else {
                foreach ($rows as $row) {
                    $text = trim((string) ($row['value_text'] ?? ''));
                    if ($text !== '') {
                        $entry['texts'][] = $text;
                    }
                }
            }

            $results[] = $entry;
        }

        return $results;
    }

    /**
     * Antwortbögen als Tabelle für den Export: eine Zeile pro Abgabe,
     * eine Spalte pro Frage. Bei anonymen Bögen bleibt die Personenspalte leer.
     *
     * @param  list<array<string, mixed>> $questions
     * @return array{0: list<string>, 1: list<list<string>>}
     */
    public function exportTable(array $form, array $questions): array
    {
        $formId = (int) $form['id'];
        $anonymous = (int) $form['is_anonymous'] === 1;

        $header = ['Abgegeben am', 'Rolle'];
        if (!$anonymous) {
            $header[] = 'Person';
            $header[] = 'Benutzername';
        }
        $header[] = 'Klasse';
        foreach ($questions as $question) {
            $header[] = (string) $question['label'];
        }

        $responses = $this->db->fetchAll(
            'SELECT r.id, r.role, r.class, r.submitted_at,
                    u.username, u.firstname, u.lastname
             FROM feedback_responses r
             LEFT JOIN users u ON u.id = r.user_id
             WHERE r.form_id = ?
             ORDER BY r.submitted_at, r.id',
            [$formId],
        );
        if ($responses === []) {
            return [$header, []];
        }

        // Antworten je Antwortbogen und Frage einsammeln; Mehrfachauswahl
        // landet als kommaseparierte Liste in einer Zelle.
        $cells = [];
        foreach ($this->db->fetchAll(
            'SELECT a.response_id, a.question_id, a.value_text, a.value_number, o.label AS option_label
             FROM feedback_answers a
             JOIN feedback_responses r ON r.id = a.response_id
             LEFT JOIN feedback_options o ON o.id = a.option_id
             WHERE r.form_id = ?
             ORDER BY a.id',
            [$formId],
        ) as $answer) {
            $value = $answer['option_label'] !== null
                ? (string) $answer['option_label']
                : ($answer['value_text'] !== null
                    ? (string) $answer['value_text']
                    : ($answer['value_number'] !== null ? (string) $answer['value_number'] : ''));
            $cells[(int) $answer['response_id']][(int) $answer['question_id']][] = $value;
        }

        $rows = [];
        foreach ($responses as $response) {
            $responseId = (int) $response['id'];
            $row = [
                (string) $response['submitted_at'],
                (string) $response['role'],
            ];
            if (!$anonymous) {
                $row[] = trim((string) $response['firstname'] . ' ' . (string) $response['lastname']);
                $row[] = (string) ($response['username'] ?? '');
            }
            $row[] = (string) ($response['class'] ?? '');

            foreach ($questions as $question) {
                $values = $cells[$responseId][(int) $question['id']] ?? [];
                if ((string) $question['type'] === 'yes_no') {
                    $values = array_map(static fn (string $v): string => $v === '1' ? 'Ja' : 'Nein', $values);
                }
                $row[] = implode(', ', $values);
            }
            $rows[] = $row;
        }

        return [$header, $rows];
    }
}
