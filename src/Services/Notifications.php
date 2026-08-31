<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;

/**
 * In-App-Benachrichtigungen (login_notifications).
 *
 * Es wird bewusst KEINE E-Mail versendet — Hinweise erscheinen beim
 * nächsten Seitenaufruf im Benachrichtigungs-Modal.
 */
final class Notifications
{
    /** Gültige Werte des ENUM login_notifications.type. */
    public const TYPES = ['exhibitor_cancelled', 'school_cancelled', 'cancellation_request', 'info'];

    /** Maximal im Modal angezeigte Einträge. */
    private const MODAL_LIMIT = 25;

    public function __construct(private readonly Database $db)
    {
    }

    /** Legt eine Benachrichtigung für einen Nutzer an. */
    public function send(
        int $userId,
        ?int $schoolId,
        string $message,
        string $type = 'info',
        ?int $relatedId = null,
        ?string $actionUrl = null,
    ): void {
        $this->db->run(
            'INSERT INTO login_notifications (user_id, school_id, message, type, related_id, action_url)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $schoolId,
                mb_substr($message, 0, 500),
                in_array($type, self::TYPES, true) ? $type : 'info',
                $relatedId,
                $actionUrl === null ? null : mb_substr($actionUrl, 0, 500),
            ],
        );
    }

    /**
     * Legt dieselbe Benachrichtigung für mehrere Nutzer an.
     *
     * @param list<int> $userIds
     */
    public function sendMany(
        array $userIds,
        ?int $schoolId,
        string $message,
        string $type = 'info',
        ?int $relatedId = null,
        ?string $actionUrl = null,
    ): void {
        foreach (array_unique($userIds) as $userId) {
            $this->send((int) $userId, $schoolId, $message, $type, $relatedId, $actionUrl);
        }
    }

    /** @return list<array<string, mixed>> Ungelesene Benachrichtigungen, neueste zuerst. */
    public function unreadFor(int $userId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM login_notifications
             WHERE user_id = ? AND read_at IS NULL
             ORDER BY created_at DESC, id DESC
             LIMIT ' . self::MODAL_LIMIT,
            [$userId],
        );
    }

    public function unreadCount(int $userId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_notifications WHERE user_id = ? AND read_at IS NULL',
            [$userId],
        );
    }

    /** Markiert alle ungelesenen Benachrichtigungen des Nutzers als gelesen. */
    public function markAllRead(int $userId): int
    {
        return $this->db->run(
            'UPDATE login_notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL',
            [$userId],
        )->rowCount();
    }

    /** Markiert eine einzelne Benachrichtigung des Nutzers als gelesen. */
    public function markRead(int $userId, int $notificationId): void
    {
        $this->db->run(
            'UPDATE login_notifications SET read_at = NOW()
             WHERE id = ? AND user_id = ? AND read_at IS NULL',
            [$notificationId, $userId],
        );
    }
}
