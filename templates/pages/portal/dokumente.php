<?php
/**
 * Aussteller-Portal — Dokumente.
 * Erwartet: $exhibitors (nur mit Dokumentenrecht), $documents (je Aussteller-ID).
 */
$formatSize = static function (int $bytes): string {
    if ($bytes >= 1048576) {
        return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
    }

    return number_format(max(1, (int) round($bytes / 1024)), 0, ',', '.') . ' KB';
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Aussteller-Portal</div>
        <h1 class="page-title">Dokumente</h1>
        <p class="page-sub">Materialien für dein Unternehmen — auf Wunsch auch für Schüler:innen sichtbar.</p>
    </div>
</div>

<?php foreach (page_blocks('portal-dokumente', [
    'hinweis' => 'Hinweis ohne Freigabe',
    'upload' => 'Dokument hochladen',
    'dokumentenliste' => 'Dokumente je Unternehmen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweis'): ?>
<?php if ($exhibitors === []): ?>
    <div class="empty-state">
        <div class="empty-icon">📄</div>
        <p>Für kein Unternehmen deines Kontos ist die Dokumentenverwaltung freigeschaltet.</p>
        <p class="text-sm text-soft">Die Schule kann dieses Recht in den Aussteller-Konten vergeben.</p>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'upload'): ?>
<?php if ($exhibitors !== []): ?>
    <div class="card">
        <div class="card-header"><h3 style="margin:0;">Dokument hochladen</h3></div>
        <form method="post" action="<?= e($ctx->schoolUrl('/portal/dokumente')) ?>" enctype="multipart/form-data">
            <?= $csrf->field() ?>
            <div class="card-body">
                <div class="form-grid">
                    <div class="field">
                        <label for="exhibitor_id">Unternehmen</label>
                        <select class="input" id="exhibitor_id" name="exhibitor_id" required>
                            <?php foreach ($exhibitors as $exhibitor): ?>
                                <option value="<?= e((string) $exhibitor['id']) ?>"><?= e($exhibitor['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="field">
                        <label for="document">Datei</label>
                        <input class="input" type="file" id="document" name="document" required
                               accept=".pdf,.doc,.docx,.ppt,.pptx,.jpg,.jpeg,.png,.gif,.webp">
                        <div class="hint">PDF, Word, PowerPoint oder Bild — maximal 10 MB.</div>
                    </div>
                </div>
                <label class="checkbox-row">
                    <input type="checkbox" name="visible_for_students" value="1">
                    <span>Direkt für Schüler:innen sichtbar machen</span>
                </label>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">Hochladen</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'dokumentenliste'): ?>
    <?php foreach ($exhibitors as $exhibitor): ?>
        <?php $rows = $documents[(int) $exhibitor['id']] ?? []; ?>
        <div class="card">
            <div class="card-header"><h3 style="margin:0;"><?= e($exhibitor['name']) ?></h3></div>
            <div class="card-body">
                <?php if ($rows === []): ?>
                    <div class="empty-state">
                        <p>Noch keine Dokumente hochgeladen.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>Datei</th>
                                <th>Größe</th>
                                <th>Hochgeladen</th>
                                <th>Für Schüler:innen</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $document): ?>
                                <tr>
                                    <td>
                                        <a href="<?= e($ctx->schoolUrl('/portal/dokumente/' . (int) $document['id'] . '/download')) ?>">
                                            <?= e($document['original_name']) ?>
                                        </a>
                                    </td>
                                    <td class="nowrap"><?= e($formatSize((int) $document['file_size'])) ?></td>
                                    <td class="nowrap"><?= e(format_datetime($document['uploaded_at'])) ?></td>
                                    <td>
                                        <?php if ((int) $document['visible_for_students'] === 1): ?>
                                            <span class="badge badge-success">sichtbar</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">intern</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <div class="row-actions">
                                            <form method="post"
                                                  action="<?= e($ctx->schoolUrl('/portal/dokumente/' . (int) $document['id'] . '/sichtbarkeit')) ?>">
                                                <?= $csrf->field() ?>
                                                <button class="btn btn-sm btn-ghost" type="submit">
                                                    <?= (int) $document['visible_for_students'] === 1 ? 'Verbergen' : 'Freigeben' ?>
                                                </button>
                                            </form>
                                            <form method="post"
                                                  action="<?= e($ctx->schoolUrl('/portal/dokumente/' . (int) $document['id'] . '/loeschen')) ?>"
                                                  data-confirm="Dokument wirklich löschen?">
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
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
