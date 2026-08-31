<?php
/**
 * Global-Admin — schulübergreifendes Audit-Log.
 * Erwartet: $rows, $filters, $severities, $schools, $page, $pages, $total.
 */
$severityLabels = ['info' => 'Info', 'warning' => 'Warnung', 'error' => 'Fehler'];
$severityBadges = ['info' => 'badge-info', 'warning' => 'badge-warning', 'error' => 'badge-danger'];

$query = static function (array $extra) use ($filters): string {
    $params = array_filter([
        'schule' => $filters['school'],
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
        <div class="page-eyebrow">System</div>
        <h1 class="page-title">Globale Logs</h1>
        <p class="page-sub">Audit-Einträge aller Schulen sowie systemweite Ereignisse.</p>
    </div>
</div>

<?= $view->renderPartial('pages/global/_nav', ['active' => '/global-admin/logs']) ?>

<div class="card">
    <div class="card-pad">
        <form method="get" action="<?= e($ctx->url('/global-admin/logs')) ?>">
            <div class="form-grid">
                <div class="field">
                    <label for="schule">Schule</label>
                    <select class="input" id="schule" name="schule">
                        <option value="">Alle</option>
                        <option value="global" <?= $filters['school'] === 'global' ? 'selected' : '' ?>>Nur systemweit</option>
                        <?php foreach ($schools as $school): ?>
                            <option value="<?= e((string) $school['id']) ?>"
                                    <?= $filters['school'] === (string) $school['id'] ? 'selected' : '' ?>>
                                <?= e($school['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
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
                <a class="btn btn-ghost" href="<?= e($ctx->url('/global-admin/logs')) ?>">Zurücksetzen</a>
            </div>
        </form>
    </div>
</div>

<p class="text-sm text-soft"><?= e((string) $total) ?> Einträge gefunden.</p>

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
                <th>Schule</th>
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
                    <td>
                        <?php if ($row['school_name'] !== null): ?>
                            <span class="badge badge-info"><?= e($row['school_name']) ?></span>
                        <?php else: ?>
                            <span class="badge badge-primary">System</span>
                        <?php endif; ?>
                    </td>
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
                   href="<?= e($ctx->url('/global-admin/logs') . '?' . $query(['seite' => $page - 1])) ?>">← Neuer</a>
            <?php endif; ?>
            <span class="text-sm text-soft">Seite <?= e((string) $page) ?> von <?= e((string) $pages) ?></span>
            <?php if ($page < $pages): ?>
                <a class="btn btn-sm btn-ghost"
                   href="<?= e($ctx->url('/global-admin/logs') . '?' . $query(['seite' => $page + 1])) ?>">Älter →</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
