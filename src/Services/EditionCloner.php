<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Dupliziert eine Messe-Edition als Grundlage für den nächsten Jahrgang.
 *
 * Kopiert wird ausschließlich die Struktur — Zeitraster, Räume,
 * Kapazitäten, Ausstellerstammdaten, Orga-Zuordnungen und Feedback-Bögen.
 * NIE kopiert werden personen- oder durchführungsbezogene Daten:
 * Anmeldungen, Anwesenheiten, QR-Token, Ausstattungsanfragen,
 * Absagevorgänge und abgegebene Feedback-Antworten.
 *
 * Geklonte Aussteller starten mit `pipeline_status = 'lead'` und
 * `active = 0`: für den neuen Jahrgang muss jedes Unternehmen erst wieder
 * zusagen. Über `previous_exhibitor_id` bleibt die Verbindung zum Vorjahr
 * erhalten.
 */
final class EditionCloner
{
    /** Kopierbare Bereiche (Key => Anzeigename). */
    public const PARTS = [
        'timeslots' => 'Zeitraster',
        'rooms' => 'Räume & Slot-Kapazitäten',
        'exhibitors' => 'Aussteller (Stammdaten)',
        'exhibitor_users' => 'Aussteller-Zugänge',
        'orga_team' => 'Orga-Team-Zuordnungen',
        'feedback' => 'Feedback-Bögen (nur Fragen)',
    ];

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Führt den Klonvorgang aus. Muss innerhalb einer Transaktion laufen.
     *
     * @param  list<string> $parts Auswahl aus PARTS
     * @return array<string, int>  Bereich => Anzahl kopierter Datensätze
     */
    public function copy(int $sourceId, int $targetId, array $parts): array
    {
        $stats = [];

        // Reihenfolge ist zwingend: Slots und Räume liefern die IDs, auf die
        // Kapazitäten und Aussteller verweisen.
        $slotMap = in_array('timeslots', $parts, true) || in_array('rooms', $parts, true)
            ? $this->copyTimeslots($sourceId, $targetId, in_array('timeslots', $parts, true))
            : [];
        if (in_array('timeslots', $parts, true)) {
            $stats['timeslots'] = count($slotMap);
        }

        $roomMap = [];
        if (in_array('rooms', $parts, true)) {
            $roomMap = $this->copyRooms($sourceId, $targetId);
            $stats['rooms'] = count($roomMap);
            $stats['capacities'] = $this->copyCapacities($roomMap, $slotMap);
        }

        $exhibitorMap = [];
        if (in_array('exhibitors', $parts, true)) {
            $exhibitorMap = $this->copyExhibitors($sourceId, $targetId, $roomMap);
            $stats['exhibitors'] = count($exhibitorMap);

            if (in_array('exhibitor_users', $parts, true)) {
                $stats['exhibitor_users'] = $this->copyExhibitorUsers($exhibitorMap);
            }
            if (in_array('orga_team', $parts, true)) {
                $stats['orga_team'] = $this->copyOrgaTeam($exhibitorMap);
            }
        }

        if (in_array('feedback', $parts, true)) {
            $stats['feedback'] = $this->copyFeedbackForms($sourceId, $targetId);
        }

        return $stats;
    }

    /**
     * Zeitraster kopieren. Wird auch dann gebraucht, wenn nur Räume
     * übernommen werden — dann allerdings nur als Zuordnung auf bereits
     * vorhandene Slots gleicher Nummer.
     *
     * @return array<int, int> alte Slot-ID => neue Slot-ID
     */
    private function copyTimeslots(int $sourceId, int $targetId, bool $insert): array
    {
        $map = [];
        $existing = [];
        foreach ($this->db->fetchAll(
            'SELECT id, slot_number FROM timeslots WHERE edition_id = ?',
            [$targetId],
        ) as $row) {
            $existing[(int) $row['slot_number']] = (int) $row['id'];
        }

        foreach ($this->db->fetchAll(
            'SELECT * FROM timeslots WHERE edition_id = ? ORDER BY slot_number',
            [$sourceId],
        ) as $slot) {
            $number = (int) $slot['slot_number'];

            if (isset($existing[$number])) {
                $map[(int) $slot['id']] = $existing[$number];
                continue;
            }
            if (!$insert) {
                continue;
            }

            $this->db->run(
                'INSERT INTO timeslots (edition_id, slot_number, slot_name, start_time, end_time, is_managed, is_break)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $targetId,
                    $number,
                    $slot['slot_name'],
                    $slot['start_time'],
                    $slot['end_time'],
                    (int) $slot['is_managed'],
                    (int) $slot['is_break'],
                ],
            );
            $map[(int) $slot['id']] = $this->db->lastInsertId();
        }

