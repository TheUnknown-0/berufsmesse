<?php
/**
 * Tagesplan: Timeline aller Zeitslots inkl. Pausen und freier Wahl.
 * Erwartet: $edition, $timeline.
 */
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($edition['name']) ?></div>
        <h1 class="page-title">Tagesplan</h1>
        <p class="page-sub">
            <?php if (!empty($edition['event_date'])): ?>
                Dein persönlicher Ablauf am <?= e(format_date($edition['event_date'])) ?>.
            <?php else: ?>
                Dein persönlicher Ablauf für die Berufsmesse.
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($ctx->schoolUrl('/meine-anmeldungen')) ?>">⭐ Meine Anmeldungen</a>
        <a class="btn btn-primary" href="<?= e($ctx->schoolUrl('/drucken')) ?>">🖨️ Plan drucken</a>
    </div>
</div>

<?php $scheduleBlocks = page_blocks('tagesplan', [
    'keine-slots' => 'Hinweis ohne Zeitslots',
    'zeitleiste' => 'Zeitleiste',
]); ?>
<?php foreach ($scheduleBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'keine-slots'): ?>
    <?php if ($timeline === []): ?>
        <div class="card card-pad">
            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">🗓️</div>
                <p>Für diese Messe sind noch keine Zeitslots eingerichtet.</p>
            </div>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'zeitleiste'): ?>
    <?php if ($timeline !== []): ?>
        <div class="stack">
            <?php foreach ($timeline as $slot): ?>
                <?php $registration = $slot['registration']; ?>
                <div class="card">
                    <div class="card-body">
                        <div class="cluster" style="align-items:flex-start;">
                            <div class="mono nowrap" style="min-width:120px;">
                                <strong><?= e($time($slot['start_time'])) ?>–<?= e($time($slot['end_time'])) ?></strong>
                                <div class="text-sm text-faint">Slot <?= e((string) $slot['slot_number']) ?></div>
                            </div>
                            <div style="flex:1;min-width:220px;">
                                <?php if ($slot['kind'] === 'break'): ?>
                                    <h3 style="margin-bottom:2px;">☕ <?= e($slot['slot_name'] ?? 'Pause') ?></h3>
                                    <p class="text-sm text-soft mb-0">Zeit zum Durchatmen und für den Austausch.</p>
                                <?php elseif ($slot['kind'] === 'free'): ?>
                                    <h3 style="margin-bottom:2px;">
                                        👆 <?= e($slot['slot_name'] ?? ('Slot ' . (int) $slot['slot_number'])) ?>
                                        <span class="badge badge-info">Freie Wahl</span>
                                    </h3>
                                    <?php if ($registration !== null): ?>
                                        <p class="text-sm mb-0">
                                            Bereits eingecheckt bei <strong><?= e($registration['exhibitor_name']) ?></strong>
                                            <?php if (!empty($registration['room_number'])): ?>
                                                · Raum <?= e($registration['room_number']) ?>
                                            <?php endif; ?>
                                        </p>
                                    <?php else: ?>
                                        <p class="text-sm text-soft mb-0">
                                            Besuche einen Aussteller deiner Wahl — vor Ort per Check-in anmelden.
                                        </p>
                                    <?php endif; ?>
                                <?php elseif ($registration !== null): ?>
                                    <h3 style="margin-bottom:2px;"><?= e($registration['exhibitor_name']) ?></h3>
                                    <?php if (!empty($registration['short_description'])): ?>
                                        <p class="text-sm text-soft" style="margin-bottom:6px;">
                                            <?= e($registration['short_description']) ?>
                                        </p>
                                    <?php endif; ?>
                                    <div class="chip-row">
                                        <span class="badge badge-primary">
                                            <?= e($slot['slot_name'] ?? ('Slot ' . (int) $slot['slot_number'])) ?>
                                        </span>
                                        <?php if (!empty($registration['room_number'])): ?>
                                            <span class="badge">
                                                🚪 Raum <?= e($registration['room_number']) ?><?= !empty($registration['room_name']) ? ' · ' . e($registration['room_name']) : '' ?>
                                            </span>
                                        <?php endif; ?>
                                        <?php if (!empty($registration['building'])): ?>
                                            <span class="badge"><?= e($registration['building']) ?></span>
                                        <?php endif; ?>
                                        <?php if ($registration['registration_type'] === 'automatic'): ?>
                                            <span class="badge badge-info">Automatisch zugeteilt</span>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <h3 style="margin-bottom:2px;">
                                        <?= e($slot['slot_name'] ?? ('Slot ' . (int) $slot['slot_number'])) ?>
                                    </h3>
                                    <p class="text-sm text-soft mb-0">Noch keine Zuteilung erhalten.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
