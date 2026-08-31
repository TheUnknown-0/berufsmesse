<?php
/**
 * Verwaltung der Aussteller-Konten & Einladungen einer Schule.
 * Erwartet: $exhibitors, $linksByExhibitor, $requests, $inviteBaseUrl.
 */
$statusLabels = [
    'active' => 'Aktiv',
    'cancelled_by_exhibitor' => 'Vom Aussteller abgesagt',
    'cancelled_by_school' => 'Von der Schule beendet',
    'removed_by_admin' => 'Entfernt',
];
$now = new DateTimeImmutable('now');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Aussteller-Konten</h1>
        <p class="page-sub">
            Einladungen erstellen, Rechte vergeben und Absagen bearbeiten.
            Es werden keine E-Mails versendet — den Einladungslink verteilst du selbst.
        </p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" type="button" data-open-modal="einladen">➕ Konto einladen</button>
    </div>
</div>

<?php foreach (page_blocks('admin-aussteller-konten', [
    'absage-anfragen' => 'Offene Absage-Anfragen',
    'hinweis' => 'Hinweis ohne Aussteller',
    'konten' => 'Konten je Unternehmen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'absage-anfragen'): ?>
<?php if ($requests !== []): ?>
    <div class="card">
        <div class="card-header">
            <h3 style="margin:0;">Offene Absage-Anfragen</h3>
            <span class="badge badge-warning"><?= e((string) count($requests)) ?></span>
        </div>
        <div class="card-body">
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Unternehmen</th>
                        <th>Gestellt von</th>
                        <th>Begründung</th>
                        <th>Eingegangen</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $request): ?>
                        <tr>
                            <td><strong><?= e($request['exhibitor_name']) ?></strong></td>
                            <td><?= e($request['username'] ?? 'unbekannt') ?></td>
                            <td class="text-sm"><?= e($request['reason'] ?? '—') ?></td>
                            <td class="nowrap"><?= e(format_datetime($request['created_at'])) ?></td>
                            <td>
                                <div class="row-actions">
                                    <form method="post"
                                          action="<?= e($ctx->schoolUrl('/admin/aussteller-konten/absage/' . (int) $request['id'] . '/bestaetigen')) ?>"
                                          data-confirm="Absage bestätigen? Der Aussteller wird deaktiviert und alle Anmeldungen werden aufgelöst.">
                                        <?= $csrf->field() ?>
                                        <button class="btn btn-sm btn-danger" type="submit">Bestätigen</button>
                                    </form>
                                    <form method="post"
                                          action="<?= e($ctx->schoolUrl('/admin/aussteller-konten/absage/' . (int) $request['id'] . '/ablehnen')) ?>"
                                          data-confirm="Absage ablehnen?">
                                        <?= $csrf->field() ?>
                                        <button class="btn btn-sm btn-ghost" type="submit">Ablehnen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'hinweis'): ?>
<?php if ($exhibitors === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🏢</div>
        <p>Für diese Messe sind noch keine Aussteller angelegt.</p>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'konten'): ?>
    <?php foreach ($exhibitors as $exhibitor): ?>
        <?php $links = $linksByExhibitor[(int) $exhibitor['id']] ?? []; ?>
        <div class="card">
            <div class="card-header">
                <h3 style="margin:0;"><?= e($exhibitor['name']) ?></h3>
                <?php if ((int) $exhibitor['active'] !== 1): ?>
                    <span class="badge badge-danger">inaktiv</span>
                <?php endif; ?>
                <span class="badge badge-info"><?= e((string) count($links)) ?> Konto/Konten</span>
            </div>
            <div class="card-body">
                <?php if ($links === []): ?>
                    <p class="text-sm text-soft">Noch kein Konto verknüpft.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>Konto</th>
                                <th>Status</th>
                                <th>Rechte</th>
                                <th>Einladungslink</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($links as $link): ?>
                                <?php
                                $linkId = (int) $link['id'];
                                $isActive = $link['status'] === 'active';
                                $accepted = (int) $link['invite_accepted'] === 1;
                                $expired = false;
                                if (!empty($link['invite_expires'])) {
                                    try {
                                        $expired = new DateTimeImmutable((string) $link['invite_expires']) < $now;
                                    } catch (Exception) {
                                        $expired = false;
                                    }
                                }
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= e($link['username']) ?></strong>
                                        <?php $name = trim(($link['firstname'] ?? '') . ' ' . ($link['lastname'] ?? '')); ?>
                                        <?php if ($name !== ''): ?>
                                            <div class="text-sm text-soft"><?= e($name) ?></div>
                                        <?php endif; ?>
                                        <?php if (!empty($link['email'])): ?>
                                            <div class="text-sm text-faint"><?= e($link['email']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!$isActive): ?>
                                            <span class="badge badge-danger"><?= e($statusLabels[$link['status']] ?? $link['status']) ?></span>
                                            <?php if (!empty($link['cancel_reason'])): ?>
                                                <div class="text-sm text-soft"><?= e($link['cancel_reason']) ?></div>
                                            <?php endif; ?>
                                        <?php elseif (!$accepted): ?>
                                            <span class="badge badge-warning">Einladung ausstehend</span>
                                            <?php if ($expired): ?>
                                                <div class="text-sm text-danger">Link abgelaufen</div>
                                            <?php elseif (!empty($link['invite_expires'])): ?>
                                                <div class="text-sm text-faint">gültig bis <?= e(format_datetime($link['invite_expires'])) ?></div>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="badge badge-success">Aktiv</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isActive): ?>
                                            <form method="post"
                                                  action="<?= e($ctx->schoolUrl('/admin/aussteller-konten/' . $linkId . '/rechte')) ?>">
                                                <?= $csrf->field() ?>
                                                <label class="checkbox-row">
                                                    <input type="checkbox" name="can_edit_profile" value="1"
                                                           <?= (int) $link['can_edit_profile'] === 1 ? 'checked' : '' ?>>
                                                    <span>Profil bearbeiten</span>
                                                </label>
                                                <label class="checkbox-row">
                                                    <input type="checkbox" name="can_manage_documents" value="1"
                                                           <?= (int) $link['can_manage_documents'] === 1 ? 'checked' : '' ?>>
                                                    <span>Dokumente verwalten</span>
                                                </label>
                                                <button class="btn btn-sm btn-ghost" type="submit">Rechte speichern</button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-sm text-faint">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($isActive && !$accepted && !empty($link['invite_token'])): ?>
                                            <?php $inviteUrl = $inviteBaseUrl . $link['invite_token']; ?>
                                            <input class="input mono text-sm" type="text" readonly
                                                   value="<?= e($inviteUrl) ?>"
                                                   aria-label="Einladungslink für <?= e($link['username']) ?>">
                                            <button class="btn btn-sm btn-ghost" type="button"
                                                    data-copy="<?= e($inviteUrl) ?>">📋 Link kopieren</button>
                                        <?php else: ?>
                                            <span class="text-sm text-faint">—</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <?php if ($isActive): ?>
                                                <form method="post"
                                                      action="<?= e($ctx->schoolUrl('/admin/aussteller-konten/' . $linkId . '/entfernen')) ?>"
                                                      data-confirm="Verknüpfung wirklich entfernen? Das Konto verliert den Zugriff auf dieses Unternehmen.">
                                                    <?= $csrf->field() ?>
                                                    <button class="btn btn-sm btn-danger-ghost" type="submit">Entfernen</button>
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
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<dialog class="modal" id="einladen">
    <form method="post" action="<?= e($ctx->schoolUrl('/admin/aussteller-konten/einladen')) ?>">
        <?= $csrf->field() ?>
        <div class="modal-header">
            <h3>Aussteller-Konto einladen</h3>
            <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="invite-exhibitor">Unternehmen</label>
                <select class="input" id="invite-exhibitor" name="exhibitor_id" required>
                    <option value="">— bitte wählen —</option>
                    <?php foreach ($exhibitors as $exhibitor): ?>
                        <option value="<?= e((string) $exhibitor['id']) ?>"><?= e($exhibitor['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="invite-username">Benutzername oder E-Mail</label>
                <input class="input" type="text" id="invite-username" name="username" required
                       pattern="[a-zA-Z0-9._@+\-]{3,100}" list="exhibitor-accounts">
                <datalist id="exhibitor-accounts">
                    <?php foreach ($existingAccounts ?? [] as $acc): ?>
                        <option value="<?= e($acc['username']) ?>"><?= e(trim(($acc['firstname'] ?? '') . ' ' . ($acc['lastname'] ?? ''))) ?></option>
                    <?php endforeach; ?>
                </datalist>
                <div class="hint">
                    Existiert bereits ein Aussteller-Konto mit diesem Namen (Vorschläge beim Tippen), wird es
                    <strong>direkt verknüpft</strong> — ohne neue Einladung. Neue Namen erhalten einen Einladungslink.
                </div>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="invite-firstname">Vorname</label>
                    <input class="input" type="text" id="invite-firstname" name="firstname" maxlength="100">
                </div>
                <div class="field">
                    <label for="invite-lastname">Nachname</label>
                    <input class="input" type="text" id="invite-lastname" name="lastname" maxlength="100">
                </div>
            </div>
            <div class="field">
                <label for="invite-email">E-Mail (optional)</label>
                <input class="input" type="email" id="invite-email" name="email" maxlength="255">
            </div>
            <div class="alert alert-info" role="status">
                Nach dem Anlegen erscheint der Einladungslink in der Liste. Er ist 14 Tage gültig.
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Einladung erstellen</button>
        </div>
    </form>
</dialog>
