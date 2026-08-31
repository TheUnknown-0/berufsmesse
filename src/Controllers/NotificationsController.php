<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\Notifications;

/**
 * JSON-Endpunkte für das Benachrichtigungs-Modal (login_notifications).
 */
final class NotificationsController extends Controller
{
    /** POST /api/benachrichtigungen/gelesen */
    public function markRead(array $params): array
    {
        $userId = $this->ctx->auth->id();
        if ($userId === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }
        $this->requireCsrf();

        $service = new Notifications($this->ctx->db);
        $id = (int) ($this->jsonBody()['id'] ?? 0);

        if ($id > 0) {
            $service->markRead($userId, $id);
        } else {
            $service->markAllRead($userId);
        }

        return ['success' => true, 'unread' => $service->unreadCount($userId)];
    }

    /** GET /api/benachrichtigungen */
    public function listUnread(array $params): array
    {
        $userId = $this->ctx->auth->id();
        if ($userId === null) {
            return $this->jsonError('Nicht angemeldet.', 401);
        }

        $items = [];
        foreach ((new Notifications($this->ctx->db))->unreadFor($userId) as $row) {
            $items[] = [
                'id' => (int) $row['id'],
                'message' => (string) $row['message'],
                'type' => (string) $row['type'],
                'action_url' => $row['action_url'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return ['success' => true, 'notifications' => $items];
    }

    /** @return array<string, mixed> JSON-Body des Requests (leer bei Formular-POST). */
    private function jsonBody(): array
    {
        $raw = file_get_contents('php://input');
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
