<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Services\OrgaAccess;
use App\Services\OrgaChat;

/**
 * Orga-Tool: Chats (Team, Gesamt, Leitungsrunde).
 *
 * Die Seite rendert nur das Gerüst; Nachrichten lädt orga-chat.js per
 * Polling über apiMessages(). Alle Texte kommen als Rohtext und werden im
 * Browser ausschließlich per textContent eingesetzt.
 */
final class OrgaChatController extends OrgaBaseController
{
    /** GET /{school}/orga/chat/{space} */
    public function show(array $params): string
    {
        $access = $this->orga($params);
        $space = $this->readableSpace($access, (string) $params['space']);
        $team = $space['team_id'] !== null ? $access->findTeam((int) $space['team_id']) : null;

        return $this->render('pages/orga/chat', [
            'title' => $space['title'] . ' · Chat',
            'access' => $access,
            'space' => $space,
            'team' => $team,
            'canWrite' => $access->canWriteSpace($space),
            'audience' => $access->audience($space),
            'unread' => $access->unreadCounts(),
            'pageScripts' => ['orga-chat.js'],
        ]);
    }

    /**
     * GET /{school}/api/orga/chat/{space}
     *
     * Parameter: after (höchste bekannte ID), since (Abgleichmarke),
     * before (ältere nachladen), gelesen=1 (Lesestand setzen).
     */
    public function apiMessages(array $params): array
    {
        $access = $this->orga($params);
        $space = $this->readableSpace($access, (string) $params['space']);
        $chat = new OrgaChat($this->ctx->db);
        $editionId = $access->editionId();

        $after = max(0, (int) ($_GET['after'] ?? 0));
        $before = max(0, (int) ($_GET['before'] ?? 0));
        $since = $this->sinceParam($_GET['since'] ?? null);

        $now = $chat->now();
        $rows = $chat->fetch($editionId, $space, $after, $since, $before);
        $reactions = $chat->reactionsFor($rows, $access->userId());

        $messages = [];
        $maxId = 0;
        foreach ($rows as $row) {
            $messages[] = $this->serialize($row, $access, $space, $reactions[(int) $row['id']] ?? []);
            $maxId = max($maxId, (int) $row['id']);
        }

        if (($_GET['gelesen'] ?? '') === '1' && $before === 0) {
            $chat->markRead($access->userId(), $editionId, $space, $maxId);
        }

        $result = ['success' => true, 'now' => $now, 'messages' => $messages];

        if ($after === 0 || $before > 0) {
            $oldest = $rows !== [] ? (int) $rows[0]['id'] : 0;
            $result['has_older'] = $oldest > 0 && $chat->hasOlder($editionId, $space, $oldest);
        }
        if ($before === 0) {
            $pinnedRows = $chat->pinned($editionId, $space);
            $result['pinned'] = array_map(
                fn (array $row): array => $this->serialize($row, $access, $space, []),
                $pinnedRows,
            );
        }

        return $result;
    }

