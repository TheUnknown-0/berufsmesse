<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Hilfe-Ruf am Messetag: Wer einem Raum zugeteilt ist (Aufsichtsplan, Lehrkräfte
 * und Orga), ruft mit einem Knopf Hilfe; wer „Im Dienst“ ist, bekommt den Ruf
 * in der App aufs Handy und sagt mit „Ich komme“ zu.
 *
 * Klingel-Runden: Jeder Ruf hat eine Rundennummer (alert_round). Die App klingelt,
 * sobald die Nummer steigt: beim neuen Ruf, bei „Brauche mehr Hilfe“ und wenn
 * nach UNANSWERED_SECONDS niemand zugesagt hat. Die letzte Regel wird beim Lesen
 * nur berechnet (effective()), nicht gespeichert, damit Abfragen nichts schreiben.
 * Schreibende Aktionen halten die berechnete Runde vorher fest (materialize()),
 * sonst könnte die Nummer nach einer Zusage wieder sinken.
 *
 * Gilt immer für die aktive Edition der Schule, auch wenn jemand gerade eine
 * ältere Edition ansieht.
 */
final class HelpCalls
{
    public const SETTING_CATEGORIES = 'help_call_categories';
    public const SETTING_TEXT = 'help_call_text_enabled';

    /** Vorgabe, solange die Schule keine eigenen Kategorien festgelegt hat. */
    public const DEFAULT_CATEGORIES = ['Erste Hilfe', 'Technik', 'Störung im Raum', 'Sonstiges'];

    public const TEST_CATEGORY = 'Probealarm';

    /** Ohne Zusage klingelt es nach dieser Zeit erneut bei allen. */
    public const UNANSWERED_SECONDS = 120;

    /** So lange gilt ein Gerät nach seiner letzten Abfrage noch als „Im Dienst“. */
    public const DUTY_ONLINE_SECONDS = 30;

    public const MAX_MESSAGE = 200;
    public const MAX_CATEGORY = 100;
    public const MAX_CATEGORIES = 12;

    /** @var array<string, mixed>|null|false false = noch nicht geladen */
    private array|null|false $edition = false;

    public function __construct(
        private readonly Database $db,
        private readonly Settings $settings,
        private readonly int $schoolId,
    ) {
    }

    // ------------------------------------------------------------ Edition & Tag

    /** @return array<string, mixed>|null Aktive Edition der Schule. */
    public function edition(): ?array
    {
        if ($this->edition === false) {
            $this->edition = $this->db->fetchOne(
                'SELECT * FROM messe_editions WHERE school_id = ? AND status = \'active\'
                 ORDER BY year DESC, id DESC LIMIT 1',
                [$this->schoolId],
            );
        }

        return $this->edition;
    }

    /** Ist heute der Messetag der aktiven Edition? Nur dann ist der Knopf aktiv. */
    public function isEventDay(): bool
    {
        $date = $this->edition()['event_date'] ?? null;

        return is_string($date) && $date === date('Y-m-d');
    }

    /**
     * Bis wann der Empfang in der App laufen darf: Ende des heutigen Tages. Am
     * Messetag ist das das Ende des Messetags; an anderen Tagen lässt sich so
     * vorab ein Probealarm testen.
     */
    public static function dutyUntil(): string
    {
        return date('c', (int) strtotime('tomorrow') - 1);
    }

    // ------------------------------------------------------------ Einstellungen

    /** @return list<string> */
    public function categories(): array
    {
        $raw = $this->settings->get(self::SETTING_CATEGORIES, $this->schoolId);
        $list = $raw !== null ? json_decode($raw, true) : null;
        if (!is_array($list)) {
            return self::DEFAULT_CATEGORIES;
        }
        $list = self::cleanCategories($list);

        return $list !== [] ? $list : self::DEFAULT_CATEGORIES;
    }

    public function textEnabled(): bool
    {
        return $this->settings->getBool(self::SETTING_TEXT, $this->schoolId, true);
    }

