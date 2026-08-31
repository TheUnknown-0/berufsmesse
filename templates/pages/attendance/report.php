<?php
/**
 * Auswertung nach der Messe.
 * Erwartet: $totalRegistrations, $totalAttendance, $totalWrongRoom, $overallPct,
 *           $wrongRoomPct, $methods, $perSlot, $missingBySlot, $topExhibitors
 */
$methodLabels = ['self_scan' => 'Selbst-Scan', 'teacher_scan' => 'Lehrer-Scan', 'manual' => 'Manuell'];
$methodSum = array_sum($methods);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Auswertung</div>
        <h1 class="page-title">Anwesenheits-Bericht</h1>
        <p class="page-sub">Kennzahlen der aktiven Messe — Grundlage für Nachbereitung und Schulleitung.</p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/anwesenheit')) ?>">📋 Liste</a>
    </div>
</div>

<?php foreach (page_blocks('admin-anwesenheit-bericht', [
    'kennzahlen' => 'Kennzahlen',
    'auswertung' => 'Zeitslots & Check-in-Methoden',
    'fehlende' => 'Fehlende Schüler:innen je Zeitslot',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card stat-success">
        <div class="stat-value"><?= e((string) $overallPct) ?>%</div>
        <div class="stat-label">Anwesenheitsquote</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $totalAttendance) ?></div>
        <div class="stat-label">Check-ins gesamt</div>
    </div>
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) $wrongRoomPct) ?>%</div>
        <div class="stat-label">Quote „falscher Raum“</div>
    </div>
    <div class="stat-card stat-danger">
        <div class="stat-value"><?= e((string) max(0, $totalRegistrations - $totalAttendance)) ?></div>
        <div class="stat-label">Fehlende Check-ins</div>
    </div>
</div>

<?php elseif ($blockKey === 'auswertung'): ?>
<div class="grid-2">
    <div class="card">
        <div class="card-header"><h2>Anwesenheit je Zeitslot</h2></div>
        <div class="table-wrap" style="border:none;">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Slot</th>
                    <th>Anwesend</th>
                    <th>Zugeteilt</th>
                    <th>Falscher Raum</th>
                    <th>Quote</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($perSlot as $slot): ?>
                    <?php
                    $registered = (int) $slot['registered'];
                    $present = (int) $slot['present'];
                    $pct = $registered > 0 ? (int) round($present / $registered * 100) : 0;
                    ?>
                    <tr>
                        <td>
                            <?= e($slot['slot_name'] ?? ('Slot ' . $slot['slot_number'])) ?>
                            <?php if ((int) $slot['is_managed'] === 0): ?>
                                <span class="badge badge-accent">frei</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string) $present) ?></td>
                        <td><?= e((string) $registered) ?></td>
                        <td><?= e((string) (int) $slot['wrong']) ?></td>
                        <td>
                            <span class="badge <?= $pct >= 90 ? 'badge-success' : ($pct >= 60 ? 'badge-info' : 'badge-warning') ?>">
                                <?= e((string) $pct) ?>%
                            </span>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($perSlot === []): ?>
                    <tr><td colspan="5" class="text-faint">Keine Zeitslots angelegt.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><h2>Check-in-Methoden</h2></div>
        <div class="card-body">
            <?php foreach ($methodLabels as $key => $label): ?>
                <?php
                $count = $methods[$key] ?? 0;
                $pct = $methodSum > 0 ? (int) round($count / $methodSum * 100) : 0;
                ?>
                <div class="mb-2">
                    <div class="cluster" style="justify-content:space-between;">
                        <span><?= e($label) ?></span>
                        <span class="text-soft text-sm"><?= e((string) $count) ?> · <?= e((string) $pct) ?>%</span>
                    </div>
                    <div class="progress"><span style="width:<?= e((string) $pct) ?>%"></span></div>
                </div>
            <?php endforeach; ?>
            <?php if ($methodSum === 0): ?>
                <p class="text-faint mb-0">Noch keine Check-ins erfasst.</p>
            <?php endif; ?>

            <hr class="divider">
            <h3>Meistbesuchte Aussteller</h3>
            <ol class="stack" style="gap:4px;padding-left:20px;margin:0;">
                <?php foreach ($topExhibitors as $exhibitor): ?>
                    <li>
                        <?= e($exhibitor['name']) ?>
                        <span class="text-soft text-sm">— <?= e((string) (int) $exhibitor['anzahl']) ?></span>
                    </li>
                <?php endforeach; ?>
                <?php if ($topExhibitors === []): ?>
                    <li class="text-faint">Noch keine Check-ins.</li>
                <?php endif; ?>
            </ol>
        </div>
    </div>
</div>

<?php elseif ($blockKey === 'fehlende'): ?>
<div class="card mt-2">
    <div class="card-header"><h2>Fehlende Schüler:innen je Zeitslot</h2></div>
    <div class="card-body">
        <?php $anyMissing = false; ?>
        <?php foreach ($perSlot as $slot): ?>
            <?php
            $slotId = (int) $slot['id'];
            $missing = $missingBySlot[$slotId] ?? [];
            if ($missing !== []) {
                $anyMissing = true;
            }
            ?>
            <div class="mb-2">
                <h3 class="mb-0">
                    <?= e($slot['slot_name'] ?? ('Slot ' . $slot['slot_number'])) ?>
                    <span class="badge <?= $missing === [] ? 'badge-success' : 'badge-warning' ?>">
                        <?= e((string) count($missing)) ?>
                    </span>
                </h3>
                <?php if ($missing === []): ?>
                    <p class="text-soft text-sm mb-0">Alle zugeteilten Schüler:innen waren anwesend.</p>
                <?php else: ?>
                    <div class="chip-row mt-2">
                        <?php foreach ($missing as $person): ?>
                            <span class="badge">
                                <?= e($person['class'] ?? '—') ?> ·
                                <?= e(trim((string) $person['firstname'] . ' ' . (string) $person['lastname'])) ?>
                                <span class="text-faint">(<?= e($person['exhibitor_name']) ?>)</span>
                            </span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
            <hr class="divider">
        <?php endforeach; ?>
        <?php if (!$anyMissing && $perSlot !== []): ?>
            <p class="text-success mb-0">Keine fehlenden Check-ins — vollständige Anwesenheit.</p>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