    /** POST /{school}/api/orga/chat/{space}/senden (multipart: body, reply_to, file) */
    public function apiSend(array $params): array
    {
        $access = $this->orga($params);
        $space = $this->readableSpace($access, (string) $params['space']);
        if (!$access->canWriteSpace($space)) {
            throw new HttpException(403, 'In diesem Chat kannst du nur mitlesen.');
        }
        $this->requireCsrf();

        $chat = new OrgaChat($this->ctx->db);
        $editionId = $access->editionId();
        $body = mb_substr(trim((string) ($_POST['body'] ?? '')), 0, OrgaChat::MAX_BODY);
        $hasFile = isset($_FILES['file']) && ($_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;

        if ($body === '' && !$hasFile) {
            return $this->jsonError('Die Nachricht ist leer.');
        }

        $replyTo = (int) ($_POST['reply_to'] ?? 0);
        if ($replyTo > 0 && !$chat->isInSpace($editionId, $space, $replyTo)) {
            $replyTo = 0;
        }

        $attachment = null;
        if ($hasFile) {
            $attachment = $this->uploads()->store(
                $_FILES['file'],
                self::ATTACHMENT_DIR,
                OrgaChat::ATTACHMENT_EXTENSIONS,
                OrgaChat::ATTACHMENT_MAX_BYTES,
            );
        }

        $id = $chat->send($editionId, $space, $access->userId(), $body, $replyTo > 0 ? $replyTo : null, $attachment);
        $this->notifyMentions($access, $space, $body, $replyTo, $chat);

        $row = (array) $chat->find($editionId, $id);
        $chat->markRead($access->userId(), $editionId, $space, $id);

        return ['success' => true, 'message' => $this->serialize($row, $access, $space, [])];
    }

    /** POST /{school}/api/orga/nachrichten/{id}/bearbeiten */
    public function apiEdit(array $params): array
    {
        $access = $this->orga($params);
        [$message, $space] = $this->message($access, (int) $params['id']);
        $this->requireCsrf();

        if (!$this->mayChange($access, $message)) {
            throw new HttpException(403, 'Du kannst nur eigene Nachrichten bearbeiten.');
        }
        if ($message['deleted_at'] !== null) {
            return $this->jsonError('Die Nachricht wurde gelöscht.');
        }

        $input = $this->jsonInput();
        $body = mb_substr(trim((string) ($input['body'] ?? '')), 0, OrgaChat::MAX_BODY);
        if ($body === '' && $message['attachment_file'] === null) {
            return $this->jsonError('Die Nachricht ist leer. Zum Entfernen bitte löschen.');
        }

        $this->ctx->db->run(
            'UPDATE orga_messages SET body = ?, edited_at = NOW(), edited_by = ? WHERE id = ?',
            [$body, $access->userId(), (int) $message['id']],
        );
        $this->logModeration($access, $message, $space, 'bearbeitet');

        $chat = new OrgaChat($this->ctx->db);
        $row = (array) $chat->find($access->editionId(), (int) $message['id']);
        $reactions = $chat->reactionsFor([$row], $access->userId());

        return ['success' => true, 'message' => $this->serialize($row, $access, $space, $reactions[(int) $row['id']] ?? [])];
    }

    /** POST /{school}/api/orga/nachrichten/{id}/loeschen */
    public function apiDelete(array $params): array
    {
        $access = $this->orga($params);
        [$message, $space] = $this->message($access, (int) $params['id']);
        $this->requireCsrf();

        if (!$this->mayChange($access, $message)) {
            throw new HttpException(403, 'Du kannst nur eigene Nachrichten löschen.');
        }
        if ($message['deleted_at'] !== null) {
            return ['success' => true];
        }

        // Inhalt wirklich entfernen; nur der Platzhalter bleibt im Verlauf.
        $this->ctx->db->run(
            "UPDATE orga_messages
             SET body = '', attachment_file = NULL, attachment_name = NULL, attachment_mime = NULL,
                 attachment_size = NULL, is_pinned = 0, deleted_at = NOW(), deleted_by = ?
             WHERE id = ?",
            [$access->userId(), (int) $message['id']],
        );
        $this->ctx->db->run('DELETE FROM orga_message_reactions WHERE message_id = ?', [(int) $message['id']]);
        $this->deleteAttachments([$message['attachment_file']]);
        $this->logModeration($access, $message, $space, 'gelöscht');

        return ['success' => true];
    }

    /** POST /{school}/api/orga/nachrichten/{id}/anheften */
    public function apiPin(array $params): array
    {
        $access = $this->orga($params);
        [$message, $space] = $this->message($access, (int) $params['id']);
        $this->requireCsrf();

        if (!$access->canPinIn($space)) {
            throw new HttpException(403, 'Anheften dürfen Teamleitung und Moderation.');
        }
        if ($message['deleted_at'] !== null) {
            return $this->jsonError('Die Nachricht wurde gelöscht.');
        }

        $pinned = (int) $message['is_pinned'] === 1 ? 0 : 1;
        $this->ctx->db->run('UPDATE orga_messages SET is_pinned = ? WHERE id = ?', [$pinned, (int) $message['id']]);
        (new OrgaChat($this->ctx->db))->touch((int) $message['id']);

        return ['success' => true, 'pinned' => $pinned === 1];
    }

    /** POST /{school}/api/orga/nachrichten/{id}/reaktion */
    public function apiReact(array $params): array
    {
        $access = $this->orga($params);
        [$message, $space] = $this->message($access, (int) $params['id']);
        if (!$access->canWriteSpace($space)) {
            throw new HttpException(403, 'In diesem Chat kannst du nur mitlesen.');
        }
        $this->requireCsrf();

        $emoji = (string) ($this->jsonInput()['emoji'] ?? '');
        if (!in_array($emoji, OrgaChat::REACTIONS, true)) {
            return $this->jsonError('Diese Reaktion gibt es nicht.');
        }
        if ($message['deleted_at'] !== null) {
            return $this->jsonError('Die Nachricht wurde gelöscht.');
        }

        $chat = new OrgaChat($this->ctx->db);
        $active = $chat->toggleReaction((int) $message['id'], $access->userId(), $emoji);

        return ['success' => true, 'active' => $active];
    }

    /** GET /{school}/orga/anhang/{id} */
    public function attachment(array $params): string
    {
        $access = $this->orga($params);
        [$message] = $this->message($access, (int) $params['id']);

        if ($message['deleted_at'] !== null || empty($message['attachment_file'])) {
            throw new HttpException(404, 'Der Anhang ist nicht mehr vorhanden.');
        }

        $isImage = str_starts_with((string) $message['attachment_mime'], 'image/');
        $download = ($_GET['download'] ?? '') === '1' || !$isImage;

        $this->uploads()->stream(
            self::ATTACHMENT_DIR,
            (string) $message['attachment_file'],
            $download ? (string) $message['attachment_name'] : null,
        );
    }

    // ---------------------------------------------------------- Helfer

    /**
     * Nachricht dieser Edition samt ihrem Raum; der Nutzer muss den Raum lesen dürfen.
     *
     * @return array{0: array<string, mixed>, 1: array{channel: string, team_id: int|null, key: string, slug: string, title: string, color: string|null}}
     */
    private function message(OrgaAccess $access, int $messageId): array
    {
        $message = (new OrgaChat($this->ctx->db))->find($access->editionId(), $messageId);
        if ($message === null) {
            throw new HttpException(404, 'Diese Nachricht gibt es nicht.');
        }

        $slug = match ((string) $message['channel']) {
            'all' => 'gesamt',
            'leads' => 'leitung',
            default => 'team-' . (int) $message['team_id'],
        };

        return [$message, $this->readableSpace($access, $slug)];
    }

    /** Eigene Nachricht, oder Moderationsrecht. */
    private function mayChange(OrgaAccess $access, array $message): bool
    {
        $own = $message['user_id'] !== null && (int) $message['user_id'] === $access->userId();

        return $own || $access->moderates();
    }

    private function logModeration(OrgaAccess $access, array $message, array $space, string $what): void
    {
        $own = $message['user_id'] !== null && (int) $message['user_id'] === $access->userId();
        if ($own) {
            return;
        }

        $this->ctx->audit->log(
            'Orga-Chat moderiert: Nachricht ' . $what,
            'info',
            sprintf('%s, Nachricht #%d von %s: %s', $space['title'], (int) $message['id'], OrgaAccess::displayName($message), OrgaChat::snippet((string) $message['body'], 120)),
            $this->ctx->schoolId(),
        );
    }

    /** Erwähnte und die Person, auf die geantwortet wurde, benachrichtigen. */
    private function notifyMentions(OrgaAccess $access, array $space, string $body, int $replyTo, OrgaChat $chat): void
    {
        $audience = $access->audience($space);
        $author = OrgaAccess::displayName((array) $this->ctx->auth->user());
        $path = '/orga/chat/' . $space['slug'];

        $mentioned = OrgaChat::mentionedUserIds($body, $audience);
        if ($mentioned !== []) {
            $this->notify(
                $mentioned,
                sprintf('%s hat dich im Chat „%s“ erwähnt: %s', $author, $space['title'], OrgaChat::snippet($body)),
                $path,
            );
        }

        if ($replyTo > 0) {
            $original = $chat->find($access->editionId(), $replyTo);
            $target = $original !== null && $original['user_id'] !== null ? (int) $original['user_id'] : 0;
            $inAudience = in_array($target, array_column($audience, 'id'), true);
            if ($target > 0 && $inAudience && !in_array($target, $mentioned, true)) {
                $this->notify(
                    [$target],
                    sprintf('%s hat dir im Chat „%s“ geantwortet: %s', $author, $space['title'], OrgaChat::snippet($body)),
                    $path,
                );
            }
        }
    }

    /** Abgleichmarke aus dem Client: nur ein gültiger Zeitstempel. */
    private function sinceParam(mixed $raw): ?string
    {
        if (!is_string($raw) || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}(\.\d{1,6})?$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    /**
     * Nachricht für den Browser.
     *
     * @param array<string, mixed> $row
     * @param list<array{emoji: string, count: int, mine: bool, names: list<string>}> $reactions
     * @return array<string, mixed>
     */
    private function serialize(array $row, OrgaAccess $access, array $space, array $reactions): array
    {
        $deleted = $row['deleted_at'] !== null;
        $own = $row['user_id'] !== null && (int) $row['user_id'] === $access->userId();
        $role = (string) ($row['author_role'] ?? '');

        $reply = null;
        if ($row['reply_to_id'] !== null) {
            $reply = [
                'id' => (int) $row['reply_to_id'],
                'author' => $row['reply_username'] !== null
                    ? OrgaAccess::displayName(['firstname' => $row['reply_firstname'], 'lastname' => $row['reply_lastname'], 'username' => $row['reply_username']])
                    : 'Unbekannt',
                'snippet' => $row['reply_deleted_at'] !== null ? 'Nachricht gelöscht' : OrgaChat::snippet((string) $row['reply_body']),
            ];
        }

        $attachment = null;
        if (!$deleted && !empty($row['attachment_file'])) {
            $url = $this->ctx->schoolUrl('/orga/anhang/' . (int) $row['id']);
            $attachment = [
                'url' => $url,
                'download_url' => $url . '?download=1',
                'name' => (string) $row['attachment_name'],
                'size' => (int) $row['attachment_size'],
                'is_image' => str_starts_with((string) $row['attachment_mime'], 'image/'),
            ];
        }

        return [
            'id' => (int) $row['id'],
            'author' => $row['user_id'] !== null ? OrgaAccess::displayName($row) : 'Gelöschtes Konto',
            'username' => (string) ($row['username'] ?? ''),
            'is_admin' => in_array($role, ['admin', 'school_admin'], true),
            'is_own' => $own,
            'body' => $deleted ? null : (string) $row['body'],
            'deleted' => $deleted,
            'edited' => !$deleted && $row['edited_at'] !== null,
            'pinned' => (int) $row['is_pinned'] === 1,
            'created_at' => date('c', (int) strtotime((string) $row['created_at'])),
            'reply' => $reply,
            'attachment' => $attachment,
            'reactions' => $reactions,
            'can_edit' => !$deleted && ($own || $access->moderates()),
            'can_delete' => !$deleted && ($own || $access->moderates()),
            'can_pin' => !$deleted && $access->canPinIn($space),
        ];
    }
}
