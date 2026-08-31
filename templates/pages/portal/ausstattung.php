<?php
/**
 * Aussteller-Portal — Ausstattungsanfragen.
 * Erwartet: $exhibitors, $options, $requests (je Aussteller-ID).
 */
$statusLabels = ['pending' => 'Offen', 'approved' => 'Genehmigt', 'denied' => 'Abgelehnt'];
$statusBadges = ['pending' => 'badge-warning', 'approved' => 'badge-success', 'denied' => 'badge-danger'];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Aussteller-Portal</div>
        <h1 class="page-title">Ausstattung</h1>
        <p class="page-sub">Wünsche für deinen Stand — die Schule prüft jede Anfrage.</p>
    </div>
</div>

<?php foreach (page_blocks('portal-ausstattung', [
    'hinweis' => 'Hinweis ohne Unternehmen',
    'neue-anfrage' => 'Neue Anfrage',
    'verfuegbare-ausstattung' => 'Verfügbare Ausstattung',
    'anfragen' => 'Gestellte Anfragen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweis'): ?>
<?php if ($exhibitors === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🔌</div>
        <p>Deinem Konto ist an dieser Schule kein Unternehmen zugeordnet.</p>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'neue-anfrage'): ?>
<?php if ($exhibitors !== []): ?>
    <div class="card">
        <div class="card-header"><h3 style="margin:0;">Neue Anfrage</h3></div>
        <form method="post" action="<?= e($ctx->schoolUrl('/portal/ausstattung')) ?>">
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
                        <label for="equipment_option_id">Ausstattung</label>
                        <select class="input" id="equipment_option_id" name="equipment_option_id">
                            <option value="0">— Eigener Wunsch (Freitext) —</option>
                            <?php foreach ($options as $option): ?>
                                <option value="<?= e((string) $option['id']) ?>"><?= e($option['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php if ($options === []): ?>
                            <div class="hint">Die Schule hat noch keine Optionen hinterlegt — nutze das Freitextfeld.</div>
                        <?php endif; ?>
                    </div>
                    <div class="field">
                        <label for="quantity">Anzahl</label>
                        <input class="input" type="number" id="quantity" name="quantity" min="1" max="99" value="1" required>
                    </div>
                </div>
                <div class="field">
                    <label for="custom_text">Freitext (nur ohne ausgewählte Option)</label>
                    <input class="input" type="text" id="custom_text" name="custom_text" maxlength="500"
                           placeholder="z. B. Stehtisch, zusätzliche Steckdosenleiste">
                </div>
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">Anfrage senden</button>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'verfuegbare-ausstattung'): ?>
    <?php if ($exhibitors !== [] && $options !== []): ?>
        <div class="card">
            <div class="card-header"><h3 style="margin:0;">Verfügbare Ausstattung</h3></div>
            <div class="card-body">
                <div class="stack">
                    <?php foreach ($options as $option): ?>
                        <div>
                            <strong><?= e($option['name']) ?></strong>
                            <?php if (!empty($option['description'])): ?>
                                <div class="text-sm text-soft"><?= e($option['description']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'anfragen'): ?>
    <?php foreach ($exhibitors as $exhibitor): ?>
        <?php $rows = $requests[(int) $exhibitor['id']] ?? []; ?>
        <div class="card">
            <div class="card-header"><h3 style="margin:0;">Anfragen: <?= e($exhibitor['name']) ?></h3></div>
            <div class="card-body">
                <?php if ($rows === []): ?>
                    <div class="empty-state">
                        <p>Noch keine Anfragen gestellt.</p>
                    </div>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                            <tr>
                                <th>Wunsch</th>
                                <th>Anzahl</th>
                                <th>Status</th>
                                <th>Anmerkung der Schule</th>
                                <th>Gestellt am</th>
                                <th></th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($rows as $request): ?>
                                <tr>
                                    <td><?= e($request['option_name'] ?? $request['custom_text'] ?? '—') ?></td>
                                    <td><?= e((string) $request['quantity']) ?></td>
                                    <td>
                                        <span class="badge <?= e($statusBadges[$request['status']] ?? 'badge-info') ?>">
                                            <?= e($statusLabels[$request['status']] ?? $request['status']) ?>
                                        </span>
                                    </td>
                                    <td class="text-sm text-soft"><?= e($request['admin_notes'] ?? '') ?></td>
                                    <td class="nowrap"><?= e(format_date($request['created_at'])) ?></td>
                                    <td>
                                        <div class="row-actions">
                                            <?php if ($request['status'] === 'pending'): ?>
                                                <form method="post"
                                                      action="<?= e($ctx->schoolUrl('/portal/ausstattung/' . (int) $request['id'] . '/stornieren')) ?>"
                                                      data-confirm="Anfrage wirklich stornieren?">
                                                    <?= $csrf->field() ?>
                                                    <button class="btn btn-sm btn-danger-ghost" type="submit">Stornieren</button>
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
