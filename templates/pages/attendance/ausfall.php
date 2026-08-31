<?php
/**
 * Ausfall eines Ausstellers: Auswahl (ohne $plan) bzw. Vorschau der
 * Umbuchung (mit $plan).
 * Erwartet: $exhibitors, $plan, $exhibitor, ggf. $canExecute.
 */
$canExecute = $canExecute ?? false;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">
            <?php if ($plan !== null): ?>
                <a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/ausfall')) ?>">← Ausfall melden</a>
            <?php else: ?>
                <a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/leitstand')) ?>">← Leitstand</a>
            <?php endif; ?>
        </div>
        <h1 class="page-title">
            <?= $plan === null ? 'Ausfall melden' : e('Ausfall: ' . (string) $exhibitor['name']) ?>
        </h1>
        <p class="page-sub">
            <?php if ($plan === null): ?>
                Fällt ein Unternehmen kurzfristig aus, werden die betroffenen Schüler:innen auf
                Aussteller mit freien Plätzen im selben Zeitslot umgebucht.
            <?php else: ?>
                Vorschau — es wurde noch nichts geändert.
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
                <tr><th>Aussteller</th><th>Status</th><th>zugeteilte Anmeldungen</th><th></th></tr>
                </thead>
                <tbody>
                <?php foreach ($exhibitors as $row): ?>
                    <tr>
                        <td><strong><?= e($row['name']) ?></strong></td>
                        <td>
                            <?php if ((int) $row['active'] === 1): ?>
                                <span class="badge badge-success">aktiv</span>
                            <?php else: ?>
                                <span class="badge">inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) $row['betroffen']) ?></td>
                        <td>
                            <a class="btn btn-sm btn-ghost"
                               href="<?= e($ctx->schoolUrl('/admin/ausfall/' . (int) $row['id'])) ?>">Ausfall prüfen</a>
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
            <div class="stat-label">ohne Ersatz</div>
        </div>
    </div>

    <?php if ($plan['affected'] === 0): ?>
        <div class="empty-state">
            <div class="empty-icon">✅</div>
            <p>Diesem Aussteller ist niemand zugeteilt — ein Ausfall hat keine Folgen für die Tagespläne.</p>
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

        <div class="alert alert-warning">
            Beim Ausführen werden die Anmeldungen umgebucht, Anmeldungen ohne freien Platz
            <strong>entfernt</strong>, der Aussteller auf „Storniert“ gesetzt und alle Betroffenen in der
            App benachrichtigt. Das lässt sich nicht mit einem Klick rückgängig machen.
        </div>

        <?php if ($canExecute): ?>
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/ausfall/' . (int) $exhibitor['id'] . '/umbuchen')) ?>"
                  data-confirm="Ausfall von „<?= e($exhibitor['name']) ?>“ jetzt verarbeiten? <?= e((string) $plan['placeable']) ?> Umbuchungen und <?= e((string) ($plan['affected'] - $plan['placeable'])) ?> Entfernungen.">
                <?= $csrf->field() ?>
                <div class="cluster">
                    <button class="btn btn-danger" type="submit">Ausfall verarbeiten</button>
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