    /** @param list<string> $categories */
    public function saveSettings(array $categories, bool $textEnabled): void
    {
        $this->settings->set(
            self::SETTING_CATEGORIES,
            json_encode(self::cleanCategories($categories), JSON_UNESCAPED_UNICODE),
            $this->schoolId,
        );
        $this->settings->set(self::SETTING_TEXT, $textEnabled ? '1' : '0', $this->schoolId);
    }

    /**
     * Bereinigt eine Kategorienliste: getrimmt, ohne Leere und Doppelte, gekürzt.
     *
     * @param array<mixed> $categories
     * @return list<string>
     */
    public static function cleanCategories(array $categories): array
    {
        $result = [];
        foreach ($categories as $category) {
            $category = mb_substr(trim(preg_replace('/\s+/u', ' ', (string) $category) ?? ''), 0, self::MAX_CATEGORY);
            if ($category !== '' && !in_array(mb_strtolower($category), array_map('mb_strtolower', $result), true)) {
                $result[] = $category;
            }
        }

        return array_slice($result, 0, self::MAX_CATEGORIES);
    }

    // ------------------------------------------------------------ Räume

    /**
     * Räume, denen das Konto in der aktiven Edition zugeteilt ist (ganztags oder
     * in irgendeinem Slot). Vorausgewählt ist der Raum des laufenden bzw. nächsten
     * Slots, sonst der Ganztags-Raum.
     *
     * @return list<array{id: int, label: string, preselected: bool}>
     */
    public function assignedRooms(int $userId): array
    {
        $edition = $this->edition();
        if ($edition === null) {
            return [];
        }
        $editionId = (int) $edition['id'];

        $rows = $this->db->fetchAll(
            'SELECT tra.room_id, tra.timeslot_id, r.room_number, r.room_name
             FROM teacher_room_assignments tra
             JOIN rooms r ON r.id = tra.room_id AND r.edition_id = tra.edition_id
             WHERE tra.teacher_id = ? AND tra.edition_id = ?
             ORDER BY r.room_number',
            [$userId, $editionId],
        );
        if ($rows === []) {
            return [];
        }

        $currentSlot = (new AttendanceService($this->db))->currentSlotId($editionId, $edition['event_date'] ?? null);
        $preferred = null;
        $allDay = null;
        $rooms = [];
        foreach ($rows as $row) {
            $roomId = (int) $row['room_id'];
            $rooms[$roomId] ??= AttendanceService::roomLabel($row['room_number'] ?? null, $row['room_name'] ?? null);
            if ($row['timeslot_id'] === null) {
                $allDay ??= $roomId;
            } elseif ($currentSlot !== null && (int) $row['timeslot_id'] === $currentSlot) {
                $preferred = $roomId;
            }
        }
        $preselect = $preferred ?? $allDay ?? array_key_first($rooms);

        $result = [];
        foreach ($rooms as $id => $label) {
            $result[] = ['id' => $id, 'label' => $label, 'preselected' => $id === $preselect];
        }

        return $result;
    }

    /** @return list<int> IDs der Räume, denen das Konto zugeteilt ist. */
    public function assignedRoomIds(int $userId): array
    {
        return array_map(static fn (array $room): int => $room['id'], $this->assignedRooms($userId));
    }

    // ------------------------------------------------------------ Rufe lesen

    /**
     * Offene Rufe der aktiven Edition, mit Zusagen.
     *
     * @return list<array<string, mixed>>
     */
    public function openCalls(): array
    {
        $edition = $this->edition();
        if ($edition === null) {
            return [];
        }

        return $this->withResponses($this->db->fetchAll(
            self::CALL_SELECT . ' WHERE c.school_id = ? AND c.edition_id = ? AND c.status = \'open\'
             ORDER BY c.created_at, c.id',
            [$this->schoolId, (int) $edition['id']],
        ));
    }

    /** @return array<string, mixed>|null */
    public function find(int $callId): ?array
    {
        $rows = $this->withResponses($this->db->fetchAll(
            self::CALL_SELECT . ' WHERE c.id = ? AND c.school_id = ?',
            [$callId, $this->schoolId],
        ));

        return $rows[0] ?? null;
    }

