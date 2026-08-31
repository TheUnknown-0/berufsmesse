<?php
/**
 * Live-Monitor: Kacheln je Raum/Aussteller im gewählten Slot, alle 10 s aktualisiert.
 * Erwartet: $slots, $currentSlotId, $edition
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Messetag</div>
        <h1 class="page-title">Anwesenheit live</h1>
        <p class="page-sub">Aktualisiert sich automatisch alle 10 Sekunden.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/anwesenheit')) ?>">📋 Liste</a>
    </div>
</div>

<?php foreach (page_blocks('admin-anwesenheit-live', [
    'hinweis' => 'Hinweis',
    'monitor' => 'Live-Monitor',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweis'): ?>
<?php if ($slots === []): ?>
    <div class="alert alert-warning">
        <span>⚠️</span>
        <div>Für diese Messe sind noch keine Zeitslots angelegt.</div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'monitor'): ?>
<?php if ($slots !== []): ?>
    <div id="live-app"
         data-endpoint="<?= e($ctx->schoolUrl('/api/anwesenheit/live')) ?>"
         data-interval="10000">

        <div class="card card-pad mb-2">
            <div class="cluster">
                <div class="field mb-0" style="min-width:240px;">
                    <label for="live-slot">Zeitslot</label>
                    <select id="live-slot">
                        <?php foreach ($slots as $slot): ?>
                            <option value="<?= e((string) (int) $slot['id']) ?>"
                                <?= $currentSlotId === (int) $slot['id'] ? 'selected' : '' ?>>
                                <?= e($slot['slot_name'] ?? ('Slot ' . $slot['slot_number'])) ?>
                                (<?= e(substr((string) $slot['start_time'], 0, 5)) ?>–<?= e(substr((string) $slot['end_time'], 0, 5)) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <span class="badge" id="live-updated">–</span>
            </div>
        </div>

        <div class="stat-grid" id="live-totals"></div>

        <div class="grid-2">
            <div class="card">
                <div class="card-header"><h2>🏢 Räume &amp; Aussteller</h2></div>
                <div class="card-body">
                    <div class="stack" id="live-tiles"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header"><h2>⏱️ Letzte Check-ins</h2></div>
                <div class="card-body">
                    <div class="stack" id="live-latest" style="gap:6px;"></div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
