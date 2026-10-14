<?php
/**
 * Verwaltung der Aussteller-Konten & Einladungen einer Schule.
 * Erwartet: $exhibitors, $linksByExhibitor, $invitesByExhibitor, $requests, $inviteBaseUrl.
 */
$statusLabels = [
    'active' => 'Aktiv',
    'cancelled_by_exhibitor' => 'Vom Aussteller abgesagt',
    'cancelled_by_school' => 'Von der Schule beendet',
    'removed_by_admin' => 'Entfernt',
];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Aussteller-Konten</h1>
        <p class="page-sub">
            Unternehmen einladen, Rechte vergeben und Absagen bearbeiten.
            Es werden keine E-Mails versendet: Den Einladungslink gibst du selbst an das Unternehmen weiter.
        </p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" type="button" data-open-modal="einladen">➕ Unternehmen einladen</button>
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
                                          data-confirm="Absage bestätigen? Der Aussteller wird ausgeblendet, die Buchungen der Schüler:innen ruhen, bis ihr neu zuteilt.">
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
        <?php
        $exhibitorId = (int) $exhibitor['id'];
        $links = $linksByExhibitor[$exhibitorId] ?? [];
        $invites = $invitesByExhibitor[$exhibitorId] ?? [];
        ?>
        <div class="card">
            <div class="card-header">
                <h3 style="margin:0;"><?= e($exhibitor['name']) ?></h3>
                <?php if ((int) $exhibitor['active'] !== 1): ?>
                    <span class="badge badge-danger">inaktiv</span>
                <?php endif; ?>
                <span class="badge badge-info"><?= e((string) count($links)) ?> Konto/Konten</span>
                <?php if ($invites !== []): ?>
                    <span class="badge badge-warning"><?= e((string) count($invites)) ?> Einladung(en) offen</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if ($links === []): ?>
                    <p class="text-sm text-soft">Noch kein Konto verknüpft. Konten erscheinen hier, sobald jemand eine Einladung angenommen hat.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>Konto</th>
                                <th>Status</th>
                                <th>Rechte</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($links as $link): ?>
                                <?php
                                $linkId = (int) $link['id'];
                                $isActive = $link['status'] === 'active';
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
                                            <span class="text-sm text-faint">keine</span>
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

                <?php if ($invites !== []): ?>
                    <h4 style="margin:16px 0 8px;">Offene Einladungen</h4>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>Notiz</th>
                                <th>Erstellt</th>
                                <th>Einladungslink</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($invites as $invite): ?>
                                <?php $inviteUrl = $inviteBaseUrl . (string) $invite['token']; ?>
                                <tr>
                                    <td class="text-sm"><?= e((string) ($invite['note'] ?? '')) ?></td>
                                    <td class="text-sm nowrap">
                                        <?= e(format_datetime($invite['created_at'])) ?>
                                        <?php if (!empty($invite['created_by_name'])): ?>
                                            <div class="text-faint">von <?= e($invite['created_by_name']) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ((int) $invite['expired'] === 1): ?>
                                            <span class="text-sm text-danger">Link abgelaufen</span>
                                        <?php else: ?>
                                            <input class="input mono text-sm" type="text" readonly
                                                   value="<?= e($inviteUrl) ?>"
                                                   aria-label="Einladungslink für <?= e($exhibitor['name']) ?>">
                                            <button class="btn btn-sm btn-ghost" type="button"
                                                    data-copy="<?= e($inviteUrl) ?>">📋 Link kopieren</button>
                                            <div class="text-sm text-faint">gültig bis <?= e(format_datetime($invite['expires_at'])) ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="post"
                                              action="<?= e($ctx->schoolUrl('/admin/aussteller-konten/einladung/' . (int) $invite['id'] . '/zurueckziehen')) ?>"
                                              data-confirm="Einladung zurückziehen? Der Link wird sofort ungültig.">
                                            <?= $csrf->field() ?>
                                            <button class="btn btn-sm btn-danger-ghost" type="submit">Zurückziehen</button>
                                        </form>
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
            <h3>Unternehmen einladen</h3>
            <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="invite-exhibitor">Unternehmen</label>
                <select class="input" id="invite-exhibitor" name="exhibitor_id" required>
                    <option value="">bitte wählen</option>
                    <?php foreach ($exhibitors as $exhibitor): ?>
                        <option value="<?= e((string) $exhibitor['id']) ?>"><?= e($exhibitor['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label for="invite-note">Notiz (optional)</label>
                <input class="input" type="text" id="invite-note" name="note" maxlength="200"
                       placeholder="z. B. an wen der Link geht">
                <div class="hint">Nur für euch sichtbar, damit ihr offene Links zuordnen könnt.</div>
            </div>
            <div class="alert alert-info" role="status">
                Es entsteht ein Einladungslink, kein Konto. Wer ihn öffnet, meldet sich mit einem
                bestehenden Aussteller-Konto an oder legt ein neues an. Der Link gilt 14 Tage und
                lässt sich nur einmal einlösen.
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Link erstellen</button>
        </div>
    </form>
</dialog>
