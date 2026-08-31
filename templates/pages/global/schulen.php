<?php
/**
 * Global-Admin — Schulen anlegen, bearbeiten, löschen.
 * Erwartet: $schools, $old.
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">System</div>
        <h1 class="page-title">Schulen</h1>
        <p class="page-sub">Jede Schule ist ein eigener Mandant mit eigener URL.</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" type="button" data-open-modal="schule-neu">➕ Schule anlegen</button>
    </div>
</div>

<?= $view->renderPartial('pages/global/_nav', ['active' => '/global-admin/schulen']) ?>

<?php if ($schools === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🏫</div>
        <p>Noch keine Schulen angelegt.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Schule</th>
                <th>URL</th>
                <th>Kontakt</th>
                <th>Editionen</th>
                <th>Nutzer</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($schools as $school): ?>
                <tr>
                    <td>
                        <strong><?= e($school['name']) ?></strong>
                        <?php if (!empty($school['address'])): ?>
                            <div class="text-sm text-soft"><?= e($school['address']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="mono nowrap">/<?= e($school['slug']) ?>/</td>
                    <td class="text-sm">
                        <?= e($school['contact_email'] ?? '') ?>
                        <?php if (!empty($school['contact_phone'])): ?>
                            <div class="text-soft"><?= e($school['contact_phone']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) $school['edition_count']) ?></td>
                    <td><?= e((string) $school['user_count']) ?></td>
                    <td>
                        <?php if ((int) $school['is_active'] === 1): ?>
                            <span class="badge badge-success">aktiv</span>
                        <?php else: ?>
                            <span class="badge badge-danger">inaktiv</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <button class="btn btn-sm btn-ghost" type="button"
                                    data-open-modal="schule-<?= e((string) $school['id']) ?>">Bearbeiten</button>
                            <button class="btn btn-sm btn-danger-ghost" type="button"
                                    data-open-modal="loeschen-<?= e((string) $school['id']) ?>">Löschen</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<dialog class="modal" id="schule-neu">
    <form method="post" action="<?= e($ctx->url('/global-admin/schulen')) ?>">
        <?= $csrf->field() ?>
        <div class="modal-header">
            <h3>Neue Schule</h3>
            <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="neu-name">Name</label>
                <input class="input" type="text" id="neu-name" name="name" required maxlength="200"
                       value="<?= e($old['name'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="neu-slug">URL-Name</label>
                <input class="input" type="text" id="neu-slug" name="slug" required pattern="[a-z0-9-]{2,100}"
                       value="<?= e($old['slug'] ?? '') ?>">
                <div class="hint">Nur Kleinbuchstaben, Zahlen und Minus. Erscheint als /url-name/ in der Adresse.</div>
            </div>
            <div class="field">
                <label for="neu-address">Adresse</label>
                <input class="input" type="text" id="neu-address" name="address" maxlength="500"
                       value="<?= e($old['address'] ?? '') ?>">
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="neu-email">Kontakt-E-Mail</label>
                    <input class="input" type="email" id="neu-email" name="contact_email" maxlength="255"
                           value="<?= e($old['contact_email'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="neu-phone">Telefon</label>
                    <input class="input" type="text" id="neu-phone" name="contact_phone" maxlength="50"
                           value="<?= e($old['contact_phone'] ?? '') ?>">
                </div>
            </div>
            <label class="checkbox-row">
                <input type="checkbox" name="is_active" value="1" checked>
                <span>Schule ist aktiv (nur aktive Schulen sind erreichbar)</span>
            </label>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Anlegen</button>
        </div>
    </form>
</dialog>

<?php foreach ($schools as $school): ?>
    <dialog class="modal" id="schule-<?= e((string) $school['id']) ?>">
        <form method="post" action="<?= e($ctx->url('/global-admin/schulen/' . (int) $school['id'])) ?>">
            <?= $csrf->field() ?>
            <div class="modal-header">
                <h3>Schule bearbeiten</h3>
                <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
            </div>
            <div class="modal-body">
                <div class="field">
                    <label for="name-<?= e((string) $school['id']) ?>">Name</label>
                    <input class="input" type="text" id="name-<?= e((string) $school['id']) ?>" name="name"
                           required maxlength="200" value="<?= e($school['name']) ?>">
                </div>
                <div class="field">
                    <label for="slug-<?= e((string) $school['id']) ?>">URL-Name</label>
                    <input class="input" type="text" id="slug-<?= e((string) $school['id']) ?>" name="slug"
                           required pattern="[a-z0-9-]{2,100}" value="<?= e($school['slug']) ?>">
                    <div class="hint">Achtung: Bestehende Links auf die alte Adresse funktionieren danach nicht mehr.</div>
                </div>
                <div class="field">
                    <label for="address-<?= e((string) $school['id']) ?>">Adresse</label>
                    <input class="input" type="text" id="address-<?= e((string) $school['id']) ?>" name="address"
                           maxlength="500" value="<?= e($school['address']) ?>">
                </div>
                <div class="form-grid">
                    <div class="field">
                        <label for="email-<?= e((string) $school['id']) ?>">Kontakt-E-Mail</label>
                        <input class="input" type="email" id="email-<?= e((string) $school['id']) ?>" name="contact_email"
                               maxlength="255" value="<?= e($school['contact_email']) ?>">
                    </div>
                    <div class="field">
                        <label for="phone-<?= e((string) $school['id']) ?>">Telefon</label>
                        <input class="input" type="text" id="phone-<?= e((string) $school['id']) ?>" name="contact_phone"
                               maxlength="50" value="<?= e($school['contact_phone']) ?>">
                    </div>
                </div>
                <div class="field">
                    <label for="baseurl-<?= e((string) $school['id']) ?>">Öffentliche Basis-URL (optional)</label>
                    <input class="input" type="url" id="baseurl-<?= e((string) $school['id']) ?>" name="public_base_url"
                           placeholder="https://messe.meine-schule.de"
                           value="<?= e($baseUrls[(int) $school['id']] ?? '') ?>">
                    <div class="hint">Überschreibt die globale Basis-URL für Einladungslinks und QR-Codes dieser Schule.</div>
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="is_active" value="1" <?= (int) $school['is_active'] === 1 ? 'checked' : '' ?>>
                    <span>Schule ist aktiv</span>
                </label>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                <button class="btn btn-primary" type="submit">Speichern</button>
            </div>
        </form>
    </dialog>

    <dialog class="modal" id="loeschen-<?= e((string) $school['id']) ?>">
        <form method="post" action="<?= e($ctx->url('/global-admin/schulen/' . (int) $school['id'] . '/loeschen')) ?>"
              data-confirm="Letzte Warnung: Die Schule und ALLE zugehörigen Daten werden unwiderruflich gelöscht.">
            <?= $csrf->field() ?>
            <div class="modal-header">
                <h3>Schule löschen</h3>
                <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
            </div>
            <div class="modal-body">
                <div class="alert alert-error" role="status">
                    Es werden alle Editionen, Aussteller, Räume, Nutzer, Anmeldungen und Check-ins dieser Schule
                    unwiderruflich gelöscht.
                </div>
                <div class="field">
                    <label for="confirm-<?= e((string) $school['id']) ?>">
                        Tippe zur Bestätigung den Schulnamen ein: <strong><?= e($school['name']) ?></strong>
                    </label>
                    <input class="input" type="text" id="confirm-<?= e((string) $school['id']) ?>"
                           name="confirm_name" required autocomplete="off">
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                <button class="btn btn-danger" type="submit">Endgültig löschen</button>
            </div>
        </form>
    </dialog>
<?php endforeach; ?>