    /**
     * Verlauf aller Rufe der aktiven Edition, neueste zuerst.
     *
     * @return list<array<string, mixed>>
     */
    public function history(int $limit = 200): array
    {
        $edition = $this->edition();
        if ($edition === null) {
            return [];
        }

        return $this->withResponses($this->db->fetchAll(
            self::CALL_SELECT . ' WHERE c.school_id = ? AND c.edition_id = ?
             ORDER BY c.created_at DESC, c.id DESC LIMIT ' . max(1, $limit),
            [$this->schoolId, (int) $edition['id']],
        ));
    }

    private const CALL_SELECT = 'SELECT c.*,
            TIMESTAMPDIFF(SECOND, c.created_at, NOW()) AS age_seconds,
            TIMESTAMPDIFF(SECOND, c.alert_at, NOW()) AS alert_age_seconds,
            u.firstname AS caller_firstname, u.lastname AS caller_lastname,
            cb.firstname AS closer_firstname, cb.lastname AS closer_lastname
        FROM help_calls c
        LEFT JOIN users u ON u.id = c.caller_id
        LEFT JOIN users cb ON cb.id = c.closed_by';

    /**
     * @param list<array<string, mixed>> $calls
     * @return list<array<string, mixed>>
     */
    private function withResponses(array $calls): array
    {
        if ($calls === []) {
            return [];
        }
        $ids = array_map(static fn (array $c): int => (int) $c['id'], $calls);
        $rows = $this->db->fetchAll(
            'SELECT r.call_id, r.user_id, r.created_at, u.firstname, u.lastname
             FROM help_call_responses r
             JOIN users u ON u.id = r.user_id
             WHERE r.call_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')
             ORDER BY r.created_at, u.lastname',
            $ids,
        );
        $byCall = [];
        foreach ($rows as $row) {
            $byCall[(int) $row['call_id']][] = [
                'id' => (int) $row['user_id'],
                'name' => self::name($row['firstname'] ?? null, $row['lastname'] ?? null),
                'at' => (string) $row['created_at'],
            ];
        }
        foreach ($calls as &$call) {
            $call['responses'] = $byCall[(int) $call['id']] ?? [];
        }

        return $calls;
    }

    /**
     * Klingel-Zustand eines Rufs inkl. der nur berechneten Runden „niemand hat
     * zugesagt“.
     *
     * @param array<string, mixed> $call
     * @return array{round: int, reason: string, alert_age: int, extra: int, answered_since_alert: bool, nobody_coming: bool}
     */
    public static function effective(array $call): array
    {
        $round = (int) $call['alert_round'];
        $reason = (string) $call['alert_reason'];
        $alertAge = max(0, (int) $call['alert_age_seconds']);
        $responses = $call['responses'] ?? [];
        $open = $call['status'] === 'open';

        $extra = 0;
        if ($open && $responses === []) {
            $extra = intdiv($alertAge, self::UNANSWERED_SECONDS);
        }
        if ($extra > 0) {
            $round += $extra;
            $reason = 'unanswered';
            $alertAge -= $extra * self::UNANSWERED_SECONDS;
        }

        $answered = false;
        if ($extra === 0) {
            foreach ($responses as $response) {
                if ($response['at'] >= (string) $call['alert_at']) {
                    $answered = true;
                    break;
                }
            }
        }

        return [
            'round' => $round,
            'reason' => $reason,
            'alert_age' => $alertAge,
            'extra' => $extra,
            'answered_since_alert' => $answered,
            'nobody_coming' => $open && $responses === [] && (int) $call['age_seconds'] >= self::UNANSWERED_SECONDS,
        ];
    }

    // ------------------------------------------------------------ Rufe schreiben

