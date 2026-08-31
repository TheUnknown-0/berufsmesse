<?php
/**
 * Global-Admin — alle Aussteller-Konten systemweit, inkl. Bearbeitung.
 * Erwartet: $links, $filter, $search, $inviteBaseUrl, $oneTimePassword.
 */
$filters = [
    'alle' => 'Alle',
    'aktiv' => 'Aktiv',
    'einladung' => 'Einladung ausstehend',
    'abgesagt' => 'Abgesagt / entfernt',
];
$statusLabels = [
    'cancelled_by_exhibitor' => 'Vom Aussteller abgesagt',
    'cancelled_by_school' => 'Von der Schule beendet',
    'removed_by_admin' => 'Entfernt',
];
// Konten dedupliziert (ein Konto kann mehrere Verknüpfungen haben)
$accounts = [];
foreach ($links as $link) {
    $accounts[(int) $link['user_id']] = $link;
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">System</div>
        <h1 class="page-title">Aussteller-Konten</h1>
        <p class="page-sub">Alle Verknüpfungen zwischen Aussteller-Konten und Unternehmen — schulübergreifend.</p>
    </div>
</div>

<?= $view->renderPartial('pages/global/_nav', ['active' => '/global-admin/aussteller-konten']) ?>

<?php if ($oneTimePassword !== null): ?>
    <div class="alert alert-warning" role="status">
        Neues Passwort für <strong><?= e($oneTimePassword['username']) ?></strong>:
        <code class="mono"><?= e($oneTimePassword['password']) ?></code>
        — es wird nur dieses eine Mal angezeigt.
    </div>
<?php endif; ?>

<div class="card mb-2">
    <div class="card-pad">
        <form method="get" action="<?= e($ctx->url('/global-admin/aussteller-konten')) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="status">Status</label>
                    <select class="input" id="status" name="status">
                        <?php foreach ($filters as $key => $label): ?>
                            <option value="<?= e($key) ?>" <?= $filter === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="q">Suche</label>
                    <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>"
                           placeholder="Konto, Unternehmen oder Schule">
                </div>
            </div>
            <div class="cluster">
                <button class="btn btn-primary" type="submit">Filtern</button>
                <a class="btn btn-ghost" href="<?= e($ctx->url('/global-admin/aussteller-konten')) ?>">Zurücksetzen</a>
            </div>
        </form>
    </div>
</div>

<?php if ($links === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🔍</div>
        <p>Keine Konten gefunden.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Konto</th>
                <th>Schule</th>
                <th>Unternehmen</th>
                <th>Edition</th>
                <th>Status</th>
                <th>Rechte</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($links as $link): ?>
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
                        <a href="<?= e($ctx->url('/' . $link['school_slug'] . '/admin/aussteller-konten')) ?>">
                            <?= e($link['school_name']) ?>
                        </a>
                    </td>
                    <td><?= e($link['exhibitor_name']) ?></td>
                    <td class="nowrap"><?= e($link['edition_name']) ?> (<?= e((string) $link['year']) ?>)</td>
                    <td>
                        <?php if ($link['status'] !== 'active'): ?>
                            <span class="badge badge-danger"><?= e($statusLabels[$link['status']] ?? $link['status']) ?></span>
                        <?php elseif ((int) $link['invite_accepted'] === 0): ?>
                            <span class="badge badge-warning">Einladung ausstehend</span>
                            <?php if (!empty($link['invite_token'])): ?>
                                <input class="input text-sm mono" style="margin-top:6px;min-width:180px;" readonly
                                       value="<?= e($inviteBaseUrl . $link['invite_token']) ?>"
                                       data-copy-source
                                       aria-label="Einladungslink für <?= e($link['username']) ?>">
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-success">Aktiv</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-soft">
                        <?= (int) $link['can_edit_profile'] === 1 ? 'Profil' : '' ?>
                        <?= (int) $link['can_manage_documents'] === 1 ? 'Dokumente' : '' ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <button class="btn btn-sm" type="button"
                                    data-open-modal="konto-<?= e((string) $link['user_id']) ?>"
                                    title="Konto bearbeiten">✏️</button>
                            <div class="menu">
                                <button class="btn btn-sm" type="button" data-menu-toggle aria-label="Weitere Aktionen">⋯</button>
                                <div class="menu-list" hidden>
                                    <form method="post" action="<?= e($ctx->url('/global-admin/aussteller-konten/verknuepfung/' . (int) $link['id'] . '/erneuern')) ?>">
                                        <?= $csrf->field() ?>
                                        <button class="menu-item" type="submit">🔄 Einladungslink erneuern</button>
                                    </form>
                                    <?php if ($link['status'] === 'active'): ?>
                                        <form method="post" action="<?= e($ctx->url('/global-admin/aussteller-konten/verknuepfung/' . (int) $link['id'] . '/entfernen')) ?>"
                                              data-confirm="Verknüpfung zu <?= e($link['exhibitor_name']) ?> wirklich entfernen?">
                                            <?= $csrf->field() ?>
                                            <button class="menu-item danger" type="submit">✂️ Verknüpfung entfernen</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="<?= e($ctx->url('/global-admin/aussteller-konten/' . (int) $link['user_id'] . '/passwort')) ?>">
                                        <?= $csrf->field() ?>
                                        <button class="menu-item" type="submit">🎲 Zufallspasswort setzen</button>
                                    </form>
                                    <form method="post" action="<?= e($ctx->url('/global-admin/aussteller-konten/' . (int) $link['user_id'] . '/passwort')) ?>"
                                          data-confirm="Passwort wirklich entfernen? Das Konto kann sich danach nicht mehr anmelden.">
                                        <?= $csrf->field() ?>
                                        <input type="hidden" name="mode" value="entfernen">
                                        <button class="menu-item danger" type="submit">🚫 Passwort entfernen</button>
                                    </form>
                                    <form method="post" action="<?= e($ctx->url('/global-admin/aussteller-konten/' . (int) $link['user_id'] . '/loeschen')) ?>"
                                          data-confirm="Konto <?= e($link['username']) ?> samt ALLER Verknüpfungen unwiderruflich löschen?">
                                        <?= $csrf->field() ?>
                                        <button class="menu-item danger" type="submit">🗑️ Konto löschen</button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-sm text-faint">Angezeigt werden maximal 500 Einträge — nutze die Filter, um einzugrenzen.</p>

    <?php foreach ($accounts as $userId => $acc): ?>
        <dialog class="modal" id="konto-<?= e((string) $userId) ?>">
            <form method="post" action="<?= e($ctx->url('/global-admin/aussteller-konten/' . $userId)) ?>">
                <?= $csrf->field() ?>
                <div class="modal-header">
                    <h3>Konto bearbeiten: <?= e($acc['username']) ?></h3>
                    <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="field">
                            <label for="k-user-<?= e((string) $userId) ?>">Benutzername</label>
                            <input class="input" type="text" id="k-user-<?= e((string) $userId) ?>" name="username"
                                   required pattern="[a-zA-Z0-9._@+-]{3,100}" value="<?= e($acc['username']) ?>">
                        </div>
                        <div class="field">
                            <label for="k-mail-<?= e((string) $userId) ?>">E-Mail</label>
                            <input class="input" type="email" id="k-mail-<?= e((string) $userId) ?>" name="email"
                                   maxlength="255" value="<?= e($acc['email'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="k-fn-<?= e((string) $userId) ?>">Vorname</label>
                            <input class="input" type="text" id="k-fn-<?= e((string) $userId) ?>" name="firstname"
                                   maxlength="100" value="<?= e($acc['firstname'] ?? '') ?>">
                        </div>
                        <div class="field">
                            <label for="k-ln-<?= e((string) $userId) ?>">Nachname</label>
                            <input class="input" type="text" id="k-ln-<?= e((string) $userId) ?>" name="lastname"
                                   maxlength="100" value="<?= e($acc['lastname'] ?? '') ?>">
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                    <button class="btn btn-primary" type="submit">Speichern</button>
                </div>
            </form>
        </dialog>
    <?php endforeach; ?>
<?php endif; ?>
