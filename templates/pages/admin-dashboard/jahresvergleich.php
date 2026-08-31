<?php
/**
 * Jahresvergleich über alle Editionen der Schule.
 * Erwartet: $editions (älteste zuerst), $loyalty, $canExport.
 */
$statusLabels = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archived' => 'Archiviert'];

/** Höchstwert einer Kennzahl über alle Jahre — Bezugsgröße der Balken. */
$peak = static function (string $key) use ($editions): int {
    $values = array_map(static fn (array $e): int => (int) $e[$key], $editions);

    return $values === [] ? 1 : max(1, max($values));
};

/** Veränderung zum Vorjahr als lesbarer Text. */
$trend = static function (int $current, ?int $previous): array {
    if ($previous === null || $previous === 0) {
        return ['', ''];
    }
    $delta = $current - $previous;
    if ($delta === 0) {
        return ['±0', ''];
    }
    $percent = (int) round($delta / $previous * 100);

    return [
        ($delta > 0 ? '+' : '') . $delta . ' (' . ($percent > 0 ? '+' : '') . $percent . ' %)',
        $delta > 0 ? 'badge-success' : 'badge-warning',
    ];
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Berichte</div>
        <h1 class="page-title">Jahresvergleich</h1>
        <p class="page-sub">Alle Messe-Jahrgänge dieser Schule nebeneinander.</p>
    </div>
    <?php if ($canExport): ?>
        <div class="page-actions">
            <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/jahresvergleich/export?format=csv')) ?>">⬇ CSV</a>
            <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/jahresvergleich/export?format=xlsx')) ?>">⬇ Excel</a>
        </div>
    <?php endif; ?>
</div>

<?php if (count($editions) < 2): ?>
    <div class="alert alert-info">
        Für einen Vergleich braucht es mindestens zwei Jahrgänge. Aktuell
        <?= e((string) count($editions)) ?> — lege die nächste Edition an (am schnellsten über
        „Klonen“ im Global-Admin), dann füllt sich diese Seite von selbst.
    </div>
<?php endif; ?>

<?php if ($editions === []): ?>
    <div class="empty-state">
        <div class="empty-icon">📅</div>
        <p>Für diese Schule ist noch keine Messe angelegt.</p>
    </div>
<?php else: ?>

<?php foreach (page_blocks('admin-jahresvergleich', [
    'tabelle' => 'Kennzahlen je Jahrgang',
    'verlauf' => 'Verläufe',
    'treue' => 'Aussteller-Treue',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'tabelle'): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Jahr</th>
                <th>Messe</th>
                <th>Status</th>
                <th>Schüler:innen</th>
                <th>Aussteller</th>
                <th>Anmeldungen</th>
                <th>Check-ins</th>
                <th>Quote</th>
                <th>Feedback</th>
            </tr>
            </thead>
            <tbody>
            <?php $previous = null; ?>
            <?php foreach ($editions as $edition): ?>
                <?php [$trendText, $trendClass] = $trend((int) $edition['students'], $previous); ?>
                <tr>
                    <td><strong><?= e((string) $edition['year']) ?></strong></td>
                    <td>
                        <?= e((string) $edition['name']) ?>
                        <?php if ($edition['event_date'] !== null): ?>
                            <div class="text-sm text-soft"><?= e(format_date($edition['event_date'])) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="badge<?= $edition['status'] === 'active' ? ' badge-success' : '' ?>">
                            <?= e($statusLabels[$edition['status']] ?? (string) $edition['status']) ?>
                        </span>
                    </td>
                    <td>
                        <?= e((string) $edition['students']) ?>
                        <?php if ($trendText !== ''): ?>
                            <span class="badge <?= e($trendClass) ?>"><?= e($trendText) ?></span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) $edition['exhibitors']) ?></td>
                    <td>
                        <?= e((string) $edition['registrations']) ?>
                        <span class="text-soft text-sm">(<?= e((string) $edition['assigned']) ?> zugeteilt)</span>
                    </td>
                    <td><?= e((string) $edition['attendances']) ?></td>
                    <td>
                        <?php if ($edition['assigned'] > 0): ?>
                            <span class="badge<?= $edition['quote'] >= 80 ? ' badge-success' : ($edition['quote'] >= 60 ? ' badge-warning' : ' badge-danger') ?>">
                                <?= e((string) $edition['quote']) ?> %
                            </span>
                        <?php else: ?>
                            <span class="text-faint">—</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e((string) $edition['feedback']) ?></td>
                </tr>
                <?php $previous = (int) $edition['students']; ?>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php elseif ($blockKey === 'verlauf'): ?>
    <div class="grid-2">
        <?php foreach ([
            'students' => 'Teilnehmende Schüler:innen',
            'exhibitors' => 'Aussteller',
            'attendances' => 'Check-ins',
            'feedback' => 'Feedback-Rückmeldungen',
        ] as $key => $label): ?>
            <div class="card">
                <div class="card-header"><h3 class="mt-0 mb-0"><?= e($label) ?></h3></div>
                <div class="card-body">
                    <div class="stack">
                        <?php $max = $peak($key); ?>
                        <?php foreach ($editions as $edition): ?>
                            <div>
                                <div class="cluster">
                                    <span style="flex:1;"><?= e((string) $edition['year']) ?></span>
                                    <span class="text-sm text-soft"><?= e((string) $edition[$key]) ?></span>
                                </div>
                                <div class="progress">
                                    <span style="width:<?= e((string) (int) round((int) $edition[$key] / $max * 100)) ?>%;"></span>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

<?php elseif ($blockKey === 'treue'): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="mt-0 mb-0">Aussteller-Treue</h2>
            <span class="text-sm text-soft">
                <?= e((string) $loyalty['chains']) ?> Unternehmen insgesamt,
                <?= e((string) $loyalty['new_count']) ?> davon bisher nur einmal dabei
            </span>
        </div>
        <div class="card-body">
            <?php if ($loyalty['returning'] === []): ?>
                <p class="text-faint mb-0">
                    Noch keine Mehrfach-Teilnahmen erfasst. Der Zusammenhang zwischen den Jahrgängen
                    entsteht, wenn eine Edition über „Klonen“ aus der vorherigen aufgesetzt wird.
                </p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Unternehmen</th><th>Jahrgänge</th><th>Jahre</th></tr></thead>
                        <tbody>
                        <?php foreach ($loyalty['returning'] as $row): ?>
                            <tr>
                                <td><strong><?= e($row['name']) ?></strong></td>
                                <td><span class="badge badge-success"><?= e((string) $row['count']) ?>×</span></td>
                                <td class="text-sm text-soft"><?= e(implode(', ', $row['years'])) ?></td>
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

<?php endif; ?>
