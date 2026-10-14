<?php
/**
 * Ausfall eines Ausstellers bzw. Neuzuteilung ruhender Buchungen nach einer
 * Absage: Auswahl (ohne $plan) bzw. Vorschau der Umbuchung (mit $plan).
 * Erwartet: $exhibitors, $plan, $exhibitor, ggf. $canExecute.
 */
$canExecute = $canExecute ?? false;
$isCancelled = $exhibitor !== null && (int) $exhibitor['active'] !== 1;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">
            <?php if ($plan !== null): ?>
                <a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/ausfall')) ?>">← Ausfall &amp; Neuzuteilung</a>
            <?php else: ?>
                <a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/leitstand')) ?>">← Leitstand</a>
            <?php endif; ?>
        </div>
        <h1 class="page-title">
            <?php if ($plan === null): ?>
                Ausfall &amp; Neuzuteilung
            <?php else: ?>
                <?= e(($isCancelled ? 'Neu zuteilen: ' : 'Ausfall: ') . (string) $exhibitor['name']) ?>
            <?php endif; ?>
        </h1>
        <p class="page-sub">
            <?php if ($plan === null): ?>
                Fällt ein Unternehmen kurzfristig aus, werden die betroffenen Schüler:innen auf
                Aussteller mit freien Plätzen im selben Zeitslot umgebucht. Nach einer Absage stehen
                hier die ruhenden Buchungen, die noch neu zugeteilt werden müssen.
            <?php else: ?>
                Vorschau: Es wurde noch nichts geändert.
            <?php endif; ?>
        </p>
    </div>
</div>

<?php if ($plan === null): ?>
    <?php if ($exhibitors === []): ?>
        <div class="empty-state">
            <div class="empty-icon">🏢</div>
            <p>Für diese Messe sind keine Aussteller angelegt.</p>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr><th>Aussteller</th><th>Status</th><th>zugeteilte Anmeldungen</th><th>ruhende Buchungen</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($exhibitors as $row): ?>
                    <tr>
                        <td><strong><?= e($row['name']) ?></strong></td>
                        <td>
                            <?php if ((int) $row['active'] === 1): ?>
                                <span class="badge badge-success">aktiv</span>
                            <?php else: ?>
                                <span class="badge">abgesagt / ausgeblendet</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) $row['betroffen']) ?></td>
                        <td>
                            <?php if ((int) $row['ruhend'] > 0): ?>
                                <span class="badge badge-warning"><?= e((string) $row['ruhend']) ?></span>
                            <?php else: ?>
                                0
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ((int) $row['active'] === 1): ?>
                                <a class="btn btn-sm btn-ghost"
                                   href="<?= e($ctx->schoolUrl('/admin/ausfall/' . (int) $row['id'])) ?>">Ausfall prüfen</a>
                            <?php elseif ((int) $row['ruhend'] > 0): ?>
                                <a class="btn btn-sm btn-primary"
                                   href="<?= e($ctx->schoolUrl('/admin/ausfall/' . (int) $row['id'])) ?>">Neu zuteilen</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php else: ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $plan['affected']) ?></div>
            <div class="stat-label">betroffene Anmeldungen</div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-value"><?= e((string) $plan['placeable']) ?></div>
            <div class="stat-label">können umgebucht werden</div>
        </div>
        <div class="stat-card<?= ($plan['affected'] - $plan['placeable']) > 0 ? ' stat-danger' : '' ?>">
            <div class="stat-value"><?= e((string) ($plan['affected'] - $plan['placeable'])) ?></div>
            <div class="stat-label">ohne Ersatz (bleiben ruhend)</div>
        </div>
    </div>

    <?php if ($plan['affected'] === 0): ?>
        <div class="empty-state">
            <div class="empty-icon">✅</div>
            <p><?= $isCancelled
                ? 'Es gibt keine ruhenden Buchungen mehr, die neu zugeteilt werden müssten.'
                : 'Diesem Aussteller ist niemand zugeteilt. Ein Ausfall hat keine Folgen für die Tagespläne.' ?></p>
        </div>
    <?php else: ?>
        <?php foreach ($plan['slots'] as $slot): ?>
            <div class="card">
                <div class="card-header">
                    <h2 class="mt-0 mb-0">
                        <?= e($slot['label']) ?>
                        <span class="text-soft text-sm"><?= e($slot['start']) ?>–<?= e($slot['end']) ?></span>
                    </h2>
                    <span class="badge"><?= e((string) count($slot['students'])) ?></span>
                </div>
                <div class="card-body">
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead>
                            <tr><th>Schüler:in</th><th>Klasse</th><th>wird umgebucht zu</th></tr>
                            </thead>
                            <tbody>
                            <?php foreach ($slot['students'] as $student): ?>
                                <tr>
                                    <td><?= e($student['name']) ?></td>
                                    <td class="text-sm text-soft"><?= e((string) ($student['class'] ?? '—')) ?></td>
                                    <td>
                                        <?php if ($student['target_id'] === null): ?>
                                            <span class="badge badge-danger">kein freier Platz</span>
                                        <?php else: ?>
                                            <span class="badge badge-success"><?= e((string) $student['target_name']) ?></span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <div class="alert alert-warning"><div>
            <?php if ($isCancelled): ?>
                Beim Ausführen bekommen die Schüler:innen mit freiem Platz eine neue Buchung; ihre ruhende
                Buchung wird dabei gelöscht. Wer keinen Platz bekommt, behält die ruhende Buchung.
            <?php else: ?>
                Beim Ausführen wird der Aussteller abgesagt (Pipeline „Storniert“), die Schüler:innen
                werden umgebucht und in der App benachrichtigt. Wer keinen freien Platz bekommt, behält
                eine <strong>ruhende Buchung</strong>; die lässt sich später neu zuteilen oder kommt zurück,
                falls die Absage zurückgenommen wird.
            <?php endif; ?>
        </div></div>

        <?php if ($canExecute): ?>
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/ausfall/' . (int) $exhibitor['id'] . '/umbuchen')) ?>"
                  data-confirm="<?= e(($isCancelled ? 'Jetzt neu zuteilen? ' : 'Ausfall von „' . (string) $exhibitor['name'] . '“ jetzt verarbeiten? ') . $plan['placeable'] . ' Umbuchungen, ' . ($plan['affected'] - $plan['placeable']) . ' bleiben ruhend.') ?>">
                <?= $csrf->field() ?>
                <div class="cluster">
                    <button class="btn <?= $isCancelled ? 'btn-primary' : 'btn-danger' ?>" type="submit"><?= $isCancelled ? 'Neu zuteilen' : 'Ausfall verarbeiten' ?></button>
                    <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/ausfall')) ?>">Abbrechen</a>
                </div>
            </form>
        <?php else: ?>
            <div class="alert alert-info">
                Zum Ausführen brauchst du die Rechte, Anmeldungen zu erstellen und zu löschen.
            </div>
        <?php endif; ?>
    <?php endif; ?>
<?php endif; ?>
