<?php
/**
 * Ergebnis des Zuteilungs-Probelaufs.
 * Erwartet: $result (before, after, phase1, phase2, unfulfilled), $withFill.
 *
 * Alle Zahlen stammen aus einem echten, anschließend zurückgerollten
 * Zuteilungslauf — sie zeigen also exakt, was beim Scharfschalten passiert.
 */
$before = $result['before'];
$after = $result['after'];
$phase1 = $result['phase1'];
$phase2 = $result['phase2'];

/** Anteil erfüllter Wünsche einer Priorität, als Prozentzahl. */
$share = static function (array $entry): int {
    return $entry['total'] > 0 ? (int) round($entry['assigned'] / $entry['total'] * 100) : 0;
};
$priorityLabels = [1 => 'Erstwunsch', 2 => 'Zweitwunsch', 3 => 'Drittwunsch'];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">
            <a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/dashboard')) ?>">← Dashboard</a>
        </div>
        <h1 class="page-title">Probelauf der Zuteilung</h1>
        <p class="page-sub">
            <?= $withFill ? 'Zuteilung der Wünsche und anschließendes Auffüllen der Lücken.' : 'Nur die Zuteilung der vorhandenen Wünsche — ohne Auffüllen.' ?>
        </p>
    </div>
</div>

<div class="alert alert-info" role="status">
    <strong>Nichts wurde gespeichert.</strong> Der Lauf wurde vollständig durchgerechnet und wieder verworfen.
    Erst „Zuteilung ausführen“ auf dem Dashboard macht das Ergebnis dauerhaft.
</div>

<div class="stat-grid">
    <div class="stat-card stat-success">
        <div class="stat-value"><?= e((string) $phase1['assigned']) ?></div>
        <div class="stat-label">Wünsche würden zugeteilt</div>
    </div>
    <?php if ($phase2 !== null): ?>
        <div class="stat-card stat-accent">
            <div class="stat-value"><?= e((string) $phase2['created']) ?></div>
            <div class="stat-label">Lücken würden gefüllt</div>
        </div>
    <?php endif; ?>
    <div class="stat-card<?= $phase1['skipped'] > 0 ? ' stat-danger' : '' ?>">
        <div class="stat-value"><?= e((string) $phase1['skipped']) ?></div>
        <div class="stat-label">Wünsche ohne freien Platz</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $phase1['deleted']) ?></div>
        <div class="stat-label">überschüssige Wünsche</div>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="mt-0 mb-0">Tagespläne der Schüler:innen</h2></div>
    <div class="card-body">
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th></th>
                    <th>vorher</th>
                    <th>nachher</th>
                    <th>Veränderung</th>
                </tr>
                </thead>
                <tbody>
                <?php
                $rows = [
                    'Vollständiger Plan (alle ' . $after['slots'] . ' Slots)' => ['full_plans', true],
                    'Teilweise belegt' => ['partial_plans', false],
                    'Ganz ohne Zuteilung' => ['empty_plans', false],
                    'Offene Wünsche' => ['open', false],
                    'Ungenutzte Plätze' => ['free_seats', false],
                ];
                ?>
                <?php foreach ($rows as $label => [$key, $isGood]): ?>
                    <?php $delta = $after[$key] - $before[$key]; ?>
                    <tr>
                        <td><?= e($label) ?></td>
                        <td class="text-soft"><?= e((string) $before[$key]) ?></td>
                        <td><strong><?= e((string) $after[$key]) ?></strong></td>
                        <td>
                            <?php if ($delta === 0): ?>
                                <span class="text-faint">±0</span>
                            <?php else: ?>
                                <span class="badge <?= ($delta > 0) === $isGood ? 'badge-success' : 'badge-warning' ?>">
                                    <?= e(($delta > 0 ? '+' : '') . $delta) ?>
                                </span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-sm text-soft">
            <?= e((string) $after['students']) ?> Schüler:innen sind dieser Messe zugeordnet,
            <?= e((string) $after['slots']) ?> feste Zeitslots stehen zur Verfügung.
        </p>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="mt-0 mb-0">Erfüllte Wünsche nach Priorität</h2></div>
    <div class="card-body">
        <?php if ($after['by_priority'] === []): ?>
            <p class="text-faint mb-0">Es liegen keine priorisierten Wünsche vor.</p>
        <?php else: ?>
            <div class="stack">
                <?php foreach ($after['by_priority'] as $priority => $entry): ?>
                    <div>
                        <div class="cluster">
                            <span style="flex:1;min-width:140px;">
                                <?= e($priorityLabels[$priority] ?? ('Priorität ' . $priority)) ?>
                            </span>
                            <span class="text-sm text-soft">
                                <?= e((string) $entry['assigned']) ?> von <?= e((string) $entry['total']) ?>
                                · <?= e((string) $share($entry)) ?> %
                            </span>
                        </div>
                        <div class="progress"><span style="width:<?= e((string) $share($entry)) ?>%;"></span></div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-header"><h2 class="mt-0 mb-0">Wo die Plätze nicht reichen</h2></div>
    <div class="card-body">
        <?php if ($result['unfulfilled'] === []): ?>
            <div class="empty-state">
                <div class="empty-icon">✅</div>
                <p>Jeder Wunsch konnte bedient werden.</p>
            </div>
        <?php else: ?>
            <p class="text-sm text-soft">
                Bei diesen Ausstellern bleiben Wünsche offen — mehr Kapazität oder ein zusätzlicher
                Slot würde hier am meisten bringen.
            </p>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr><th>Aussteller</th><th>offene Wünsche</th><th>davon Erstwünsche</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach ($result['unfulfilled'] as $row): ?>
                        <tr>
                            <td>
                                <a href="<?= e($ctx->schoolUrl('/admin/aussteller/' . (int) $row['id'])) ?>">
                                    <?= e($row['name']) ?>
                                </a>
                            </td>
                            <td><strong><?= e((string) $row['offen']) ?></strong></td>
                            <td><?= e((string) (int) $row['erstwuensche']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="cluster">
    <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/dashboard')) ?>">Zurück zum Dashboard</a>
    <form method="post" action="<?= e($ctx->schoolUrl('/admin/zuteilung/simulation')) ?>">
        <?= $csrf->field() ?>
        <?php if (!$withFill): ?>
            <input type="hidden" name="auffuellen" value="1">
            <button class="btn btn-accent" type="submit">Probelauf mit Auffüllen</button>
        <?php else: ?>
            <button class="btn btn-ghost" type="submit">Probelauf ohne Auffüllen</button>
        <?php endif; ?>
    </form>
</div>
