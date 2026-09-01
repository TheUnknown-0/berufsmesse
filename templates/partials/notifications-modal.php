<?php
/**
 * Benachrichtigungs-Modal: zeigt ungelesene login_notifications beim
 * nächsten Seitenaufruf. Wird vom Layout nach dem Flash-Partial gerendert.
 * Gelesen-Markierung erfolgt per JS (notifications.js) über
 * POST /api/benachrichtigungen/gelesen.
 */

use App\Services\Notifications;

$notifUser = $auth->user();
if ($notifUser === null) {
    return;
}

$notifItems = (new Notifications($ctx->db))->unreadFor((int) $notifUser['id']);
if ($notifItems === []) {
    return;
}

$notifIcons = [
    'exhibitor_cancelled' => '🚫',
    'school_cancelled' => '🏫',
    'cancellation_request' => '❓',
    'info' => 'ℹ️',
];
$notifClasses = [
    'exhibitor_cancelled' => 'alert-error',
    'school_cancelled' => 'alert-warning',
    'cancellation_request' => 'alert-warning',
    'info' => 'alert-info',
];
?>
<?php
/*
 * Kennung des aktuellen Hinweis-Stands. Klickt jemand das Modal weg, merkt
 * sich das JS diese Kennung für die Sitzung und drängt sich nicht auf jeder
 * Folgeseite erneut auf. Kommt ein neuer Hinweis dazu, ändert sich die
 * Kennung und das Modal meldet sich wieder.
 */
$notifStamp = implode('-', array_map(static fn (array $n): string => (string) $n['id'], $notifItems));
?>
<dialog class="modal" id="notifications-modal"
        data-notifications-stamp="<?= e($notifStamp) ?>"
        data-notifications-url="<?= e($ctx->url('/api/benachrichtigungen/gelesen')) ?>">
    <div class="modal-header">
        <h3>Neue Hinweise (<?= e((string) count($notifItems)) ?>)</h3>
        <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
    </div>
    <div class="modal-body">
        <div class="stack">
            <?php foreach ($notifItems as $notifItem): ?>
                <div class="alert <?= e($notifClasses[$notifItem['type']] ?? 'alert-info') ?>" role="status">
                    <span aria-hidden="true"><?= $notifIcons[$notifItem['type']] ?? 'ℹ️' ?></span>
                    <?= e($notifItem['message']) ?>
                    <div class="text-sm text-faint"><?= e(format_datetime($notifItem['created_at'])) ?></div>
                    <?php if (!empty($notifItem['action_url'])): ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e($notifItem['action_url']) ?>">Ansehen</a>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="modal-footer">
        <button class="btn btn-primary" type="button" data-notifications-read>Alles gelesen</button>
    </div>
</dialog>
<?php /* defer: läuft erst nach app.js (BM.fetchJson) und vollständigem DOM */ ?>
<script src="<?= e($ctx->url('/assets/js/notifications.js')) ?>" defer></script>
