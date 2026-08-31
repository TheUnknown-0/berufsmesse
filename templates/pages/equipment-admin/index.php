<?php
/**
 * Ausstattung: Optionen (schulweit) und Anfragen der aktuellen Messe.
 * Erwartet: $options, $requests, $counts, $status, $canManage.
 */
$statusLabels = [
    'pending' => ['Offen', 'badge-warning'],
    'approved' => ['Genehmigt', 'badge-success'],
    'denied' => ['Abgelehnt', 'badge-danger'],
];
$filters = ['alle' => 'Alle', 'pending' => 'Offen', 'approved' => 'Genehmigt', 'denied' => 'Abgelehnt'];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Ausstattung</h1>
        <p class="page-sub">Wählbare Ausstattungsoptionen pflegen und Anfragen der Aussteller bearbeiten.</p>
    </div>
</div>

<?php foreach (page_blocks('admin-ausstattung', [
    'kennzahlen' => 'Kennzahlen',
    'neue-option' => 'Neue Ausstattungsoption',
    'optionen' => 'Ausstattungsoptionen',
    'anfragen' => 'Anfragen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) count($options)) ?></div>
        <div class="stat-label">Optionen</div>
    </div>
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) (int) $counts['offen']) ?></div>
        <div class="stat-label">Offene Anfragen</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-value"><?= e((string) (int) $counts['genehmigt']) ?></div>
        <div class="stat-label">Genehmigt</div>
    </div>
    <div class="stat-card stat-danger">
        <div class="stat-value"><?= e((string) (int) $counts['abgelehnt']) ?></div>
        <div class="stat-label">Abgelehnt</div>
    </div>
</div>

<?php elseif ($blockKey === 'neue-option'): ?>
<?php if ($canManage): ?>
    <div class="card mb-2">
        <div class="card-header"><h2>Neue Ausstattungsoption</h2></div>
        <div class="card-body">
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/ausstattung/optionen/neu')) ?>">
                <?= $csrf->field() ?>
                <div class="form-grid">
                    <div class="field">
                        <label for="opt-name">Name *</label>
                        <input class="input" type="text" id="opt-name" name="name" required maxlength="150"
                               placeholder="z. B. Beamer">
                    </div>
                    <div class="field">
                        <label for="opt-description">Beschreibung</label>
                        <input class="input" type="text" id="opt-description" name="description" maxlength="500">
                    </div>
                    <div class="field">
                        <label for="opt-sort">Sortierung</label>
                        <input class="input" type="number" id="opt-sort" name="sort_order" min="0" max="65535" value="0">
                    </div>
                    <div class="field">
                        <label for="opt-active">Status</label>
                        <label class="checkbox-row" for="opt-active">
                            <input type="checkbox" id="opt-active" name="is_active" value="1" checked>
                            <span>Aktiv (auswählbar)</span>
                        </label>
                    </div>
                </div>
                <button class="btn btn-primary" type="submit">Option anlegen</button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'optionen'): ?>
