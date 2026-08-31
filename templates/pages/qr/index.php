<?php
/**
 * QR-Code-Matrix: Aussteller × Zeitslots (ohne Pausen) mit Token-Status.
 * Erwartet: $slots, $exhibitors, $tokens, $tokenCount, $expectedCount,
 *           $canCreate, $canSeeStudents, $qrBase, $schoolSlug, $edition
 */
$imgBase = $ctx->schoolUrl('/api/qr/bild');
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Check-in</div>
        <h1 class="page-title">QR-Codes</h1>
        <p class="page-sub">
            Ein Code je Aussteller und Zeitslot. Schüler:innen scannen ihn am Stand und sind damit anwesend.
        </p>
    </div>
    <div class="page-actions">
        <?php if ($canSeeStudents): ?>
            <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/qr-codes/schueler')) ?>">🎫 Schüler-QR-Karten</a>
        <?php endif; ?>
        <?php if ($canCreate): ?>
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/qr-codes/generieren')) ?>">
                <?= $csrf->field() ?>
                <input type="hidden" name="scope" value="all">
                <button class="btn btn-primary" type="submit">✨ Fehlende Codes erzeugen</button>
            </form>
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/qr-codes/generieren')) ?>"
                  data-confirm="Alle QR-Codes neu erzeugen? Bereits gedruckte Codes werden dadurch ungültig.">
                <?= $csrf->field() ?>
                <input type="hidden" name="scope" value="all">
                <input type="hidden" name="force" value="1">
                <button class="btn btn-danger-ghost" type="submit">♻️ Alle neu erzeugen</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php foreach (page_blocks('admin-qr-codes', [
    'kennzahlen' => 'Kennzahlen',
    'hinweise' => 'Hinweise',
    'matrix' => 'QR-Code-Matrix',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) count($exhibitors)) ?></div>
        <div class="stat-label">Aktive Aussteller</div>
    </div>
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) count($slots)) ?></div>
        <div class="stat-label">Zeitslots ohne Pausen</div>
    </div>
    <div class="stat-card <?= $tokenCount >= $expectedCount && $expectedCount > 0 ? 'stat-success' : 'stat-danger' ?>">
        <div class="stat-value"><?= e($tokenCount . ' / ' . $expectedCount) ?></div>
        <div class="stat-label">Erzeugte QR-Codes</div>
    </div>
</div>

<?php elseif ($blockKey === 'hinweise'): ?>
<?php if ($slots === [] || $exhibitors === []): ?>
    <div class="alert alert-warning">
        <span>⚠️</span>
        <div>Für diese Messe fehlen noch Zeitslots oder aktive Aussteller. Lege diese zuerst an.</div>
    </div>
<?php else: ?>
    <div class="alert alert-info">
        <span>ℹ️</span>
        <div>
            Im QR-Code steckt die Adresse
            <span class="mono"><?= e(rtrim($qrBase, '/') . '/' . $schoolSlug . '/checkin?token=…') ?></span>.
            Die Basis lässt sich in den Einstellungen über <span class="mono">qr_code_url</span> festlegen.
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'matrix'): ?>
<?php if ($slots !== [] && $exhibitors !== []): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Aussteller</th>
                <?php foreach ($slots as $slot): ?>
                    <th>
                        <?= e($slot['slot_name'] ?? ('Slot ' . $slot['slot_number'])) ?>
                        <div class="text-faint" style="font-weight:400;text-transform:none;letter-spacing:0;">
                            <?= e(substr((string) $slot['start_time'], 0, 5)) ?>–<?= e(substr((string) $slot['end_time'], 0, 5)) ?>
                            <?= (int) $slot['is_managed'] === 0 ? ' · frei' : '' ?>
                        </div>
                    </th>
                <?php endforeach; ?>
                <th></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($exhibitors as $exhibitor): ?>
                <?php $exhibitorId = (int) $exhibitor['id']; ?>
                <tr>
                    <td>
                        <strong><?= e($exhibitor['name']) ?></strong>
                        <?php if (!empty($exhibitor['room_number'])): ?>
                            <div class="text-sm text-faint">
                                Raum <?= e(trim((string) $exhibitor['room_number'] . ' ' . (string) ($exhibitor['room_name'] ?? ''))) ?>
                            </div>
                        <?php else: ?>
                            <div class="text-sm text-faint">Kein Raum zugeordnet</div>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($slots as $slot): ?>
                        <?php
                        $slotId = (int) $slot['id'];
                        $cell = $tokens[$exhibitorId][$slotId] ?? null;
                        $token = $cell['token'] ?? null;
                        ?>
                        <td>
                            <?php if ($token !== null): ?>
                                <?php $url = (string) $cell['url']; ?>
                                <div class="qr-frame">
                                    <img src="<?= e($imgBase . '?scale=3&data=' . urlencode($url)) ?>"
                                         width="102" height="102" alt="QR-Code <?= e($exhibitor['name']) ?>">
                                </div>
                                <div class="mono text-sm"><?= e($token) ?></div>
                                <?php $expires = $cell['expires_at'] ?? null; ?>
                                <?php if ($expires !== null): ?>
                                    <div class="text-faint text-sm">bis <?= e(format_datetime($expires)) ?></div>
                                <?php endif; ?>
                            <?php elseif ($canCreate): ?>
                                <form method="post" action="<?= e($ctx->schoolUrl('/admin/qr-codes/generieren')) ?>">
                                    <?= $csrf->field() ?>
                                    <input type="hidden" name="scope" value="single">
                                    <input type="hidden" name="exhibitor_id" value="<?= e((string) $exhibitorId) ?>">
                                    <input type="hidden" name="timeslot_id" value="<?= e((string) $slotId) ?>">
                                    <button class="btn btn-sm" type="submit">Erzeugen</button>
                                </form>
                            <?php else: ?>
                                <span class="badge">offen</span>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <td>
                        <div class="row-actions">
                            <a class="btn btn-sm btn-ghost"
                               href="<?= e($ctx->schoolUrl('/admin/qr-codes/druck/' . $exhibitorId)) ?>">🖨️ Druckbogen</a>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
