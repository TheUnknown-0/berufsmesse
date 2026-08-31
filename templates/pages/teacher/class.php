<?php
/**
 * Schülerliste einer Klasse inkl. Status und Zuteilungsmatrix.
 * Erwartet: $edition, $class, $students, $managedSlots, $matrix, $hasAttendance.
 */
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$slotLabel = static fn (array $slot): string => (string) ($slot['slot_name'] ?? '') !== ''
    ? (string) $slot['slot_name']
    : 'Slot ' . (int) $slot['slot_number'];
$managedCount = count($managedSlots);

$complete = 0;
$without = 0;
foreach ($students as $student) {
    if ((int) $student['anmeldungen'] === 0) {
        $without++;
    }
    if ($managedCount > 0 && (int) $student['zugeteilt'] >= $managedCount) {
        $complete++;
    }
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($edition['name']) ?></div>
        <h1 class="page-title">Klasse <?= e($class) ?></h1>
        <p class="page-sub"><?= e((string) count($students)) ?> Schüler:innen</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($ctx->schoolUrl('/klassen')) ?>">← Alle Klassen</a>
    </div>
</div>

<?php $classBlocks = page_blocks('klassen-detail', [
    'kennzahlen' => 'Kennzahlen',
    'schuelerliste' => 'Schüler:innen-Liste',
]); ?>
<?php foreach ($classBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
    <div class="stat-grid">
        <div class="stat-card stat-success">
            <div class="stat-value"><?= e((string) $complete) ?></div>
            <div class="stat-label">vollständig zugeteilt</div>
        </div>
        <div class="stat-card stat-accent">
            <div class="stat-value"><?= e((string) (count($students) - $complete)) ?></div>
            <div class="stat-label">noch unvollständig</div>
        </div>
        <div class="stat-card stat-danger">
            <div class="stat-value"><?= e((string) $without) ?></div>
            <div class="stat-label">ohne jede Anmeldung</div>
        </div>
    </div>

<?php elseif ($blockKey === 'schuelerliste'): ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr>
                <th>Name</th>
                <th>Status</th>
                <?php foreach ($managedSlots as $slot): ?>
                    <th>
                        <?= e($slotLabel($slot)) ?><br>
                        <span class="mono text-faint"><?= e($time($slot['start_time'])) ?>–<?= e($time($slot['end_time'])) ?></span>
                    </th>
                <?php endforeach; ?>
                <?php if ($hasAttendance): ?>
                    <th>Anwesend</th>
                <?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($students as $student): ?>
                <?php
                $studentId = (int) $student['id'];
                $assigned = (int) $student['zugeteilt'];
                $registrations = (int) $student['anmeldungen'];
                ?>
                <tr>
                    <td>
                        <strong><?= e($student['lastname']) ?>, <?= e($student['firstname']) ?></strong>
                        <div class="text-sm text-faint mono"><?= e($student['username']) ?></div>
                    </td>
                    <td>
                        <?php if ($registrations === 0): ?>
                            <span class="badge badge-danger">keine Anmeldung</span>
                        <?php elseif ($managedCount > 0 && $assigned >= $managedCount): ?>
                            <span class="badge badge-success">vollständig</span>
                        <?php else: ?>
                            <span class="badge badge-warning">
                                <?= e((string) $assigned) ?> / <?= e((string) $managedCount) ?> zugeteilt
                            </span>
                        <?php endif; ?>
                        <div class="text-sm text-soft"><?= e((string) $registrations) ?> gewählt</div>
                    </td>
                    <?php foreach ($managedSlots as $slot): ?>
                        <?php $cell = $matrix[$studentId][(int) $slot['id']] ?? null; ?>
                        <td>
                            <?php if ($cell === null): ?>
                                <span class="text-faint">—</span>
                            <?php else: ?>
                                <?= e($cell['exhibitor_name']) ?>
                                <div class="text-sm text-faint">
                                    <?php if (!empty($cell['room_number'])): ?>
                                        Raum <?= e($cell['room_number']) ?> ·
                                    <?php endif; ?>
                                    <?= $cell['registration_type'] === 'automatic' ? 'Auto' : 'Manuell' ?>
                                </div>
                            <?php endif; ?>
                        </td>
                    <?php endforeach; ?>
                    <?php if ($hasAttendance): ?>
                        <td>
                            <?php if ((int) $student['anwesend'] > 0): ?>
                                <span class="badge badge-success"><?= e((string) $student['anwesend']) ?>× eingecheckt</span>
                            <?php else: ?>
                                <span class="badge">noch nicht</span>
                            <?php endif; ?>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
