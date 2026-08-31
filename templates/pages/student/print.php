<?php
/**
 * Druckfreundlicher persönlicher Plan (nutzt die @media print-Regeln
 * des Design-Systems: Sidebar, Topbar und page-actions werden ausgeblendet).
 * Erwartet: $edition, $student, $timeline.
 */
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$fullName = trim(($student['firstname'] ?? '') . ' ' . ($student['lastname'] ?? ''));
if ($fullName === '') {
    $fullName = (string) $student['username'];
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($ctx->school['name'] ?? '') ?> · <?= e($edition['name']) ?></div>
        <h1 class="page-title">Mein Messeplan</h1>
        <p class="page-sub">
            <?= e($fullName) ?><?= !empty($student['class']) ? ' · Klasse ' . e($student['class']) : '' ?>
            <?php if (!empty($edition['event_date'])): ?>
                · <?= e(format_date($edition['event_date'])) ?>
            <?php endif; ?>
        </p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" type="button" data-print>🖨️ Jetzt drucken</button>
        <a class="btn" href="<?= e($ctx->schoolUrl('/tagesplan')) ?>">Zurück zum Tagesplan</a>
    </div>
</div>

<p class="text-sm text-soft no-print">
    Tipp: Über den Druckdialog kannst du den Plan auch als PDF speichern.
</p>

<?php if ($timeline === []): ?>
    <div class="card card-pad">
        <div class="empty-state">
            <div class="empty-icon" aria-hidden="true">🗓️</div>
            <p>Für diese Messe sind noch keine Zeitslots eingerichtet.</p>
        </div>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th style="width:130px;">Zeit</th>
                <th>Ablauf</th>
                <th style="width:180px;">Raum</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($timeline as $slot): ?>
                <?php $registration = $slot['registration']; ?>
                <tr>
                    <td class="mono nowrap">
                        <?= e($time($slot['start_time'])) ?>–<?= e($time($slot['end_time'])) ?>
                    </td>
                    <td>
                        <?php if ($slot['kind'] === 'break'): ?>
                            ☕ <?= e($slot['slot_name'] ?? 'Pause') ?>
                        <?php elseif ($slot['kind'] === 'free'): ?>
                            👆 <?= e($slot['slot_name'] ?? ('Slot ' . (int) $slot['slot_number'])) ?> — freie Wahl vor Ort
                            <?php if ($registration !== null): ?>
                                <div class="text-sm text-soft">
                                    Eingecheckt bei <?= e($registration['exhibitor_name']) ?>
                                </div>
                            <?php endif; ?>
                        <?php elseif ($registration !== null): ?>
                            <strong><?= e($registration['exhibitor_name']) ?></strong>
                            <div class="text-sm text-soft">
                                <?= e($slot['slot_name'] ?? ('Slot ' . (int) $slot['slot_number'])) ?>
                            </div>
                        <?php else: ?>
                            <span class="text-soft">Noch keine Zuteilung</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($registration !== null && !empty($registration['room_number'])): ?>
                            <strong><?= e($registration['room_number']) ?></strong>
                            <?php if (!empty($registration['room_name'])): ?>
                                <div class="text-sm text-soft"><?= e($registration['room_name']) ?></div>
                            <?php endif; ?>
                            <?php if (!empty($registration['building'])): ?>
                                <div class="text-sm text-faint"><?= e($registration['building']) ?></div>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="text-faint">—</span>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<p class="text-sm text-soft mt-2">
    Bitte sei pünktlich bei deinen Terminen und bringe diesen Plan mit.
</p>