    /**
     * Neuer Ruf aus einem Raum. Wirft \DomainException mit einer Meldung für
     * die Oberfläche, wenn für den Raum schon ein Ruf offen ist.
     *
     * @return int ID des Rufs
     */
    public function create(int $callerId, int $roomId, string $category, ?string $message): int
    {
        $edition = $this->edition() ?? throw new \DomainException('Es gibt keine aktive Messe.');
        $room = $this->db->fetchOne(
            'SELECT id, room_number, room_name FROM rooms WHERE id = ? AND edition_id = ?',
            [$roomId, (int) $edition['id']],
        ) ?? throw new \DomainException('Diesen Raum gibt es in der aktiven Messe nicht.');

        $existing = $this->db->fetchValue(
            'SELECT id FROM help_calls WHERE open_room_key = ?',
            [$roomId],
        );
        if ($existing !== null) {
            throw new \DomainException('Für diesen Raum ist schon ein Hilfe-Ruf offen.');
        }

        try {
            return $this->insert(
                (int) $edition['id'],
                $roomId,
                AttendanceService::roomLabel($room['room_number'] ?? null, $room['room_name'] ?? null),
                $callerId,
                $category,
                $message,
                false,
            );
        } catch (\PDOException $e) {
            // Zwei Rufe aus demselben Raum im selben Augenblick: der UNIQUE-Index entscheidet.
            if ($e->getCode() === '23000') {
                throw new \DomainException('Für diesen Raum ist schon ein Hilfe-Ruf offen.');
            }
            throw $e;
        }
    }

    /** Probealarm ohne Raum, an jedem Tag möglich. Höchstens einer gleichzeitig. */
    public function createTest(int $callerId, ?string $message): int
    {
        $edition = $this->edition() ?? throw new \DomainException('Es gibt keine aktive Messe.');
        $open = $this->db->fetchValue(
            'SELECT 1 FROM help_calls WHERE school_id = ? AND edition_id = ? AND is_test = 1 AND status = \'open\'',
            [$this->schoolId, (int) $edition['id']],
        );
        if ($open !== null) {
            throw new \DomainException('Es läuft schon ein Probealarm. Bitte erst schließen.');
        }

        return $this->insert((int) $edition['id'], null, null, $callerId, self::TEST_CATEGORY, $message, true);
    }

