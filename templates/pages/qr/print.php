<?php
/**
 * Druckbogen: alle Slot-QR-Codes eines Ausstellers auf einer Seite.
 * Erwartet: $exhibitor, $sheets (slot, token, url), $edition
 */
$imgBase = $ctx->schoolUrl('/api/qr/bild');
$roomLabel = trim((string) ($exhibitor['room_number'] ?? '') . ' ' . (string) ($exhibitor['room_name'] ?? ''));
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">QR-Druckbogen</div>
        <h1 class="page-title"><?= e($exhibitor['name']) ?></h1>
        <p class="page-sub">
            <?= $roomLabel !== '' ? 'Raum ' . e($roomLabel) : 'Kein Raum zugeordnet' ?>
            <?php if (!empty($edition['event_date'])): ?>
                · Messetag <?= e(format_date($edition['event_date'])) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/qr-codes')) ?>">← Zurück</a>
    </div>
</div>

<div class="alert alert-info no-print">
    <span>🖨️</span>
    <div>Diese Seite ist für den Ausdruck gedacht: über das Browser-Druckmenü (Strg + P) ausgeben und am Stand aushängen.</div>
</div>

<div class="stack">
    <?php foreach ($sheets as $sheet): ?>
        <?php $slot = $sheet['slot']; ?>
        <div class="card card-pad" style="break-inside:avoid;page-break-inside:avoid;">
            <div class="cluster" style="align-items:center;gap:24px;">
                <?php if ($sheet['token'] !== null): ?>
                    <div class="qr-frame">
                        <img src="<?= e($imgBase . '?scale=8&data=' . urlencode((string) $sheet['url'])) ?>"
                             width="272" height="272"
                             alt="QR-Code <?= e($exhibitor['name']) ?> <?= e($slot['slot_name'] ?? '') ?>">
                    </div>
                <?php else: ?>
                    <div class="empty-state" style="padding:32px;">
                        <div class="empty-icon">❔</div>
                        <div>Noch kein Code erzeugt.</div>
                    </div>
                <?php endif; ?>
                <div>
                    <h2><?= e($slot['slot_name'] ?? ('Slot ' . $slot['slot_number'])) ?></h2>
                    <p class="text-soft mb-0">
                        <?= e(substr((string) $slot['start_time'], 0, 5)) ?>–<?= e(substr((string) $slot['end_time'], 0, 5)) ?> Uhr
                        <?php if ((int) $slot['is_managed'] === 0): ?>
                            · <span class="badge badge-accent">Freie Wahl</span>
                        <?php endif; ?>
                    </p>
                    <p class="mb-0"><strong><?= e($exhibitor['name']) ?></strong></p>
                    <?php if ($roomLabel !== ''): ?>
                        <p class="text-soft mb-0">Raum <?= e($roomLabel) ?></p>
                    <?php endif; ?>
                    <?php if ($sheet['token'] !== null): ?>
                        <p class="mono text-sm mt-2 mb-0">Code zum Eintippen: <strong><?= e((string) $sheet['token']) ?></strong></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <?php if ($sheets === []): ?>
        <div class="empty-state">
            <div class="empty-icon">🗓️</div>
            <p>Für diese Messe sind noch keine Zeitslots angelegt.</p>
        </div>
    <?php endif; ?>
</div>
