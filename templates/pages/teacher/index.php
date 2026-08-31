<?php
/**
 * Klassenübersicht für Lehrkräfte mit Einschreibe- und Zuteilungsquote.
 * Erwartet: $edition, $classes, $managedCount, $withoutClass.
 */
$percent = static fn (int $part, int $total): int => $total > 0 ? (int) round($part / $total * 100) : 0;
$totalStudents = array_sum(array_column($classes, 'gesamt'));
$totalRegistered = array_sum(array_column($classes, 'eingeschrieben'));
$totalComplete = array_sum(array_column($classes, 'vollstaendig'));
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($edition['name']) ?></div>
        <h1 class="page-title">Klassen</h1>
        <p class="page-sub">Wie weit sind die Klassen bei Einschreibung und Zuteilung?</p>
    </div>
</div>

<?php $classBlocks = page_blocks('klassen', [
    'kennzahlen' => 'Kennzahlen',
    'klassenliste' => 'Klassenliste',
]); ?>
<?php foreach ($classBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $totalStudents) ?></div>
            <div class="stat-label">Schüler:innen mit Klasse</div>
        </div>
        <div class="stat-card stat-accent">
            <div class="stat-value"><?= e((string) $totalRegistered) ?></div>
            <div class="stat-label">eingeschrieben · <?= e((string) $percent($totalRegistered, $totalStudents)) ?> %</div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-value"><?= e((string) $totalComplete) ?></div>
            <div class="stat-label">vollständig zugeteilt (<?= e((string) $managedCount) ?> Slots)</div>
        </div>
        <?php if ($withoutClass > 0): ?>
            <div class="stat-card stat-danger">
                <div class="stat-value"><?= e((string) $withoutClass) ?></div>
                <div class="stat-label">ohne Klassenangabe</div>
            </div>
        <?php endif; ?>
    </div>

<?php elseif ($blockKey === 'klassenliste'): ?>
    <?php if ($classes === []): ?>
        <div class="card card-pad">
            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">🧑‍🏫</div>
                <p>Für diese Messe sind noch keine Klassen erfasst.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Klasse</th>
                    <th>Schüler:innen</th>
                    <th style="min-width:200px;">Eingeschrieben</th>
                    <th style="min-width:200px;">Zugeteilt</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($classes as $row): ?>
                    <?php
                    $regPercent = $percent($row['eingeschrieben'], $row['gesamt']);
                    $assignPercent = $percent($row['vollstaendig'], $row['gesamt']);
                    ?>
                    <tr>
                        <td><strong><?= e($row['class']) ?></strong></td>
                        <td><?= e((string) $row['gesamt']) ?></td>
                        <td>
                            <div class="progress" role="img"
                                 aria-label="<?= e($regPercent . ' Prozent eingeschrieben') ?>">
                                <span style="width:<?= e((string) $regPercent) ?>%;"></span>
                            </div>
                            <div class="text-sm text-soft">
                                <?= e((string) $row['eingeschrieben']) ?> / <?= e((string) $row['gesamt']) ?>
                                · <?= e((string) $regPercent) ?> %
                            </div>
                        </td>
                        <td>
                            <div class="progress" role="img"
                                 aria-label="<?= e($assignPercent . ' Prozent vollständig zugeteilt') ?>">
                                <span style="width:<?= e((string) $assignPercent) ?>%;"></span>
                            </div>
                            <div class="text-sm text-soft">
                                <?= e((string) $row['vollstaendig']) ?> / <?= e((string) $row['gesamt']) ?>
                                · <?= e((string) $assignPercent) ?> %
                            </div>
                        </td>
                        <td>
                            <div class="row-actions">
                                <a class="btn btn-sm"
                                   href="<?= e($ctx->schoolUrl('/klassen/' . rawurlencode($row['class']))) ?>">Liste</a>
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
