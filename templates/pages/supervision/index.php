<?php
/**
 * Aufsichtsplan-Matrix: Raum × zugeteilte Slots + Spalte „Ganztags“.
 * Erwartet: $rooms, $slots, $teachers, $assignments, $unsupervised, $canEdit
 */
$formAction = $ctx->schoolUrl('/admin/aufsicht');

/** Rendert das Auswahl-Formular einer Zelle. */
$assignForm = static function (int $roomId, string $slotValue) use ($teachers, $csrf, $formAction): string {
    $options = '<option value="">+ Lehrkraft …</option>';
    foreach ($teachers as $teacher) {
        $options .= '<option value="' . e((string) (int) $teacher['id']) . '">'
            . e(trim((string) $teacher['lastname'] . ', ' . (string) $teacher['firstname']))
            . '</option>';
    }

    return '<form method="post" action="' . e($formAction) . '" class="cluster" style="gap:4px;margin-top:6px;">'
        . $csrf->field()
        . '<input type="hidden" name="action" value="zuweisen">'
        . '<input type="hidden" name="room_id" value="' . e((string) $roomId) . '">'
        . '<input type="hidden" name="timeslot_id" value="' . e($slotValue) . '">'
        . '<select name="teacher_id" required style="min-width:130px;">' . $options . '</select>'
        . '<button class="btn btn-sm btn-primary" type="submit">+</button>'
        . '</form>';
};

/** Rendert die vorhandenen Zuweisungen einer Zelle. */
$assignedChips = static function (array $entries) use ($canEdit, $csrf, $formAction): string {
    $html = '<div class="chip-row">';
    foreach ($entries as $entry) {
        $name = trim((string) $entry['firstname'] . ' ' . (string) $entry['lastname']);
        $html .= '<span class="badge badge-primary">' . e($name);
        if ($canEdit) {
            $html .= '<form method="post" action="' . e($formAction) . '" style="display:inline;"'
                . ' data-confirm="Aufsicht von ' . e($name) . ' entfernen?">'
                . $csrf->field()
                . '<input type="hidden" name="action" value="entfernen">'
                . '<input type="hidden" name="assignment_id" value="' . e((string) (int) $entry['id']) . '">'
                . '<button class="btn btn-sm btn-ghost" type="submit" title="Entfernen"'
                . ' style="padding:0 4px;">×</button></form>';
        }
        $html .= '</span>';
    }

    return $html . '</div>';
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Messetag</div>
        <h1 class="page-title">Aufsichtsplan</h1>
        <p class="page-sub">
            Lehrkräfte je Raum und Zeitslot — Grundlage für den Lehrer-Check-in.
            „Ganztags“ gilt für alle Slots, auch für die frei wählbaren.
        </p>
    </div>
</div>

<?php foreach (page_blocks('admin-aufsicht', [
    'hinweise' => 'Hinweise',
    'matrix' => 'Aufsichtsplan-Matrix',
    'ohne-aufsicht' => 'Räume ohne Aufsicht',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweise'): ?>
<?php if ($rooms === [] || $slots === []): ?>
    <div class="alert alert-warning">
        <span>⚠️</span>
        <div>Für diese Messe sind noch keine Räume oder zugeteilten Zeitslots angelegt.</div>
    </div>
<?php elseif ($teachers === [] && $canEdit): ?>
    <div class="alert alert-info">
        <span>ℹ️</span>
        <div>Es sind noch keine Benutzer mit der Rolle „Lehrer“ angelegt.</div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'matrix'): ?>
<?php if ($rooms !== [] && $slots !== []): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Raum</th>
                <th>Ganztags</th>
                <?php foreach ($slots as $slot): ?>
                    <th>
                        <?= e($slot['slot_name'] ?? ('Slot ' . $slot['slot_number'])) ?>
                        <div class="text-faint" style="font-weight:400;text-transform:none;letter-spacing:0;">
                            <?= e(substr((string) $slot['start_time'], 0, 5)) ?>–<?= e(substr((string) $slot['end_time'], 0, 5)) ?>
                        </div>
                    </th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rooms as $room): ?>
                <?php $roomId = (int) $room['id']; ?>
                <tr>
                    <td>
                        <strong><?= e(trim((string) $room['room_number'] . ' ' . (string) ($room['room_name'] ?? ''))) ?></strong>
                        <?php if (!empty($room['exhibitor_names'])): ?>
                            <div class="text-sm text-faint"><?= e($room['exhibitor_names']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php $entries = $assignments[$roomId][0] ?? []; ?>
                        <?php if ($entries !== []): ?>
                            <?= $assignedChips($entries) ?>
                        <?php else: ?>
                            <span class="text-faint text-sm">—</span>
                        <?php endif; ?>
                        <?php if ($canEdit && $teachers !== []): ?>
                            <?= $assignForm($roomId, 'ganztags') ?>
                        <?php endif; ?>
                    </td>
                    <?php foreach ($slots as $slot): ?>
                        <?php $slotId = (int) $slot['id']; ?>
                        <td>
                            <?php $entries = $assignments[$roomId][$slotId] ?? []; ?>
                            <?php if ($entries !== []): ?>
                                <?= $assignedChips($entries) ?>
                            <?php elseif (!empty($assignments[$roomId][0])): ?>
                                <span class="text-faint text-sm">über Ganztags betreut</span>
                            <?php else: ?>
                                <span class="text-faint text-sm">keine Aufsicht</span>
                            <?php endif; ?>
                            <?php if ($canEdit && $teachers !== []): ?>
                                <?= $assignForm($roomId, (string) $slotId) ?>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'ohne-aufsicht'): ?>
<?php if ($rooms !== [] && $slots !== []): ?>
    <div class="card card-pad mt-2">
        <h3>⚠️ Räume ohne Aufsicht</h3>
        <div class="grid-3">
            <?php foreach ($slots as $slot): ?>
                <?php $slotId = (int) $slot['id']; ?>
                <div>
                    <div class="text-sm"><strong><?= e($slot['slot_name'] ?? ('Slot ' . $slot['slot_number'])) ?></strong></div>
                    <?php if (empty($unsupervised[$slotId])): ?>
                        <div class="text-sm text-success">Alle Räume betreut</div>
                    <?php else: ?>
                        <div class="text-sm text-soft"><?= e(implode(', ', $unsupervised[$slotId])) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
