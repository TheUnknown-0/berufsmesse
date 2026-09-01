<?php
/**
 * Visuelle Raumplanung — Aussteller per Drag & Drop auf Räume verteilen.
 * Erwartet: $grouped (Gebäude → Etage → Räume), $unassigned, $roomCount,
 * $slotCount, $assignedCount, $canEdit.
 *
 * Jede Karte trägt data-exhibitor, jede Ablagefläche data-room —
 * room-plan.js hängt sich daran. Das Select auf jeder Karte ist der
 * Fallback für Touch-Geräte und Tastaturbedienung.
 */

/** Alle Räume flach, für die Auswahlliste auf den Karten. */
$allRooms = [];
foreach ($grouped as $floors) {
    foreach ($floors as $rooms) {
        foreach ($rooms as $room) {
            $allRooms[] = $room;
        }
    }
}

/** Eine Ausstellerkarte. */
$card = static function (array $exhibitor) use ($allRooms, $canEdit): void {
    $id = (int) $exhibitor['id'];
    ?>
    <div class="card card-pad" data-exhibitor="<?= e((string) $id) ?>"<?= $canEdit ? ' draggable="true"' : '' ?>>
        <div class="cluster">
            <?php if ($canEdit): ?>
                <span class="drag-grip" aria-hidden="true" title="Zum Verschieben ziehen — am Touchscreen kurz halten">⠿</span>
            <?php endif; ?>
            <strong style="flex:1;min-width:120px;"><?= e($exhibitor['name']) ?></strong>
            <?php if ((int) $exhibitor['active'] !== 1): ?>
                <span class="badge badge-warning">inaktiv</span>
            <?php endif; ?>
        </div>
        <div class="text-sm text-soft">
            <?= e((string) $exhibitor['demand']) ?> Wünsche
            <?php if ((int) $exhibitor['total_slots'] > 0): ?>
                · <?= e((string) $exhibitor['total_slots']) ?> Plätze ohne Raum
            <?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
            <select class="input" data-move-exhibitor="<?= e((string) $id) ?>" aria-label="Raum wählen">
                <option value="0"<?= $exhibitor['room_id'] === null ? ' selected' : '' ?>>— nicht zugeteilt —</option>
                <?php foreach ($allRooms as $room): ?>
                    <option value="<?= e((string) $room['id']) ?>"<?= (int) ($exhibitor['room_id'] ?? 0) === (int) $room['id'] ? ' selected' : '' ?>>
                        <?= e((string) $room['room_number']) ?><?= $room['room_name'] !== null && $room['room_name'] !== '' ? ' — ' . e((string) $room['room_name']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        <?php endif; ?>
    </div>
    <?php
};
?>
<?php /* Wurzel für room-plan.js: trägt den Speicher-Endpunkt. */ ?>
<div data-roomplan data-assign-url="<?= e($ctx->schoolUrl('/api/raumplan/zuteilen')) ?>"></div>

<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/raeume')) ?>">← Räume</a></div>
        <h1 class="page-title">Raumplanung</h1>
        <p class="page-sub">
            Aussteller auf einen Raum ziehen — am Touchscreen kurz halten, dann ziehen.
            Alternativ auf der Karte den Raum auswählen. Änderungen werden sofort gespeichert.
        </p>
    </div>
</div>

<?php if ($roomCount === 0): ?>
    <div class="empty-state">
        <div class="empty-icon">🚪</div>
        <p>Es sind noch keine Räume angelegt.</p>
        <a class="btn btn-primary" href="<?= e($ctx->schoolUrl('/admin/raeume')) ?>">Räume anlegen</a>
    </div>
<?php else: ?>

<?php foreach (page_blocks('admin-raumplan', [
    'kennzahlen' => 'Kennzahlen',
    'nicht-zugeteilt' => 'Nicht zugeteilte Aussteller',
    'raeume' => 'Räume',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $roomCount) ?></div>
            <div class="stat-label">Räume</div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-value"><?= e((string) $assignedCount) ?></div>
            <div class="stat-label">Aussteller mit Raum</div>
        </div>
        <div class="stat-card<?= $unassigned !== [] ? ' stat-danger' : '' ?>">
            <div class="stat-value"><?= e((string) count($unassigned)) ?></div>
            <div class="stat-label">ohne Raum</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $slotCount) ?></div>
            <div class="stat-label">feste Zeitslots</div>
        </div>
    </div>

<?php elseif ($blockKey === 'nicht-zugeteilt'): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="mt-0 mb-0">Nicht zugeteilt</h2>
            <span class="badge" data-room-count="0"><?= e((string) count($unassigned)) ?></span>
        </div>
        <div class="card-body" data-room="0">
            <div class="grid-3" data-room-list="0">
                <?php foreach ($unassigned as $exhibitor): ?>
                    <?php $card($exhibitor); ?>
                <?php endforeach; ?>
            </div>
            <?php if ($unassigned === []): ?>
                <p class="text-faint mb-0" data-empty-hint>Alle Aussteller haben einen Raum.</p>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($blockKey === 'raeume'): ?>
    <?php foreach ($grouped as $building => $floors): ?>
        <h2><?= e((string) $building) ?></h2>
        <?php foreach ($floors as $floor => $rooms): ?>
            <div class="text-sm text-soft"><?= e((string) $floor) ?></div>
            <div class="grid-2">
                <?php foreach ($rooms as $room): ?>
                    <?php
                    $roomId = (int) $room['id'];
                    $occupants = count($room['exhibitors']);
                    $overbooked = $occupants > 1;
                    $tight = !$overbooked && $room['seats'] > 0 && $room['demand'] > $room['seats'];
                    ?>
                    <div class="card">
                        <div class="card-header">
                            <h3 class="mt-0 mb-0">
                                <?= e((string) $room['room_number']) ?>
                                <?php if (!empty($room['room_name'])): ?>
                                    <span class="text-soft text-sm"><?= e((string) $room['room_name']) ?></span>
                                <?php endif; ?>
                            </h3>
                            <span class="badge" data-room-count="<?= e((string) $roomId) ?>"><?= e((string) $occupants) ?></span>
                        </div>
                        <div class="card-body" data-room="<?= e((string) $roomId) ?>">
                            <div class="text-sm text-soft">
                                <?= e((string) $room['capacity']) ?> Plätze je Slot
                                · <?= e((string) $room['seats']) ?> Plätze am Tag
                                · <?= e((string) $room['demand']) ?> Wünsche
                            </div>

                            <?php if ($overbooked): ?>
                                <div class="alert alert-warning" data-room-warning="<?= e((string) $roomId) ?>">
                                    <?= e((string) $occupants) ?> Aussteller in einem Raum — sie teilen sich die Plätze.
                                </div>
                            <?php elseif ($tight): ?>
                                <div class="alert alert-info">
                                    Mehr Wünsche als Plätze — nicht alle werden hier unterkommen.
                                </div>
                            <?php endif; ?>

                            <div class="stack" data-room-list="<?= e((string) $roomId) ?>">
                                <?php foreach ($room['exhibitors'] as $exhibitor): ?>
                                    <?php $card($exhibitor); ?>
                                <?php endforeach; ?>
                            </div>
                            <?php if ($room['exhibitors'] === []): ?>
                                <p class="text-faint mb-0" data-empty-hint>Noch kein Aussteller — hierher ziehen.</p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    <?php endforeach; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php endif; ?>