    private function insert(
        int $editionId,
        ?int $roomId,
        ?string $roomLabel,
        int $callerId,
        string $category,
        ?string $message,
        bool $isTest,
    ): int {
        $message = $message !== null ? mb_substr(trim($message), 0, self::MAX_MESSAGE) : null;
        $this->db->run(
            'INSERT INTO help_calls
                (school_id, edition_id, room_id, room_label, caller_id, category, message, is_test,
                 alert_round, alert_reason, alert_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, \'new\', NOW(), NOW())',
            [
                $this->schoolId,
                $editionId,
                $roomId,
                $roomLabel,
                $callerId,
                mb_substr($category, 0, self::MAX_CATEGORY),
                $message === '' ? null : $message,
                $isTest ? 1 : 0,
            ],
        );

        return $this->db->lastInsertId();
    }

    /** „Ich komme“. Liefert false, wenn die Zusage schon bestand. */
    public function respond(int $callId, int $userId): bool
    {
        return $this->db->transaction(function () use ($callId, $userId): bool {
            $this->materialize($callId);
            $stmt = $this->db->run(
                'INSERT IGNORE INTO help_call_responses (call_id, user_id, created_at) VALUES (?, ?, NOW())',
                [$callId, $userId],
            );

            return $stmt->rowCount() > 0;
        });
    }

    /** „Brauche mehr Hilfe“: neue Runde, es klingelt erneut bei allen. */
    public function requestMore(int $callId): void
    {
        $this->db->transaction(function () use ($callId): void {
            $this->materialize($callId);
            $this->db->run(
                'UPDATE help_calls
                 SET alert_round = alert_round + 1, alert_reason = \'more\', alert_at = NOW(), more_count = more_count + 1
                 WHERE id = ? AND status = \'open\'',
                [$callId],
            );
        });
    }

    public function close(int $callId, int $userId): void
    {
        $this->db->transaction(function () use ($callId, $userId): void {
            $this->materialize($callId);
            $this->db->run(
                'UPDATE help_calls SET status = \'closed\', closed_at = NOW(), closed_by = ?
                 WHERE id = ? AND status = \'open\'',
                [$userId, $callId],
            );
        });
    }

    /** Hält die berechneten Runden „niemand hat zugesagt“ fest (in einer Transaktion aufrufen). */
    private function materialize(int $callId): void
    {
        $call = $this->db->fetchOne(
            'SELECT c.*, TIMESTAMPDIFF(SECOND, c.created_at, NOW()) AS age_seconds,
                    TIMESTAMPDIFF(SECOND, c.alert_at, NOW()) AS alert_age_seconds
             FROM help_calls c WHERE c.id = ? FOR UPDATE',
            [$callId],
        );
        if ($call === null) {
            return;
        }
        $call['responses'] = $this->db->fetchAll(
            'SELECT user_id AS id, created_at AS at FROM help_call_responses WHERE call_id = ?',
            [$callId],
        );
        $effective = self::effective($call);
        if ($effective['extra'] > 0) {
            $this->db->run(
                'UPDATE help_calls
                 SET alert_round = ?, alert_reason = \'unanswered\',
                     alert_at = DATE_ADD(alert_at, INTERVAL ? SECOND)
                 WHERE id = ?',
                [$effective['round'], $effective['extra'] * self::UNANSWERED_SECONDS, $callId],
            );
        }
    }

    // ------------------------------------------------------------ Dienst (App)

    /** Gerät meldet sich „Im Dienst“ (bei jeder Abfrage des Dienstes). */
    public function touchDuty(int $userId): void
    {
        $this->db->run(
            'INSERT INTO help_call_duty (user_id, school_id, started_at, last_seen_at) VALUES (?, ?, NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                started_at = IF(last_seen_at < NOW() - INTERVAL ? SECOND, NOW(), started_at),
                last_seen_at = NOW()',
            [$userId, $this->schoolId, self::DUTY_ONLINE_SECONDS],
        );
    }

    /** @return bool true, wenn das Konto vorher im Dienst war */
    public function endDuty(int $userId): bool
    {
        return $this->db->run(
            'DELETE FROM help_call_duty WHERE user_id = ? AND school_id = ?',
            [$userId, $this->schoolId],
        )->rowCount() > 0;
    }

    public function isOnDuty(int $userId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM help_call_duty
             WHERE user_id = ? AND school_id = ? AND last_seen_at >= NOW() - INTERVAL ? SECOND',
            [$userId, $this->schoolId, self::DUTY_ONLINE_SECONDS],
        ) !== null;
    }

    /** @return list<array{id: int, name: string, since: string}> Wer gerade im Dienst ist. */
    public function onDuty(): array
    {
        $rows = $this->db->fetchAll(
            'SELECT d.user_id, d.started_at, u.firstname, u.lastname
             FROM help_call_duty d
             JOIN users u ON u.id = d.user_id
             WHERE d.school_id = ? AND d.last_seen_at >= NOW() - INTERVAL ? SECOND
             ORDER BY u.lastname, u.firstname',
            [$this->schoolId, self::DUTY_ONLINE_SECONDS],
        );

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['user_id'],
            'name' => self::name($row['firstname'] ?? null, $row['lastname'] ?? null),
            'since' => (string) $row['started_at'],
        ], $rows);
    }

    // ------------------------------------------------------------ Hilfen

    public static function name(?string $firstname, ?string $lastname): string
    {
        $name = trim((string) $firstname . ' ' . (string) $lastname);

        return $name !== '' ? $name : 'Unbekannt';
    }

    /** Kurzbeschreibung für Audit-Log und Benachrichtigungen. */
    public static function describe(array $call): string
    {
        if ((int) $call['is_test'] === 1) {
            return 'TEST: ' . self::TEST_CATEGORY;
        }

        return 'Raum ' . ((string) ($call['room_label'] ?? '') ?: '?') . ', ' . (string) $call['category'];
    }
}
