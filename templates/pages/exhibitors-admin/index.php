<?php
/**
 * Aussteller-Liste (Admin).
 * Erwartet: $rows, $search, $status, $totals.
 */

use App\Core\Permissions as P;

$schoolId = $ctx->schoolId();
$can = static fn (string $p): bool => $auth->can($p, $schoolId);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Aussteller</h1>
        <p class="page-sub">Unternehmen, Branchen, Profilfelder und Dokumente der aktuellen Messe.</p>
    </div>
    <div class="page-actions">
        <?php if ($can(P::AUSSTELLER_KONTEN_VERWALTEN)): ?>
            <a class="btn" href="<?= e($ctx->schoolUrl('/admin/aussteller-konten')) ?>">👤 Aussteller-Konten</a>
        <?php endif; ?>
        <?php if ($can(P::AUSSTELLER_ERSTELLEN)): ?>
            <a class="btn btn-primary" href="<?= e($ctx->schoolUrl('/admin/aussteller/neu')) ?>">➕ Neuer Aussteller</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach (page_blocks('admin-aussteller', [
    'reiter' => 'Reiter-Navigation',
    'kennzahlen' => 'Kennzahlen',
    'filter' => 'Suche & Filter',
    'liste' => 'Ausstellerliste',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'reiter'): ?>
<div class="tabs">
    <a class="tab active" href="<?= e($ctx->schoolUrl('/admin/aussteller')) ?>">Aussteller</a>
    <?php if ($can(P::BRANCHEN_SEHEN)): ?>
        <a class="tab" href="<?= e($ctx->schoolUrl('/admin/branchen')) ?>">Branchen</a>
    <?php endif; ?>
</div>

<?php elseif ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) (int) $totals['gesamt']) ?></div>
        <div class="stat-label">Aussteller gesamt</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-value"><?= e((string) (int) $totals['aktiv']) ?></div>
        <div class="stat-label">Aktiv</div>
    </div>
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) (int) $totals['mit_raum']) ?></div>
        <div class="stat-label">Mit Raum</div>
    </div>
</div>

<?php elseif ($blockKey === 'filter'): ?>
<div class="card mb-2">
    <div class="card-body">
        <form method="get" action="<?= e($ctx->schoolUrl('/admin/aussteller')) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="q">Suche</label>
                    <input class="input" type="search" id="q" name="q" value="<?= e($search) ?>"
                           placeholder="Name, Kurzbeschreibung oder Branche">
                </div>
                <div class="field">
                    <label for="status">Status</label>
                    <select id="status" name="status">
                        <option value="alle" <?= $status === 'alle' ? 'selected' : '' ?>>Alle</option>
                        <option value="aktiv" <?= $status === 'aktiv' ? 'selected' : '' ?>>Nur aktive</option>
                        <option value="inaktiv" <?= $status === 'inaktiv' ? 'selected' : '' ?>>Nur inaktive</option>
                    </select>
                </div>
                <div class="field">
                    <label for="filter-submit">&nbsp;</label>
                    <button class="btn btn-primary" id="filter-submit" type="submit">Filtern</button>
                </div>
            </div>
        </form>
    </div>
</div>

<?php elseif ($blockKey === 'liste'): ?>
<?php if ($rows === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🏢</div>
        <p>Keine Aussteller gefunden.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Aussteller</th>
                <th>Branchen</th>
                <th>Raum</th>
                <th>Anmeldungen</th>
                <th>Status</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td>
                        <div class="cluster">
                            <?php if (!empty($row['logo'])): ?>
                                <img src="<?= e($ctx->url('/medien/logos/' . $row['logo'])) ?>"
                                     alt="" width="32" height="32"
                                     style="width:32px;height:32px;object-fit:contain;border-radius:6px;">
                            <?php endif; ?>
                            <div>
                                <strong><?= e($row['name']) ?></strong>
                                <?php if ((int) $row['document_count'] > 0): ?>
                                    <span class="text-faint text-sm">📄 <?= e((string) (int) $row['document_count']) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </td>
                    <td>
                        <?php if ($row['category_list'] === []): ?>
                            <span class="text-faint">—</span>
                        <?php else: ?>
                            <div class="chip-row">
                                <?php foreach ($row['category_list'] as $category): ?>
                                    <span class="badge badge-primary"><?= e($category) ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($row['room_number'] !== null): ?>
                            <?= e($row['room_number']) ?>
                            <?php if (!empty($row['room_name'])): ?>
                                <span class="text-soft text-sm"><?= e($row['room_name']) ?></span>
                            <?php endif; ?>
                        <?php elseif ($can(P::RAEUME_BEARBEITEN)): ?>
                            <a class="text-soft text-sm" href="<?= e($ctx->schoolUrl('/admin/raeume')) ?>">Raum zuweisen →</a>
                        <?php else: ?>
                            <span class="text-faint">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) (int) $row['registration_count']) ?> / <?= e((string) (int) $row['total_slots']) ?></td>
                    <td>
                        <?php if ((int) $row['active'] === 1): ?>
                            <span class="badge badge-success">Aktiv</span>
                        <?php else: ?>
                            <span class="badge">Inaktiv</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm" href="<?= e($ctx->schoolUrl('/admin/aussteller/' . (int) $row['id'])) ?>">Bearbeiten</a>
                            <?php if ($can(P::AUSSTELLER_LOESCHEN)): ?>
                                <form method="post"
                                      action="<?= e($ctx->schoolUrl('/admin/aussteller/' . (int) $row['id'] . '/loeschen')) ?>"
                                      data-confirm="Aussteller &quot;<?= e($row['name']) ?>&quot; wirklich löschen? Anmeldungen und Dokumente werden mitgelöscht.">
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
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
