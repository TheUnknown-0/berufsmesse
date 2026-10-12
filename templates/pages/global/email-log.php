<?php
/**
 * Global-Admin — E-Mail-Versand: Test-Mail und Protokoll (Anmeldecodes, Test-Mails).
 * Erwartet: $configured (bool), $log (list<array<string, mixed>>).
 */
$statusLabels = ['sent' => 'Versendet', 'failed' => 'Fehlgeschlagen'];
$statusBadges = ['sent' => 'badge-success', 'failed' => 'badge-danger'];
$templateLabels = ['login_code' => 'Anmeldecode', 'test' => 'Test-Mail'];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">System</div>
        <h1 class="page-title">E-Mail-Versand</h1>
        <p class="page-sub">SMTP-Konfiguration testen und protokollierte Versände einsehen.</p>
    </div>
</div>

<?= $view->renderPartial('pages/global/_nav', ['active' => '/global-admin/email-log']) ?>

<?php if (!$configured): ?>
    <div class="alert alert-warning">
        E-Mail-Versand ist nicht konfiguriert (MAIL_HOST fehlt in der Umgebung). Die Anmeldung per Code bleibt
        solange deaktiviert; Test-Mails können nicht verschickt werden.
    </div>
<?php endif; ?>

<div class="card mb-2">
    <div class="card-header"><h3>✉️ Test-Mail senden</h3></div>
    <div class="card-body">
        <form method="post" action="<?= e($ctx->url('/global-admin/email-log/test')) ?>" class="cluster" style="align-items:flex-end;">
            <?= $csrf->field() ?>
            <div class="field mb-0" style="flex:1;min-width:280px;">
                <label for="to">Empfänger-Adresse</label>
                <input class="input" type="email" id="to" name="to" required placeholder="name@beispiel.de" <?= !$configured ? 'disabled' : '' ?>>
            </div>
            <button class="btn btn-primary" type="submit" <?= !$configured ? 'disabled' : '' ?>>Senden</button>
        </form>
    </div>
</div>

<?php if ($log === []): ?>
    <div class="empty-state">
        <div class="empty-icon">📭</div>
        <p>Es wurden noch keine E-Mails versendet.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Zeitpunkt</th>
                <th>Empfänger</th>
                <th>Betreff</th>
                <th>Vorlage</th>
                <th>Status</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($log as $row): ?>
                <tr>
                    <td class="nowrap mono"><?= e(format_datetime($row['created_at'])) ?></td>
                    <td>
                        <?= e($row['recipient']) ?>
                        <?php if (!empty($row['firstname']) || !empty($row['lastname'])): ?>
                            <span class="text-sm text-soft">(<?= e(trim($row['firstname'] . ' ' . $row['lastname'])) ?>)</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($row['subject']) ?></td>
                    <td><span class="badge badge-info"><?= e($templateLabels[$row['template']] ?? $row['template']) ?></span></td>
                    <td>
                        <span class="badge <?= e($statusBadges[$row['status']] ?? 'badge-info') ?>">
                            <?= e($statusLabels[$row['status']] ?? $row['status']) ?>
                        </span>
                        <?php if ($row['status'] === 'failed' && !empty($row['error'])): ?>
                            <div class="text-sm text-danger"><?= e($row['error']) ?></div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