<div class="card mb-2">
    <div class="card-header"><h2>Ausstattungsoptionen</h2></div>
    <div class="card-body">
        <?php if ($options === []): ?>
            <p class="text-faint mb-0">Es sind noch keine Optionen angelegt.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Beschreibung</th>
                        <th>Sortierung</th>
                        <th>Status</th>
                        <?php if ($canManage): ?><th></th><?php endif; ?>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($options as $option): ?>
                        <tr>
                            <?php if ($canManage): ?>
                                <td colspan="4">
                                    <form method="post" class="cluster"
                                          action="<?= e($ctx->schoolUrl('/admin/ausstattung/optionen/' . (int) $option['id'])) ?>">
                                        <?= $csrf->field() ?>
                                        <input class="input" type="text" name="name" required maxlength="150"
                                               style="max-width:200px;" value="<?= e($option['name']) ?>">
                                        <input class="input" type="text" name="description" maxlength="500"
                                               style="max-width:260px;" value="<?= e($option['description'] ?? '') ?>">
                                        <input class="input" type="number" name="sort_order" min="0" max="65535"
                                               style="max-width:90px;" value="<?= e((string) (int) $option['sort_order']) ?>">
                                        <label class="checkbox-row mb-0" for="active-<?= e((string) (int) $option['id']) ?>">
                                            <input type="checkbox" id="active-<?= e((string) (int) $option['id']) ?>"
                                                   name="is_active" value="1"
                                                   <?= (int) $option['is_active'] === 1 ? 'checked' : '' ?>>
                                            <span>Aktiv</span>
                                        </label>
                                        <button class="btn btn-sm" type="submit">Speichern</button>
                                    </form>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <form method="post"
                                              action="<?= e($ctx->schoolUrl('/admin/ausstattung/optionen/' . (int) $option['id'] . '/loeschen')) ?>"
                                              data-confirm="Option &quot;<?= e($option['name']) ?>&quot; wirklich löschen?">
                                            <?= $csrf->field() ?>
                                            <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                                        </form>
                                    </div>
                                </td>
                            <?php else: ?>
                                <td><?= e($option['name']) ?></td>
                                <td class="text-soft"><?= e($option['description'] ?? '') ?></td>
                                <td><?= e((string) (int) $option['sort_order']) ?></td>
                                <td>
                                    <?php if ((int) $option['is_active'] === 1): ?>
                                        <span class="badge badge-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                            <?php endif; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($blockKey === 'anfragen'): ?>
<div class="card">
    <div class="card-header">
        <h2>Anfragen</h2>
        <span class="badge badge-info"><?= e((string) (int) $counts['gesamt']) ?> gesamt</span>
    </div>
    <div class="card-body">
        <div class="tabs">
            <?php foreach ($filters as $key => $label): ?>
                <a class="tab<?= $status === $key ? ' active' : '' ?>"
                   href="<?= e($ctx->schoolUrl('/admin/ausstattung?status=' . urlencode($key))) ?>"><?= e($label) ?></a>
            <?php endforeach; ?>
        </div>

        <?php if ($requests === []): ?>
            <p class="text-faint mb-0">Keine Anfragen in dieser Ansicht.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Aussteller</th>
                        <th>Ausstattung</th>
                        <th>Anzahl</th>
                        <th>Status</th>
                        <th>Bearbeiten</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($requests as $request): ?>
                        <?php
                        $label = $statusLabels[$request['status']] ?? ['Unbekannt', ''];
                        $requestId = (int) $request['id'];
                        ?>
                        <tr>
                            <td>
                                <strong><?= e($request['exhibitor_name']) ?></strong>
                                <div class="text-faint text-sm"><?= e(format_datetime($request['created_at'])) ?></div>
                            </td>
                            <td>
                                <?= e($request['option_name'] ?? 'Freitextwunsch') ?>
                                <?php if (!empty($request['custom_text'])): ?>
                                    <div class="text-soft text-sm"><?= e($request['custom_text']) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) (int) $request['quantity']) ?></td>
                            <td><span class="badge <?= e($label[1]) ?>"><?= e($label[0]) ?></span></td>
                            <td>
                                <?php if ($canManage): ?>
                                    <form method="post" class="cluster"
                                          action="<?= e($ctx->schoolUrl('/admin/ausstattung/anfragen/' . $requestId)) ?>">
                                        <?= $csrf->field() ?>
                                        <input type="hidden" name="back_status" value="<?= e($status) ?>">
                                        <label class="text-sm text-soft" for="st-<?= e((string) $requestId) ?>">Status</label>
                                        <select id="st-<?= e((string) $requestId) ?>" name="status" style="max-width:150px;">
                                            <?php foreach ($statusLabels as $key => $meta): ?>
                                                <option value="<?= e($key) ?>" <?= $request['status'] === $key ? 'selected' : '' ?>>
                                                    <?= e($meta[0]) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                        <input class="input" type="text" name="admin_notes" maxlength="500"
                                               style="max-width:240px;" placeholder="Notiz"
                                               value="<?= e($request['admin_notes'] ?? '') ?>">
                                        <button class="btn btn-sm btn-primary" type="submit">Speichern</button>
                                    </form>
                                <?php elseif (!empty($request['admin_notes'])): ?>
                                    <span class="text-soft text-sm"><?= e($request['admin_notes']) ?></span>
                                <?php else: ?>
                                    <span class="text-faint">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
