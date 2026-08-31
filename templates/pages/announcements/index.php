<?php
/**
 * Ankündigungen einer Schule verwalten.
 * Erwartet: $announcements, $editing, $types, $targetRoles, $typeLabels, $roleLabels.
 */
$typeBadges = ['info' => 'badge-info', 'warning' => 'badge-warning', 'success' => 'badge-success', 'error' => 'badge-danger'];
$now = new DateTimeImmutable('now');
$formAction = $editing !== null
    ? $ctx->schoolUrl('/admin/ankuendigungen/' . (int) $editing['id'])
    : $ctx->schoolUrl('/admin/ankuendigungen');
$expiresValue = '';
if ($editing !== null && !empty($editing['expires_at'])) {
    $expiresValue = format_date($editing['expires_at'], 'Y-m-d\TH:i');
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Ankündigungen</h1>
        <p class="page-sub">
            Sichtbar sind aktive, nicht abgelaufene Ankündigungen für die jeweilige Zielgruppe.
        </p>
    </div>
</div>

<?php foreach (page_blocks('admin-ankuendigungen', [
    'formular' => 'Formular (neu / bearbeiten)',
    'liste' => 'Ankündigungsliste',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'formular'): ?>
<div class="card">
    <div class="card-header">
        <h3 style="margin:0;"><?= $editing !== null ? 'Ankündigung bearbeiten' : 'Neue Ankündigung' ?></h3>
        <?php if ($editing !== null): ?>
            <a class="btn btn-sm btn-ghost" href="<?= e($ctx->schoolUrl('/admin/ankuendigungen')) ?>">Abbrechen</a>
        <?php endif; ?>
    </div>
    <form method="post" action="<?= e($formAction) ?>">
        <?= $csrf->field() ?>
        <div class="card-body">
            <div class="field">
                <label for="title">Titel</label>
                <input class="input" type="text" id="title" name="title" required maxlength="200"
                       value="<?= e($editing['title'] ?? '') ?>">
            </div>
            <div class="field">
                <label for="body">Text</label>
                <textarea class="input" id="body" name="body" rows="5"><?= e($editing['body'] ?? '') ?></textarea>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="type">Art</label>
                    <select class="input" id="type" name="type">
                        <?php foreach ($types as $type): ?>
                            <option value="<?= e($type) ?>" <?= ($editing['type'] ?? 'info') === $type ? 'selected' : '' ?>>
                                <?= e($typeLabels[$type] ?? $type) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="target_role">Zielgruppe</label>
                    <select class="input" id="target_role" name="target_role">
                        <?php foreach ($targetRoles as $role): ?>
                            <option value="<?= e($role) ?>" <?= ($editing['target_role'] ?? 'all') === $role ? 'selected' : '' ?>>
                                <?= e($roleLabels[$role] ?? $role) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="expires_at">Läuft ab am</label>
                    <input class="input" type="datetime-local" id="expires_at" name="expires_at" value="<?= e($expiresValue) ?>">
                    <div class="hint">Leer lassen für „läuft nicht ab".</div>
                </div>
            </div>
            <label class="checkbox-row">
                <input type="checkbox" name="is_active" value="1"
                       <?= $editing === null || (int) $editing['is_active'] === 1 ? 'checked' : '' ?>>
                <span>Ankündigung ist aktiv</span>
            </label>
        </div>
        <div class="card-footer">
            <button class="btn btn-primary" type="submit">
                <?= $editing !== null ? 'Änderungen speichern' : 'Ankündigung erstellen' ?>
            </button>
        </div>
    </form>
</div>

<?php elseif ($blockKey === 'liste'): ?>
<?php if ($announcements === []): ?>
    <div class="empty-state">
        <div class="empty-icon">📣</div>
        <p>Noch keine Ankündigungen angelegt.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Titel</th>
                <th>Art</th>
                <th>Zielgruppe</th>
                <th>Läuft ab</th>
                <th>Status</th>
                <th>Erstellt</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($announcements as $announcement): ?>
                <?php
                $expired = false;
                if (!empty($announcement['expires_at'])) {
                    try {
                        $expired = new DateTimeImmutable((string) $announcement['expires_at']) < $now;
                    } catch (Exception) {
                        $expired = false;
                    }
                }
                ?>
                <tr>
                    <td>
                        <strong><?= e($announcement['title']) ?></strong>
                        <?php if (!empty($announcement['body'])): ?>
                            <div class="text-sm text-soft"><?= e(mb_substr((string) $announcement['body'], 0, 120)) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge <?= e($typeBadges[$announcement['type']] ?? 'badge-info') ?>">
                            <?= e($typeLabels[$announcement['type']] ?? $announcement['type']) ?>
                        </span>
                    </td>
                    <td><?= e($roleLabels[$announcement['target_role']] ?? $announcement['target_role']) ?></td>
                    <td class="nowrap"><?= e(!empty($announcement['expires_at']) ? format_datetime($announcement['expires_at']) : '—') ?></td>
                    <td>
                        <?php if ($expired): ?>
                            <span class="badge badge-warning">abgelaufen</span>
                        <?php elseif ((int) $announcement['is_active'] === 1): ?>
                            <span class="badge badge-success">aktiv</span>
                        <?php else: ?>
                            <span class="badge badge-danger">inaktiv</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-sm text-soft nowrap">
                        <?= e(format_date($announcement['created_at'])) ?>
                        <?php if (!empty($announcement['creator'])): ?>
                            <div class="text-faint"><?= e($announcement['creator']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost"
                               href="<?= e($ctx->schoolUrl('/admin/ankuendigungen') . '?bearbeiten=' . (int) $announcement['id']) ?>">Bearbeiten</a>
                            <form method="post"
                                  action="<?= e($ctx->schoolUrl('/admin/ankuendigungen/' . (int) $announcement['id'] . '/umschalten')) ?>">
                                <?= $csrf->field() ?>
                                <button class="btn btn-sm btn-ghost" type="submit">
                                    <?= (int) $announcement['is_active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?>
                                </button>
                            </form>
                            <form method="post"
                                  action="<?= e($ctx->schoolUrl('/admin/ankuendigungen/' . (int) $announcement['id'] . '/loeschen')) ?>"
                                  data-confirm="Ankündigung wirklich löschen?">
                                <?= $csrf->field() ?>
                                <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