        return $map;
    }

    /** @return array<int, int> alte Raum-ID => neue Raum-ID */
    private function copyRooms(int $sourceId, int $targetId): array
    {
        $map = [];
        foreach ($this->db->fetchAll(
            'SELECT * FROM rooms WHERE edition_id = ? ORDER BY id',
            [$sourceId],
        ) as $room) {
            $this->db->run(
                'INSERT INTO rooms (edition_id, room_number, room_name, building, floor, capacity, equipment)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $targetId,
                    $room['room_number'],
                    $room['room_name'],
                    $room['building'],
                    $room['floor'],
                    (int) $room['capacity'],
                    $room['equipment'],
                ],
            );
            $map[(int) $room['id']] = $this->db->lastInsertId();
        }

        return $map;
    }

    /**
     * Abweichende Slot-Kapazitäten übernehmen — nur für Paare, deren Raum
     * UND Slot beide abgebildet werden konnten.
     *
     * @param array<int, int> $roomMap
     * @param array<int, int> $slotMap
     */
    private function copyCapacities(array $roomMap, array $slotMap): int
    {
        if ($roomMap === [] || $slotMap === []) {
            return 0;
        }

        $count = 0;
        $placeholders = implode(',', array_fill(0, count($roomMap), '?'));
        foreach ($this->db->fetchAll(
            "SELECT * FROM room_slot_capacities WHERE room_id IN ({$placeholders})",
            array_keys($roomMap),
        ) as $capacity) {
            $roomId = $roomMap[(int) $capacity['room_id']] ?? null;
            $slotId = $slotMap[(int) $capacity['timeslot_id']] ?? null;
            if ($roomId === null || $slotId === null) {
                continue;
            }

            $this->db->run(
                'INSERT INTO room_slot_capacities (room_id, timeslot_id, capacity) VALUES (?, ?, ?)',
                [$roomId, $slotId, (int) $capacity['capacity']],
            );
            $count++;
        }

        return $count;
    }

    /**
     * Ausstellerstammdaten übernehmen — ohne Dokumente (die hängen an
     * Dateien der alten Edition) und zunächst inaktiv im Status „Lead“.
     *
     * @param  array<int, int> $roomMap
     * @return array<int, int> alte Aussteller-ID => neue Aussteller-ID
     */
    private function copyExhibitors(int $sourceId, int $targetId, array $roomMap): array
    {
        $map = [];
        foreach ($this->db->fetchAll(
            "SELECT * FROM exhibitors WHERE edition_id = ? AND pipeline_status <> 'declined' ORDER BY name",
            [$sourceId],
        ) as $exhibitor) {
            $oldRoom = $exhibitor['room_id'] !== null ? (int) $exhibitor['room_id'] : null;

            $this->db->run(
                "INSERT INTO exhibitors
                    (edition_id, previous_exhibitor_id, name, short_description, description, categories,
                     logo, contact_person, email, phone, website, jobs, features, offer_types,
                     equipment, visible_fields, total_slots, room_id, active, pipeline_status)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 'lead')",
                [
                    $targetId,
                    (int) $exhibitor['id'],
                    $exhibitor['name'],
                    $exhibitor['short_description'],
                    $exhibitor['description'],
                    $exhibitor['categories'],
                    $exhibitor['logo'],
                    $exhibitor['contact_person'],
                    $exhibitor['email'],
                    $exhibitor['phone'],
                    $exhibitor['website'],
                    $exhibitor['jobs'],
                    $exhibitor['features'],
                    $exhibitor['offer_types'],
                    $exhibitor['equipment'],
                    $exhibitor['visible_fields'],
                    (int) $exhibitor['total_slots'],
                    $oldRoom !== null ? ($roomMap[$oldRoom] ?? null) : null,
                ],
            );
            $map[(int) $exhibitor['id']] = $this->db->lastInsertId();
        }

        return $map;
    }

    /**
     * Zugänge der Unternehmensvertreter übernehmen — nur bereits
     * angenommene, aktive Verknüpfungen; Einladungstoken werden NICHT
     * mitkopiert (sie sind an die alte Einladung gebunden).
     *
     * @param array<int, int> $exhibitorMap
     */
    private function copyExhibitorUsers(array $exhibitorMap): int
    {
        if ($exhibitorMap === []) {
            return 0;
        }

        $count = 0;
        $placeholders = implode(',', array_fill(0, count($exhibitorMap), '?'));
        foreach ($this->db->fetchAll(
            "SELECT * FROM exhibitor_users
              WHERE exhibitor_id IN ({$placeholders}) AND status = 'active' AND invite_accepted = 1",
            array_keys($exhibitorMap),
        ) as $link) {
            $newId = $exhibitorMap[(int) $link['exhibitor_id']] ?? null;
            if ($newId === null) {
                continue;
            }

            $this->db->run(
                'INSERT INTO exhibitor_users (user_id, exhibitor_id, can_edit_profile, can_manage_documents, invite_accepted)
                 VALUES (?, ?, ?, ?, 1)',
                [
                    (int) $link['user_id'],
                    $newId,
                    (int) $link['can_edit_profile'],
                    (int) $link['can_manage_documents'],
                ],
            );
            $count++;
        }

        return $count;
    }

    /** @param array<int, int> $exhibitorMap */
    private function copyOrgaTeam(array $exhibitorMap): int
    {
        if ($exhibitorMap === []) {
            return 0;
        }

        $count = 0;
        $placeholders = implode(',', array_fill(0, count($exhibitorMap), '?'));
        foreach ($this->db->fetchAll(
            "SELECT * FROM exhibitor_orga_team WHERE exhibitor_id IN ({$placeholders})",
            array_keys($exhibitorMap),
        ) as $row) {
            $newId = $exhibitorMap[(int) $row['exhibitor_id']] ?? null;
            if ($newId === null) {
                continue;
            }

            $this->db->run(
                'INSERT INTO exhibitor_orga_team (user_id, exhibitor_id) VALUES (?, ?)',
                [(int) $row['user_id'], $newId],
            );
            $count++;
        }

        return $count;
    }

    /**
     * Feedback-Bögen als Entwurf übernehmen — mit allen Fragen und
     * Optionen, aber ohne Antworten und ohne Zeitfenster.
     */
    private function copyFeedbackForms(int $sourceId, int $targetId): int
    {
        $count = 0;
        foreach ($this->db->fetchAll(
            'SELECT * FROM feedback_forms WHERE edition_id = ? ORDER BY id',
            [$sourceId],
        ) as $form) {
            $this->db->run(
                "INSERT INTO feedback_forms
                    (edition_id, title, description, status, is_anonymous,
                     audience_students, audience_teachers, audience_exhibitors, thank_you_text, created_by)
                 VALUES (?, ?, ?, 'draft', ?, ?, ?, ?, ?, ?)",
                [
                    $targetId,
                    $form['title'],
                    $form['description'],
                    (int) $form['is_anonymous'],
                    (int) $form['audience_students'],
                    (int) $form['audience_teachers'],
                    (int) $form['audience_exhibitors'],
                    $form['thank_you_text'],
                    $form['created_by'] !== null ? (int) $form['created_by'] : null,
                ],
            );
            $newFormId = $this->db->lastInsertId();
            $count++;

            foreach ($this->db->fetchAll(
                'SELECT * FROM feedback_questions WHERE form_id = ? ORDER BY sort_order, id',
                [(int) $form['id']],
            ) as $question) {
                $this->db->run(
                    'INSERT INTO feedback_questions
                        (form_id, sort_order, type, label, help_text, is_required,
                         scale_min, scale_max, scale_min_label, scale_max_label)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                    [
                        $newFormId,
                        (int) $question['sort_order'],
                        $question['type'],
                        $question['label'],
                        $question['help_text'],
                        (int) $question['is_required'],
                        (int) $question['scale_min'],
                        (int) $question['scale_max'],
                        $question['scale_min_label'],
                        $question['scale_max_label'],
                    ],
                );
                $newQuestionId = $this->db->lastInsertId();

                foreach ($this->db->fetchAll(
                    'SELECT * FROM feedback_options WHERE question_id = ? ORDER BY sort_order, id',
                    [(int) $question['id']],
                ) as $option) {
                    $this->db->run(
                        'INSERT INTO feedback_options (question_id, sort_order, label) VALUES (?, ?, ?)',
                        [$newQuestionId, (int) $option['sort_order'], $option['label']],
                    );
                }
            }
        }

        return $count;
    }
}
