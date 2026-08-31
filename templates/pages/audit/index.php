<?php
/**
 * Audit-Log einer Schule.
 * Erwartet: $rows, $filters, $severities, $page, $pages, $total, $errorCount.
 */
$severityLabels = ['info' => 'Info', 'warning' => 'Warnung', 'error' => 'Fehler'];
$severityBadges = ['info' => 'badge-info', 'warning' => 'badge-warning', 'error' => 'badge-danger'];

$query = static function (array $extra) use ($filters): string {
    $params = array_filter([
        'stufe' => $filters['severity'],
        'von' => $filters['von'],
        'bis' => $filters['bis'],
        'suche' => $filters['suche'],
    ], static fn (string $v): bool => $v !== '');

    return http_build_query($params + $extra);
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Audit-Log</h1>
        <p class="page-sub">Protokoll aller sicherheitsrelevanten Aktionen dieser Schule. Einträge sind schreibgeschützt.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/audit-log/export') . '?' . $query([])) ?>">
            ⬇️ Als TXT exportieren
        </a>
    </div>
</div>

<?php foreach (page_blocks('admin-audit-log', [
    'kennzahlen' => 'Kennzahlen',
    'filter' => 'Suche & Filter',
    'liste' => 'Protokoll-Einträge',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) $total) ?></div>
        <div class="stat-label">Einträge (gefiltert)</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $page) ?> / <?= e((string) $pages) ?></div>
        <div class="stat-label">Seite</div>
    </div>
    <div class="stat-card<?= $errorCount > 0 ? ' stat-danger' : '' ?>">
        <div class="stat-value"><?= e((string) $errorCount) ?></div>
        <div class="stat-label">Fehler</div>
    </div>
</div>

<?php elseif ($blockKey === 'filter'): ?>
<div class="card">
    <div class="card-pad">
        <form method="get" action="<?= e($ctx->schoolUrl('/admin/audit-log')) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="stufe">Schweregrad</label>
                    <select class="input" id="stufe" name="stufe">
                        <option value="">Alle</option>
                        <?php foreach ($severities as $severity): ?>
                            <option value="<?= e($severity) ?>" <?= $filters['severity'] === $severity ? 'selected' : '' ?>>
                                <?= e($severityLabels[$severity] ?? $severity) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="von">Von</label>
                    <input class="input" type="date" id="von" name="von" value="<?= e($filters['von']) ?>">
                </div>
                <div class="field">
                    <label for="bis">Bis</label>
                    <input class="input" type="date" id="bis" name="bis" value="<?= e($filters['bis']) ?>">
                </div>
                <div class="field">
                    <label for="suche">Suche in Aktion/Benutzer</label>
                    <input class="input" type="search" id="suche" name="suche" value="<?= e($filters['suche']) ?>">
                </div>
            </div>
            <div class="cluster">
                <button class="btn btn-primary" type="submit">Filtern</button>
                <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/audit-log')) ?>">Zurücksetzen</a>
            </div>
        </form>
    </div>
</div>

<?php elseif ($blockKey === 'liste'): ?>
<?php if ($rows === []): ?>
    <div class="empty-state">
        <div class="empty-icon">📜</div>
        <p>Keine Einträge für diese Filter.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Zeitpunkt</th>
                <th>Benutzer</th>
                <th>Aktion</th>
                <th>Stufe</th>
                <th>Details</th>
                <th>IP</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <tr>
                    <td class="nowrap mono"><?= e(format_datetime($row['created_at'])) ?></td>
                    <td><?= e($row['username'] ?? 'System') ?></td>
                    <td><?= e($row['action']) ?></td>
                    <td>
                        <span class="badge <?= e($severityBadges[$row['severity']] ?? 'badge-info') ?>">
                            <?= e($severityLabels[$row['severity']] ?? $row['severity']) ?>
                        </span>
                    </td>
                    <td class="text-sm text-soft"><?= e($row['details'] ?? '') ?></td>
                    <td class="mono text-sm"><?= e($row['ip_address'] ?? '') ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <?php if ($pages > 1): ?>
        <div class="cluster" style="margin-top:14px;">
            <?php if ($page > 1): ?>
                <a class="btn btn-sm btn-ghost"
                   href="<?= e($ctx->schoolUrl('/admin/audit-log') . '?' . $query(['seite' => $page - 1])) ?>">← Neuer</a>
            <?php endif; ?>
            <span class="text-sm text-soft">Seite <?= e((string) $page) ?> von <?= e((string) $pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn-sm btn-ghost"
                   href="<?= e($ctx->schoolUrl('/admin/audit-log') . '?' . $query(['seite' => $page + 1])) ?>">Älter →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
