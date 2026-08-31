<?php
/**
 * Admin-Dashboard: Kennzahlen, Auto-Zuteilung, Diagramme, Raumplan.
 * Erwartet: $edition, $stats, $chartData, $slots, $managedSlots, $exhibitors,
 *           $occupancy, $canAssign, $canReset, $statsUrl.
 */
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$slotLabel = static fn (array $slot): string => (string) ($slot['slot_name'] ?? '') !== ''
    ? (string) $slot['slot_name']
    : 'Slot ' . (int) $slot['slot_number'];
$quote = static fn (int $part, int $total): int => $total > 0 ? (int) round($part / $total * 100) : 0;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($edition['name']) ?></div>
        <h1 class="page-title">Dashboard</h1>
        <p class="page-sub">Zentrale Übersicht über Einschreibung, Zuteilung und Auslastung.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($ctx->schoolUrl('/admin/anmeldungen')) ?>">📝 Anmeldungen</a>
        <a class="btn" href="<?= e($ctx->schoolUrl('/admin/einstellungen')) ?>">⚙️ Einstellungen</a>
    </div>
</div>

<?php $dashboardBlocks = page_blocks('admin-dashboard', [
    'statistik' => 'Statistik-Kacheln',
    'zuteilung' => 'Automatische Zuteilung',
    'diagramme' => 'Diagramme',
    'raumplan' => 'Raumplan-Kurzübersicht',
]); ?>
<?php foreach ($dashboardBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'statistik'): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $stats['students_total']) ?></div>
        <div class="stat-label">Schüler:innen gesamt</div>
    </div>
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) $stats['students_registered']) ?></div>
        <div class="stat-label">
            eingeschrieben ·
            <?= e((string) $quote($stats['students_registered'], $stats['students_total'])) ?> %
        </div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-value"><?= e((string) $stats['students_complete']) ?></div>
        <div class="stat-label">vollständig zugeteilt</div>
    </div>
    <div class="stat-card stat-danger">
        <div class="stat-value"><?= e((string) $stats['students_without']) ?></div>
        <div class="stat-label">ohne Anmeldung</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $stats['exhibitors_active']) ?></div>
        <div class="stat-label">aktive Aussteller</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $stats['rooms']) ?></div>
        <div class="stat-label">Räume</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $stats['registrations_total']) ?></div>
        <div class="stat-label">
            Anmeldungen · <?= e((string) $stats['registrations_pending']) ?> offen
        </div>
    </div>
</div>

<?php elseif ($blockKey === 'zuteilung'): ?>
<?php if ($canAssign || $canReset): ?>
    <div class="card mb-2">
        <div class="card-header">
            <h2>Automatische Zuteilung</h2>
            <span class="badge"><?= e((string) $stats['managed_slots']) ?> feste Slots</span>
        </div>
        <div class="card-body">
            <p class="text-sm text-soft">
                <strong>Phase 1</strong> verteilt alle offenen Wünsche nach Priorität auf freie Slots und entfernt
                überschüssige Anmeldungen. <strong>Phase 2</strong> füllt Schüler:innen mit Lücken automatisch mit
                Ausstellern auf, die im jeweiligen Slot noch Platz haben.
            </p>
            <div class="cluster">
                <?php if ($canAssign): ?>
                    <form method="post" action="<?= e($ctx->schoolUrl('/admin/zuteilung/simulation')) ?>">
                        <?= $csrf->field() ?>
                        <button class="btn btn-accent" type="submit">🔍 Probelauf (ändert nichts)</button>
                    </form>
                    <form method="post" action="<?= e($ctx->schoolUrl('/admin/zuteilung/ausfuehren')) ?>"
                          data-confirm="Phase 1 der automatischen Zuteilung jetzt starten?">
                        <?= $csrf->field() ?>
                        <button class="btn btn-primary" type="submit">▶️ Phase 1: Wünsche zuteilen</button>
                    </form>
                    <form method="post" action="<?= e($ctx->schoolUrl('/admin/zuteilung/auffuellen')) ?>"
                          data-confirm="Unvollständige Zuteilungen jetzt automatisch auffüllen?">
                        <?= $csrf->field() ?>
                        <button class="btn btn-accent" type="submit">➕ Phase 2: Unvollständige auffüllen</button>
                    </form>
                <?php endif; ?>
                <?php if ($canReset): ?>
                    <form method="post" action="<?= e($ctx->schoolUrl('/admin/zuteilung/zuruecksetzen')) ?>"
                          data-confirm="Wirklich die gesamte Zuteilung zurücksetzen? Automatisch erzeugte Anmeldungen werden gelöscht.">
                        <?= $csrf->field() ?>
                        <button class="btn btn-danger-ghost" type="submit">↺ Zuteilung zurücksetzen</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'diagramme'): ?>
