<?php
/**
 * Chat eines Orga-Raums. Das Gerüst kommt vom Server, Nachrichten lädt
 * orga-chat.js (Polling). Ohne JavaScript bleibt ein Hinweis stehen.
 * Erwartet: $access, $space, $team, $canWrite, $audience, $unread.
 */
use App\Services\OrgaChat;

$s = static fn (string $path): string => $ctx->schoolUrl($path);
$me = (array) $auth->user();
$config = [
    'messagesUrl' => $s('/api/orga/chat/' . $space['slug']),
    'sendUrl' => $s('/api/orga/chat/' . $space['slug'] . '/senden'),
    'messageUrl' => $s('/api/orga/nachrichten/{id}/{action}'),
    'me' => (string) ($me['username'] ?? ''),
    'canWrite' => $canWrite,
    'reactions' => OrgaChat::REACTIONS,
    'maxBytes' => OrgaChat::ATTACHMENT_MAX_BYTES,
    'audience' => array_map(static fn (array $u): array => ['username' => $u['username'], 'name' => $u['name']], $audience),
];
$sub = match ($space['channel']) {
    'all' => 'Für das gesamte Orga-Team dieses Jahrgangs.',
    'leads' => 'Austausch der Teamleitungen mit der Verwaltung.',
    default => 'Teamchat',
};
$accept = implode(',', array_map(static fn (string $ext): string => '.' . $ext, OrgaChat::ATTACHMENT_EXTENSIONS));
?>
<?= $view->renderPartial('pages/orga/_nav', [
    'space' => $space,
    'active' => 'chat',
    'unread' => $unread,
    'sub' => $sub,
]) ?>

<?php foreach (page_blocks('orga-chat', [
    'hinweis' => 'Hinweis zur Einsicht',
    'chat' => 'Chat',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweis'): ?>
<div class="alert alert-info text-sm" role="note">
    ℹ️ Administrator:innen können diesen Chat einsehen und moderieren.
    <?php if (!$canWrite): ?><strong>Du liest hier nur mit.</strong><?php endif; ?>
</div>

<?php elseif ($blockKey === 'chat'): ?>
<div class="card chat" data-orga-chat data-config="<?= e(json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>">
    <details class="chat-pinned" data-pinned hidden>
        <summary>📌 Angeheftet (<span data-pinned-count>0</span>)</summary>
        <div data-pinned-list></div>
    </details>

    <div class="chat-log" data-log aria-live="polite" aria-label="Nachrichten">
        <noscript><p class="text-faint">Der Chat braucht JavaScript.</p></noscript>
        <button class="btn btn-sm btn-ghost" type="button" data-older hidden style="align-self:center;">Ältere Nachrichten laden</button>
        <div data-messages></div>
    </div>

    <?php if ($canWrite): ?>
        <form class="chat-composer" data-composer>
            <div class="chat-suggest" data-suggest hidden role="listbox" aria-label="Personen erwähnen"></div>
            <div class="chat-composer-bar" data-mode-bar hidden>
                <span data-mode-text></span>
                <button class="btn btn-sm btn-ghost" type="button" data-mode-cancel aria-label="Abbrechen">✕</button>
            </div>
            <div class="chat-composer-bar" data-file-bar hidden>
                <span data-file-text></span>
                <button class="btn btn-sm btn-ghost" type="button" data-file-cancel aria-label="Anhang entfernen">✕</button>
            </div>
            <div class="chat-composer-row">
                <label class="btn" title="Datei anhängen (max. <?= e((string) (OrgaChat::ATTACHMENT_MAX_BYTES / 1048576)) ?> MB)" style="flex-shrink:0;">
                    📎<span class="sr-only" style="position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0);">Datei anhängen</span>
                    <input type="file" data-file accept="<?= e($accept) ?>" hidden>
                </label>
                <textarea class="input" data-input rows="1" maxlength="<?= e((string) OrgaChat::MAX_BODY) ?>"
                          placeholder="Nachricht schreiben … (@name erwähnt, Umschalt+Enter für neue Zeile)" aria-label="Nachricht"></textarea>
                <button class="btn btn-primary" type="submit" data-send style="flex-shrink:0;">Senden</button>
            </div>
        </form>
    <?php endif; ?>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
