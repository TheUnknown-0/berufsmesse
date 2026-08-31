<?php
/**
 * Anwesenheitsliste mit Filtern und manueller Pflege.
 * Erwartet: $rows, $slots, $exhibitors, $classes, $filter*, $presentCount, $canEdit
 */
$methodLabels = ['self_scan' => 'Selbst-Scan', 'teacher_scan' => 'Lehrer-Scan', 'manual' => 'Manuell'];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Messetag</div>
        <h1 class="page-title">Anwesenheit</h1>
        <p class="page-sub">Alle Zuteilungen mit Check-in-Status — manuelle Korrekturen sind jederzeit möglich.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/anwesenheit-live')) ?>">📡 Live-Monitor</a>
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/anwesenheit-bericht')) ?>">📈 Bericht</a>
    </div>
</div>

<?php foreach (page_blocks('admin-anwesenheit', [
    'kennzahlen' => 'Kennzahlen',
    'filter' => 'Filter',
    'liste' => 'Anwesenheitsliste',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) count($rows)) ?></div>
        <div class="stat-label">Zuteilungen in der Auswahl</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-value"><?= e((string) $presentCount) ?></div>
        <div class="stat-label">davon eingecheckt</div>
    </div>
    <div class="stat-card stat-danger">
        <div class="stat-value"><?= e((string) (count($rows) - $presentCount)) ?></div>
        <div class="stat-label">noch offen</div>
    </div>
</div>

<?php elseif ($blockKey === 'filter'): ?>
<div class="card card-pad mb-2">
    <form method="get" class="form-grid">
        <div class="field">
            <label for="f-slot">Zeitslot</label>
            <select id="f-slot" name="slot">
                <option value="0">Alle Slots</option>
                <?php foreach ($slots as $slot): ?>
                    <option value="<?= e((string) (int) $slot['id']) ?>" <?= $filterSlot === (int) $slot['id'] ? 'selected' : '' ?>>
                        <?= e($slot['slot_name'] ?? ('Slot ' . $slot['slot_number'])) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-aussteller">Aussteller</label>
            <select id="f-aussteller" name="aussteller">
                <option value="0">Alle Aussteller</option>
                <?php foreach ($exhibitors as $exhibitor): ?>
                    <option value="<?= e((string) (int) $exhibitor['id']) ?>" <?= $filterExhibitor === (int) $exhibitor['id'] ? 'selected' : '' ?>>
                        <?= e($exhibitor['name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-klasse">Klasse</label>
            <select id="f-klasse" name="klasse">
                <option value="">Alle Klassen</option>
                <?php foreach ($classes as $class): ?>
                    <option value="<?= e($class) ?>" <?= $filterClass === $class ? 'selected' : '' ?>><?= e($class) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label for="f-status">Status</label>
            <select id="f-status" name="status">
                <option value="">Alle</option>
                <option value="anwesend" <?= $filterStatus === 'anwesend' ? 'selected' : '' ?>>Nur anwesend</option>
                <option value="fehlend" <?= $filterStatus === 'fehlend' ? 'selected' : '' ?>>Nur fehlend</option>
            </select>
        </div>
        <div class="field" style="align-self:end;">
            <button class="btn btn-primary" type="submit">Filtern</button>
        </div>
    </form>
</div>

<?php elseif ($blockKey === 'liste'): ?>
<?php if ($rows === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🔍</div>
        <p>Keine Zuteilungen für diese Auswahl.</p>
    </div>
<?php else: ?>
    <div class="table-wrap" id="attendance-table" data-endpoint="<?= e($ctx->schoolUrl('/api/anwesenheit/setzen')) ?>">
        <table class="data-table">
            <thead>
            <tr>
                <th>Schüler:in</th>
                <th>Klasse</th>
                <th>Zeitslot</th>
                <th>Aussteller</th>
                <th>Raum</th>
                <th>Status</th>
                <?php if ($canEdit): ?><th></th><?php endif; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($rows as $row): ?>
                <?php $present = $row['attendance_id'] !== null; ?>
                <tr data-user="<?= e((string) (int) $row['user_id']) ?>"
                    data-exhibitor="<?= e((string) (int) $row['exhibitor_id']) ?>"
                    data-slot="<?= e((string) (int) $row['timeslot_id']) ?>">
                    <td><?= e(trim((string) $row['lastname'] . ', ' . (string) $row['firstname'])) ?></td>
                    <td><?= e($row['class'] ?? '—') ?></td>
                    <td class="nowrap">
                        <?= e($row['slot_name'] ?? ('Slot ' . $row['slot_number'])) ?>
                        <div class="text-sm text-faint"><?= e(substr((string) $row['start_time'], 0, 5)) ?></div>
                    </td>
                    <td><?= e($row['exhibitor_name']) ?></td>
                    <td><?= e(trim((string) ($row['room_number'] ?? '') . ' ' . (string) ($row['room_name'] ?? ''))) ?: '—' ?></td>
                    <td class="js-status">
                        <?php if ($present): ?>
                            <span class="badge badge-success">✓ <?= e(format_date($row['checked_in_at'], 'H:i')) ?></span>
                            <span class="badge"><?= e($methodLabels[(string) $row['checkin_method']] ?? (string) $row['checkin_method']) ?></span>
                            <?php if ((int) $row['wrong_room'] === 1): ?>
                                <span class="badge badge-warning">falscher Raum</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span class="badge badge-danger">fehlt</span>
                        <?php endif; ?>
                    </td>
                    <?php if ($canEdit): ?>
                        <td>
                            <div class="row-actions">
                                <button class="btn btn-sm js-mark" type="button"
                                        data-action="<?= $present ? 'abwesend' : 'anwesend' ?>">
                                    <?= $present ? 'Zurücksetzen' : 'Anwesend' ?>
                                </button>
                            </div>
                        </td>
                    <?php endif; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="text-sm text-faint mt-2">Es werden höchstens 1000 Zeilen angezeigt — bei Bedarf Filter nutzen.</p>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
