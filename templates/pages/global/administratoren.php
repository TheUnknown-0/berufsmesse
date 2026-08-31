<?php
/**
 * Global-Admin — Administrator-Konten (Rolle admin).
 * Erwartet: $admins, $generated.
 */
$listUrl = $ctx->url('/global-admin/administratoren');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">System</div>
        <h1 class="page-title">Administratoren</h1>
        <p class="page-sub">
            Globale Administratoren haben in allen Schulen alle Rechte. Sie werden ausschließlich hier
            angelegt und geändert — in den Benutzerlisten der Schulen tauchen sie nur auf, wenn das
            ausdrücklich gewünscht ist.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e($ctx->url('/global-admin/administratoren/neu')) ?>">➕ Administrator anlegen</a>
    </div>
</div>

<?= $view->renderPartial('pages/global/_nav', ['active' => '/global-admin/administratoren']) ?>

<?php if ($admins === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🛡️</div>
        <p>Noch keine Administrator-Konten angelegt.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Benutzername</th>
                <th>E-Mail</th>
                <th>Schulzuordnung</th>
                <th>In Schulliste</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($admins as $row): ?>
                <?php
                $id = (int) $row['id'];
                $fullName = trim((string) $row['firstname'] . ' ' . (string) $row['lastname']);
                $isSelf = $auth->id() === $id;
                ?>
                <tr>
                    <td>
                        <strong><?= e($fullName !== '' ? $fullName : (string) $row['username']) ?></strong>
                        <?php if ($isSelf): ?>
                            <span class="badge badge-info">du</span>
                        <?php endif; ?>
                    </td>
                    <td class="mono"><?= e($row['username']) ?></td>
                    <td class="text-sm"><?= e((string) ($row['email'] ?? '—')) ?></td>
                    <td class="text-sm">
                        <?php if ($row['school_name'] !== null): ?>
                            <?= e($row['school_name']) ?>
                            <div class="text-soft mono">/<?= e((string) $row['school_slug']) ?>/</div>
                        <?php else: ?>
                            <span class="text-faint">keine (systemweit)</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $row['visible_in_school_list'] === 1): ?>
                            <span class="badge badge-success">sichtbar</span>
                        <?php else: ?>
                            <span class="badge">ausgeblendet</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int) $row['no_password'] === 1): ?>
                            <span class="badge badge-warning">kein Passwort</span>
                        <?php elseif ((int) $row['must_change_password'] === 1): ?>
                            <span class="badge badge-info">Wechsel fällig</span>
                        <?php else: ?>
                            <span class="badge badge-success">aktiv</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost"
                               href="<?= e($listUrl . '/' . $id . '/bearbeiten') ?>">Bearbeiten</a>
                            <button class="btn btn-sm btn-ghost" type="button"
                                    data-open-modal="modal-passwort"
                                    data-reset-action="<?= e($listUrl . '/' . $id . '/passwort') ?>"
                                    data-reset-name="<?= e($fullName !== '' ? $fullName : (string) $row['username']) ?>">🔑 Passwort</button>
                            <?php if (!$isSelf): ?>
                                <form method="post" action="<?= e($listUrl . '/' . $id . '/loeschen') ?>"
                                      data-confirm="Administrator „<?= e($row['username']) ?>“ wirklich löschen?">
                                    <?= $csrf->field() ?>
                                    <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<dialog class="modal" id="modal-passwort">
    <form method="post" action="" data-reset-form>
        <?= $csrf->field() ?>
        <div class="modal-header">
            <h3>Passwort zurücksetzen</h3>
            <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
        </div>
        <div class="modal-body">
            <p class="text-sm text-soft">Konto: <strong data-reset-target>—</strong></p>
            <div class="field">
                <label>Was soll passieren?</label>
                <label class="checkbox-row">
                    <input type="radio" name="modus" value="zufaellig" checked>
                    <span>Zufälliges Passwort erzeugen (wird einmalig angezeigt)</span>
                </label>
                <label class="checkbox-row">
                    <input type="radio" name="modus" value="leeren">
                    <span>Passwort entfernen — das Konto kann sich bis zur Neuvergabe nicht anmelden</span>
                </label>
                <div class="hint">In beiden Fällen muss beim nächsten Login ein neues Passwort gesetzt werden.</div>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Zurücksetzen</button>
        </div>
    </form>
</dialog>

<?php if ($generated !== null): ?>
    <dialog class="modal" id="modal-neues-passwort" data-auto-open="1">
        <div class="modal-header">
            <h3>Neues Passwort</h3>
            <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
        </div>
        <div class="modal-body">
            <div class="alert alert-warning">
                Dieses Passwort wird nur jetzt angezeigt. Notiere es und gib es persönlich weiter.
            </div>
            <p class="text-sm text-soft">
                <?= e($generated['name']) ?> · <span class="mono"><?= e($generated['username']) ?></span>
            </p>
            <div class="field">
                <label for="generiertes-passwort">Passwort</label>
                <input class="input mono" type="text" id="generiertes-passwort" readonly
                       value="<?= e($generated['password']) ?>">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-copy-target="generiertes-passwort">Kopieren</button>
            <button class="btn btn-primary" type="button" data-close-modal>Fertig</button>
        </div>
    </dialog>
<?php endif; ?>
