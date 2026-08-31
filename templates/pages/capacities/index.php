<?php
/**
 * Kapazitäts-Matrix: Räume × verwaltete Zeitslots.
 * Erwartet: $rooms, $slots, $capacities[roomId][slotId], $canEdit.
 */
$configured = 0;
foreach ($capacities as $perRoom) {
    $configured += count($perRoom);
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Slot-Kapazitäten</h1>
        <p class="page-sub">Wie viele Schüler:innen passen je Raum und Zeitslot? Leer = Standardkapazität des Raums.</p>
    </div>
</div>

<?php foreach (page_blocks('admin-kapazitaeten', [
    'kennzahlen' => 'Kennzahlen',
    'hinweis' => 'Hinweis zur Standardkapazität',
    'matrix' => 'Kapazitäts-Matrix',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) count($rooms)) ?></div>
        <div class="stat-label">Räume</div>
    </div>
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) count($slots)) ?></div>
        <div class="stat-label">Verwaltete Zeitslots</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-value"><?= e((string) $configured) ?></div>
        <div class="stat-label">Eigene Werte gesetzt</div>
    </div>
</div>

<?php elseif ($blockKey === 'hinweis'): ?>
<?php if ($rooms !== [] && $slots !== []): ?>
    <div class="alert alert-info">
        <div>Ein leeres Feld übernimmt die Standardkapazität des Raums (grau als Platzhalter angezeigt). Ein gesetzter Wert gilt nur für diesen Raum in diesem Zeitslot.</div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'matrix'): ?>
<?php if ($rooms === [] || $slots === []): ?>
    <div class="empty-state">
        <div class="empty-icon">📐</div>
        <p>
            <?php if ($rooms === []): ?>
                Es sind noch keine Räume angelegt.
            <?php else: ?>
                Für diese Messe sind keine verwalteten Zeitslots (is_managed) eingerichtet.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <form method="post" action="<?= e($ctx->schoolUrl('/admin/kapazitaeten')) ?>">
        <?= $csrf->field() ?>
        <div class="table-wrap mb-2">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Raum</th>
                    <th>Standard</th>
                    <?php foreach ($slots as $slot): ?>
                        <th>
                            <?= e($slot['slot_name'] ?? ('Slot ' . (int) $slot['slot_number'])) ?><br>
                            <span class="text-faint"><?= e(format_date($slot['start_time'], 'H:i')) ?>–<?= e(format_date($slot['end_time'], 'H:i')) ?></span>
                        </th>
                    <?php endforeach; ?>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($rooms as $room): ?>
                    <?php $roomId = (int) $room['id']; ?>
                    <tr>
                        <td>
                            <strong><?= e($room['room_number']) ?></strong>
                            <?php if (!empty($room['room_name'])): ?>
                                <div class="text-faint text-sm"><?= e($room['room_name']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="text-soft"><?= e((string) (int) $room['capacity']) ?></td>
                        <?php foreach ($slots as $slot): ?>
                            <?php
                            $slotId = (int) $slot['id'];
                            $value = $capacities[$roomId][$slotId] ?? null;
                            ?>
                            <td>
                                <input class="input" type="number" min="0" max="9999" style="max-width:90px;"
                                       name="capacity[<?= e((string) $roomId) ?>][<?= e((string) $slotId) ?>]"
                                       placeholder="<?= e((string) (int) $room['capacity']) ?>"
                                       value="<?= $value === null ? '' : e((string) $value) ?>"
                                       aria-label="Kapazität <?= e($room['room_number']) ?> / <?= e($slot['slot_name'] ?? ('Slot ' . (int) $slot['slot_number'])) ?>"
                                       <?= $canEdit ? '' : 'disabled' ?>>
                            </td>
                        <?php endforeach; ?>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($canEdit): ?>
            <button class="btn btn-primary btn-lg" type="submit">Kapazitäten speichern</button>
        <?php endif; ?>
    </form>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
