<?php
/**
 * Branchen-Stammdaten (global, aber nur im Schul-Kontext bearbeitbar).
 * Erwartet: $industries, $usage (Name => Anzahl), $unknown (Name => Anzahl).
 */

use App\Core\Permissions as P;

$schoolId = $ctx->schoolId();
$canEdit = $auth->can(P::BRANCHEN_BEARBEITEN, $schoolId);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Branchen</h1>
        <p class="page-sub">Auswahlliste für Aussteller-Profile. Branchen gelten schulübergreifend.</p>
    </div>
</div>

<?php foreach (page_blocks('admin-branchen', [
    'reiter' => 'Reiter-Navigation',
    'neue-branche' => 'Neue Branche anlegen',
    'liste' => 'Branchenliste',
    'unbekannte' => 'Nicht mehr gepflegte Branchen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'reiter'): ?>
<div class="tabs">
    <?php if ($auth->can(P::AUSSTELLER_SEHEN, $schoolId)): ?>
        <a class="tab" href="<?= e($ctx->schoolUrl('/admin/aussteller')) ?>">Aussteller</a>
    <?php endif; ?>
    <a class="tab active" href="<?= e($ctx->schoolUrl('/admin/branchen')) ?>">Branchen</a>
</div>

<?php elseif ($blockKey === 'neue-branche'): ?>
<?php if ($canEdit): ?>
    <div class="card mb-2">
        <div class="card-header"><h2>Neue Branche</h2></div>
        <div class="card-body">
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/branchen')) ?>">
                <?= $csrf->field() ?>
                <div class="form-grid">
                    <div class="field">
                        <label for="new-name">Name *</label>
                        <input class="input" type="text" id="new-name" name="name" required maxlength="150">
                    </div>
                    <div class="field">
                        <label for="new-sort">Sortierung</label>
                        <input class="input" type="number" id="new-sort" name="sort_order" min="0" max="65535" value="0">
                    </div>
                    <div class="field">
                        <label for="new-submit">&nbsp;</label>
                        <button class="btn btn-primary" id="new-submit" type="submit">Anlegen</button>
                    </div>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'liste'): ?>
<?php if ($industries === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🏷️</div>
        <p>Es sind noch keine Branchen angelegt.</p>
    </div>
<?php else: ?>
    <div class="table-wrap mb-2">
        <table class="data-table">
            <thead>
            <tr>
                <th>Branche</th>
                <th>Sortierung</th>
                <th>Verwendet</th>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($industries as $industry): ?>
                <?php $count = (int) ($usage[$industry['name']] ?? 0); ?>
                <tr>
                    <?php if ($canEdit): ?>
                        <td colspan="2">
                            <form method="post" class="cluster"
                                  action="<?= e($ctx->schoolUrl('/admin/branchen/' . (int) $industry['id'])) ?>">
                                <?= $csrf->field() ?>
                                <input class="input" type="text" name="name" required maxlength="150"
                                       style="max-width:280px;" value="<?= e($industry['name']) ?>">
                                <input class="input" type="number" name="sort_order" min="0" max="65535"
                                       style="max-width:100px;" value="<?= e((string) (int) $industry['sort_order']) ?>">
                                <button class="btn btn-sm" type="submit">Speichern</button>
                            </form>
                        </td>
                    <?php else: ?>
                        <td><?= e($industry['name']) ?></td>
                        <td><?= e((string) (int) $industry['sort_order']) ?></td>
                    <?php endif; ?>
                    <td class="nowrap">
                        <?php if ($count > 0): ?>
                            <span class="badge badge-info"><?= e((string) $count) ?> Aussteller</span>
                        <?php else: ?>
                            <span class="text-faint">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($canEdit): ?>
                            <div class="row-actions">
                                <form method="post"
                                      action="<?= e($ctx->schoolUrl('/admin/branchen/' . (int) $industry['id'] . '/loeschen')) ?>"
                                      data-confirm="Branche &quot;<?= e($industry['name']) ?>&quot; löschen? Bereits zugeordnete Aussteller behalten den Namen.">
                                    <?= $csrf->field() ?>
                                    <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                </form>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'unbekannte'): ?>
<?php if ($unknown !== []): ?>
    <div class="alert alert-info">
        <div>
            <strong>Nicht mehr gepflegte Branchen</strong>
            <p class="text-sm mb-0">Diese Namen stehen noch in Aussteller-Profilen dieser Messe, sind aber keine Stammdaten (mehr). Lege sie oben neu an, um sie wieder auswählbar zu machen.</p>
            <div class="chip-row mt-2">
                <?php foreach ($unknown as $name => $count): ?>
                    <span class="badge badge-warning"><?= e((string) $name) ?> · <?= e((string) $count) ?></span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
