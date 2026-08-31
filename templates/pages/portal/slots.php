<?php
/**
 * Aussteller-Portal — Slots & Anmeldungen (nur Zahlen, keine Namen).
 * Erwartet: $rows (je Unternehmen: exhibitor, slots, unassigned, total, classes).
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Aussteller-Portal</div>
        <h1 class="page-title">Slots & Anmeldungen</h1>
        <p class="page-sub">Aus Datenschutzgründen zeigen wir hier ausschließlich Zahlen — keine Namen.</p>
    </div>
</div>

<?php foreach (page_blocks('portal-slots', [
    'hinweis' => 'Hinweis ohne Unternehmen',
    'slot-uebersicht' => 'Slot-Übersicht je Unternehmen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweis'): ?>
<?php if ($rows === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🗓️</div>
        <p>Deinem Konto ist an dieser Schule kein Unternehmen zugeordnet.</p>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'slot-uebersicht'): ?>
<?php foreach ($rows as $row): ?>
    <?php $exhibitor = $row['exhibitor']; ?>
    <div class="card">
        <div class="card-header">
            <h3 style="margin:0;"><?= e($exhibitor['name']) ?></h3>
            <span class="badge badge-primary"><?= e((string) $row['total']) ?> Anmeldungen</span>
        </div>
        <div class="card-body">
            <div class="chip-row">
                <span class="badge badge-info">
                    <?= $exhibitor['room_number'] !== null
                        ? 'Raum ' . e($exhibitor['room_number'])
                        : 'Ohne Raum — Kapazität aus „Plätze gesamt"' ?>
                </span>
                <?php if ($row['unassigned'] > 0): ?>
                    <span class="badge badge-warning"><?= e((string) $row['unassigned']) ?> noch nicht zugeteilt</span>
                <?php endif; ?>
            </div>

            <?php if ($row['slots'] === []): ?>
                <div class="empty-state">
                    <p>Für diese Messe sind noch keine Zuteilungs-Slots angelegt.</p>
                </div>
            <?php else: ?>
                <div class="table-wrap" style="margin-top:12px;">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Slot</th>
                            <th>Zeit</th>
                            <th>Angemeldet</th>
                            <th>Kapazität</th>
                            <th>Auslastung</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($row['slots'] as $slot): ?>
                            <?php
                            $capacity = (int) $slot['capacity'];
                            $registered = (int) $slot['registered'];
                            $percent = $capacity > 0 ? (int) round($registered / $capacity * 100) : 0;
                            ?>
                            <tr>
                                <td><?= e($slot['slot_name'] !== null && $slot['slot_name'] !== ''
                                        ? $slot['slot_name']
                                        : 'Slot ' . $slot['slot_number']) ?></td>
                                <td class="mono nowrap">
                                    <?= e(substr((string) $slot['start_time'], 0, 5)) ?>–<?= e(substr((string) $slot['end_time'], 0, 5)) ?>
                                </td>
                                <td><?= e((string) $registered) ?></td>
                                <td><?= e((string) $capacity) ?></td>
                                <td>
                                    <div class="progress" role="img"
                                         aria-label="<?= e((string) min(100, $percent)) ?> Prozent belegt">
                                        <span style="width:<?= e((string) min(100, $percent)) ?>%;"></span>
                                    </div>
                                    <span class="text-sm text-soft"><?= e((string) $percent) ?> %</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if ($row['classes'] !== []): ?>
                <div class="divider"></div>
                <div class="text-sm text-soft" style="margin-bottom:6px;">Verteilung nach Klassen</div>
                <div class="chip-row">
                    <?php foreach ($row['classes'] as $class): ?>
                        <span class="badge badge-info">
                            <?= e($class['class_name']) ?>: <?= e((string) $class['anzahl']) ?>
                        </span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
