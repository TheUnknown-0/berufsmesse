<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * Chat des Orga-Tools: Nachrichten laden, für die Oberfläche aufbereiten,
 * Lesestand führen und @Erwähnungen auflösen.
 *
 * Abgleich per Polling: Der Client merkt sich die höchste bekannte ID und
 * den Serverzeitpunkt des letzten Abrufs. Neue Nachrichten kommen über
 * `id > after`, geänderte (bearbeitet, gelöscht, angeheftet, Reaktion) über
 * `updated_at >= since`. Überschneidungen sind gewollt, der Client ersetzt
 * Nachrichten anhand ihrer ID.
 *
 * Zugriffsprüfungen stehen NICHT hier, sondern in OrgaAccess.
 */
final class OrgaChat
{
    /** Erlaubte Reaktionen (feste Auswahl, damit keine beliebigen Zeichen gespeichert werden). */
    public const REACTIONS = ['👍', '✅', '❤️', '😂', '👀', '🎉'];

    /** Anhänge: klein halten, keine SVG (Script-Risiko), keine Office-Makroformate. */
    public const ATTACHMENT_EXTENSIONS = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'ppt', 'pptx'];
    public const ATTACHMENT_MAX_BYTES = 5 * 1024 * 1024;

    public const MAX_BODY = 4000;
    private const PAGE_SIZE = 50;

    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Nachrichten eines Raums.
     *
     * @param array{channel: string, team_id: int|null} $space
     * @return list<array<string, mixed>> Rohzeilen, aufsteigend nach ID
     */
    public function fetch(int $editionId, array $space, int $after = 0, ?string $since = null, int $before = 0): array
    {
        [$where, $params] = $this->spaceWhere($editionId, $space);

        if ($before > 0) {
            // Ältere Nachrichten nachladen
            $sql = "SELECT * FROM ({$this->select()} WHERE {$where} AND m.id < ? ORDER BY m.id DESC LIMIT " . self::PAGE_SIZE . ') x ORDER BY id';
            $params[] = $before;
        } elseif ($after > 0) {
            $sql = "{$this->select()} WHERE {$where} AND (m.id > ?" . ($since !== null ? ' OR m.updated_at >= ?' : '') . ') ORDER BY m.id';
            $params[] = $after;
            if ($since !== null) {
                $params[] = $since;
            }
        } else {
            // Erstaufruf: die jüngsten Nachrichten
            $sql = "SELECT * FROM ({$this->select()} WHERE {$where} ORDER BY m.id DESC LIMIT " . self::PAGE_SIZE . ') x ORDER BY id';
        }

        return $this->db->fetchAll($sql, $params);
    }

    /**
     * @param array{channel: string, team_id: int|null} $space
     * @return list<array<string, mixed>>
     */
    public function pinned(int $editionId, array $space): array
    {
        [$where, $params] = $this->spaceWhere($editionId, $space);

        return $this->db->fetchAll(
            "{$this->select()} WHERE {$where} AND m.is_pinned = 1 AND m.deleted_at IS NULL ORDER BY m.id DESC LIMIT 20",
            $params,
        );
    }

    /**
     * Gibt es ältere Nachrichten als die angegebene ID?
     *
     * @param array{channel: string, team_id: int|null} $space
     */
    public function hasOlder(int $editionId, array $space, int $beforeId): bool
    {
        [$where, $params] = $this->spaceWhere($editionId, $space);
        $params[] = $beforeId;

        return $this->db->fetchValue("SELECT 1 FROM orga_messages m WHERE {$where} AND m.id < ? LIMIT 1", $params) !== null;
    }

    /**
     * Eine Nachricht dieser Edition laden (für Aktionen per ID).
     *
     * @return array<string, mixed>|null
     */
    public function find(int $editionId, int $messageId): ?array
    {
        return $this->db->fetchOne(
            "{$this->select()} WHERE m.id = ? AND m.edition_id = ?",
            [$messageId, $editionId],
        );
    }

    /** Aktueller Datenbank-Zeitpunkt als Abgleichmarke für den nächsten Abruf. */
    public function now(): string
    {
        return (string) $this->db->fetchValue('SELECT DATE_FORMAT(NOW(3), \'%Y-%m-%d %H:%i:%s.%f\')');
    }

    /**
     * Legt eine Nachricht an und gibt ihre ID zurück.
     *
     * @param array{channel: string, team_id: int|null} $space
     * @param array{filename: string, original_name: string, mime: string, size: int}|null $attachment
     */
    public function send(int $editionId, array $space, int $userId, string $body, ?int $replyToId, ?array $attachment): int
    {
        $this->db->run(
            'INSERT INTO orga_messages
                (edition_id, channel, team_id, user_id, reply_to_id, body,
                 attachment_file, attachment_name, attachment_mime, attachment_size)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $editionId,
                $space['channel'],
                $space['team_id'],
                $userId,
                $replyToId,
                $body,
                $attachment['filename'] ?? null,
                isset($attachment['original_name']) ? mb_substr($attachment['original_name'], 0, 255) : null,
                $attachment['mime'] ?? null,
                $attachment['size'] ?? null,
            ],
        );

        return $this->db->lastInsertId();
    }

    /**
     * Prüft, ob eine Antwort-Referenz im selben Raum liegt.
     *
     * @param array{channel: string, team_id: int|null} $space
     */
    public function isInSpace(int $editionId, array $space, int $messageId): bool
    {
        [$where, $params] = $this->spaceWhere($editionId, $space);
        $params[] = $messageId;

        return $this->db->fetchValue("SELECT 1 FROM orga_messages m WHERE {$where} AND m.id = ?", $params) !== null;
    }

    /** Reaktion umschalten; gibt zurück, ob sie jetzt gesetzt ist. */
    public function toggleReaction(int $messageId, int $userId, string $emoji): bool
    {
        $exists = $this->db->fetchValue(
            'SELECT 1 FROM orga_message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?',
            [$messageId, $userId, $emoji],
        ) !== null;

        if ($exists) {
            $this->db->run(
                'DELETE FROM orga_message_reactions WHERE message_id = ? AND user_id = ? AND emoji = ?',
                [$messageId, $userId, $emoji],
            );
        } else {
            $this->db->run(
                'INSERT INTO orga_message_reactions (message_id, user_id, emoji) VALUES (?, ?, ?)',
                [$messageId, $userId, $emoji],
            );
        }
        $this->touch($messageId);

        return !$exists;
    }

    /** Markiert eine Nachricht als geändert, damit Clients sie neu abrufen. */
    public function touch(int $messageId): void
    {
        $this->db->run('UPDATE orga_messages SET updated_at = NOW(3) WHERE id = ?', [$messageId]);
    }

    /**
     * Lesestand hochsetzen (nie zurück).
     *
     * @param array{key: string} $space
     */
    public function markRead(int $userId, int $editionId, array $space, int $lastId): void
    {
        if ($lastId <= 0) {
            return;
        }
        $this->db->run(
            'INSERT INTO orga_chat_reads (user_id, edition_id, space_key, last_read_id)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE last_read_id = GREATEST(last_read_id, VALUES(last_read_id))',
            [$userId, $editionId, $space['key'], $lastId],
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array<int, list<array{emoji: string, count: int, mine: bool, names: list<string>}>> message_id => Reaktionen
     */
    public function reactionsFor(array $rows, int $userId): array
    {
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $rows);
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $result = [];
        foreach ($this->db->fetchAll(
            "SELECT r.message_id, r.emoji, r.user_id, u.firstname, u.lastname, u.username
             FROM orga_message_reactions r
             JOIN users u ON u.id = r.user_id
             WHERE r.message_id IN ({$placeholders})
             ORDER BY r.created_at",
            $ids,
        ) as $row) {
            $mid = (int) $row['message_id'];
            $emoji = (string) $row['emoji'];
            $result[$mid][$emoji] ??= ['emoji' => $emoji, 'count' => 0, 'mine' => false, 'names' => []];
            $result[$mid][$emoji]['count']++;
            $result[$mid][$emoji]['names'][] = OrgaAccess::displayName($row);
            if ((int) $row['user_id'] === $userId) {
                $result[$mid][$emoji]['mine'] = true;
            }
        }

        return array_map('array_values', $result);
    }

    /**
     * Findet @benutzername-Erwähnungen, die zur Zielgruppe des Raums gehören.
     *
     * @param list<array{id: int, username: string, name: string}> $audience
     * @return list<int>
     */
    public static function mentionedUserIds(string $body, array $audience): array
    {
        if (preg_match_all('/(?<![\p{L}\p{N}_])@([\p{L}\p{N}._-]{2,100})/u', $body, $matches) < 1) {
            return [];
        }

        $wanted = array_map(static fn (string $u): string => mb_strtolower(rtrim($u, '.')), $matches[1]);
        $ids = [];
        foreach ($audience as $user) {
            if (in_array(mb_strtolower($user['username']), $wanted, true)) {
                $ids[] = $user['id'];
            }
        }

        return array_values(array_unique($ids));
    }

    /** Kurzfassung für Antwort-Zitate und Benachrichtigungen. */
    public static function snippet(string $body, int $length = 80): string
    {
        $flat = trim((string) preg_replace('/\s+/u', ' ', $body));

        return mb_strlen($flat) > $length ? mb_substr($flat, 0, $length - 1) . '…' : $flat;
    }

    // ---------------------------------------------------------- Intern

    private function select(): string
    {
        return 'SELECT m.*, u.username, u.firstname, u.lastname, u.role AS author_role,
                       rm.body AS reply_body, rm.deleted_at AS reply_deleted_at,
                       ru.username AS reply_username, ru.firstname AS reply_firstname, ru.lastname AS reply_lastname
                FROM orga_messages m
                LEFT JOIN users u ON u.id = m.user_id
                LEFT JOIN orga_messages rm ON rm.id = m.reply_to_id
                LEFT JOIN users ru ON ru.id = rm.user_id';
    }

    /**
     * @param array{channel: string, team_id: int|null} $space
     * @return array{string, list<mixed>}
     */
    private function spaceWhere(int $editionId, array $space): array
    {
        if ($space['channel'] === 'team') {
            return ["m.edition_id = ? AND m.channel = 'team' AND m.team_id = ?", [$editionId, (int) $space['team_id']]];
        }

        return ['m.edition_id = ? AND m.channel = ? AND m.team_id IS NULL', [$editionId, $space['channel']]];
    }
}