<div data-dashboard
     data-charts="<?= e(json_encode($chartData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?>"
     data-stats-url="<?= e($statsUrl) ?>">
    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h3>Anmeldungen je Aussteller (Top 15)</h3></div>
            <div class="card-body">
                <?php if ($chartData['exhibitors']['labels'] === []): ?>
                    <div class="empty-state"><p>Noch keine Daten vorhanden.</p></div>
                <?php else: ?>
                    <canvas id="chart-exhibitors" height="360" aria-label="Anmeldungen je Aussteller"></canvas>
                <?php endif; ?>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><h3>Auslastung je Zeitslot</h3></div>
            <div class="card-body">
                <?php if ($chartData['slots']['labels'] === []): ?>
                    <div class="empty-state"><p>Noch keine Zeitslots eingerichtet.</p></div>
                <?php else: ?>
                    <canvas id="chart-slots" height="360" aria-label="Belegung und Kapazität je Zeitslot"></canvas>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($blockKey === 'raumplan'): ?>
<div class="card mt-2">
    <div class="card-header">
        <h2>Raumplan-Kurzübersicht</h2>
        <span class="text-sm text-soft">belegt / Kapazität je festem Slot</span>
    </div>
    <?php if ($exhibitors === [] || $managedSlots === []): ?>
        <div class="card-body">
            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">🚪</div>
                <p>Für die Übersicht braucht es aktive Aussteller und feste Zeitslots.</p>
            </div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Aussteller</th>
                    <th>Raum</th>
                    <?php foreach ($managedSlots as $slot): ?>
                        <th>
                            <?= e($slotLabel($slot)) ?><br>
                            <span class="mono text-faint"><?= e($time($slot['start_time'])) ?></span>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($exhibitors as $exhibitor): ?>
                    <?php $exhibitorId = (int) $exhibitor['id']; ?>
                    <tr>
                        <td><strong><?= e($exhibitor['name']) ?></strong></td>
                        <td class="text-sm text-soft">
                            <?php if (!empty($exhibitor['room_number'])): ?>
                                <?= e($exhibitor['room_number']) ?>
                                <?php if (!empty($exhibitor['room_name'])): ?>
                                    <div class="text-faint"><?= e($exhibitor['room_name']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="badge badge-warning">kein Raum</span>
                            <?php endif; ?>
                        </td>
                        <?php foreach ($managedSlots as $slot): ?>
                            <?php
                            $cell = $occupancy[$exhibitorId][(int) $slot['id']] ?? ['capacity' => 0, 'used' => 0, 'free' => 0];
                            $badge = 'badge-success';
                            if ($cell['capacity'] === 0) {
                                $badge = 'badge-danger';
                            } elseif ($cell['free'] === 0) {
                                $badge = 'badge-danger';
                            } elseif ($cell['used'] >= $cell['capacity'] * 0.8) {
                                $badge = 'badge-warning';
                            }
                            ?>
                            <td>
                                <span class="badge <?= e($badge) ?> mono">
                                    <?= e((string) $cell['used']) ?> / <?= e((string) $cell['capacity']) ?>
                                </span>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
