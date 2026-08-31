<?php
/**
 * Leitstand — Gesamtsicht auf den Messetag, aktualisiert sich selbst.
 * Erwartet: $edition, $slots, $state.
 *
 * Der serverseitig gerenderte Zustand ist der Startwert; ops-board.js
 * ersetzt anschließend die mit data-ops-* markierten Stellen.
 */

$totals = $state['totals'];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Messetag</div>
        <h1 class="page-title">Leitstand</h1>
        <p class="page-sub">
            <?= e((string) $edition['name']) ?>
            <?php if (!empty($edition['event_date'])): ?>
                · <?= e(format_date($edition['event_date'])) ?>
            <?php endif; ?>
            · Stand <span data-ops-time><?= e($state['time']) ?></span> Uhr
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/anwesenheit-live')) ?>">Slot-Detail</a>
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/ausfall')) ?>">⚠️ Ausfall melden</a>
        <label class="checkbox-row">
            <input type="checkbox" data-ops-autorefresh checked>
            <span class="text-sm">automatisch aktualisieren</span>
        </label>
    </div>
</div>

<?php foreach (page_blocks('admin-leitstand', [
    'aktueller-slot' => 'Laufender Slot',
    'kennzahlen' => 'Kennzahlen',
    'stille-aussteller' => 'Aussteller ohne Check-in',
    'raeume' => 'Räume',
    'auffaelligkeiten' => 'Auffälligkeiten',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'aktueller-slot'): ?>
    <div class="card card-pad" data-ops-slot>
        <?php if ($state['slot'] === null): ?>
            <div class="empty-state">
                <div class="empty-icon">🕒</div>
                <p>Gerade läuft kein Zeitslot.</p>
            </div>
        <?php else: ?>
            <div class="cluster">
                <h2 class="mt-0 mb-0" style="flex:1;">
                    <?= e($state['slot']['label']) ?>
                    <span class="text-soft"><?= e($state['slot']['start']) ?>–<?= e($state['slot']['end']) ?></span>
                </h2>
                <?php if ($state['slot']['is_break']): ?>
                    <span class="badge badge-info">Pause</span>
                <?php endif; ?>
                <span class="badge"><?= e((string) $state['slot']['progress']) ?> % vorbei</span>
            </div>
            <div class="progress"><span style="width:<?= e((string) $state['slot']['progress']) ?>%;"></span></div>
        <?php endif; ?>
    </div>

<?php elseif ($blockKey === 'kennzahlen'): ?>
    <div class="stat-grid" data-ops-totals>
        <div class="stat-card stat-success">
            <div class="stat-value"><?= e((string) $totals['slot_present']) ?></div>
            <div class="stat-label">jetzt eingecheckt</div>
        </div>
        <div class="stat-card<?= $totals['slot_missing'] > 0 ? ' stat-danger' : '' ?>">
            <div class="stat-value"><?= e((string) $totals['slot_missing']) ?></div>
            <div class="stat-label">noch nicht da</div>
        </div>
        <div class="stat-card stat-accent">
            <div class="stat-value"><?= e((string) $totals['slot_quote']) ?> %</div>
            <div class="stat-label">Quote im Slot</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $totals['day_quote']) ?> %</div>
            <div class="stat-label">Quote am Tag</div>
        </div>
    </div>

<?php elseif ($blockKey === 'stille-aussteller'): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="mt-0 mb-0">Kein Check-in im laufenden Slot</h2>
        </div>
        <div class="card-body" data-ops-silent>
            <?php if ($state['silent_exhibitors'] === []): ?>
                <p class="text-faint mb-0">Bei allen Ausstellern ist jemand angekommen.</p>
            <?php else: ?>
                <p class="text-sm text-soft">
                    Hier wird jemand erwartet, aber es hat noch niemand eingecheckt — lohnt einen Anruf oder
                    den Weg zum Raum.
                </p>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead><tr><th>Aussteller</th><th>Raum</th><th>erwartet</th></tr></thead>
                        <tbody>
                        <?php foreach ($state['silent_exhibitors'] as $row): ?>
                            <tr>
                                <td><strong><?= e($row['name']) ?></strong></td>
                                <td><?= e((string) ($row['room_number'] ?? '—')) ?></td>
                                <td><?= e((string) $row['erwartet']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($blockKey === 'raeume'): ?>
    <div class="card">
        <div class="card-header"><h2 class="mt-0 mb-0">Auslastung der Räume</h2></div>
        <div class="card-body" data-ops-rooms>
            <?php if ($state['rooms'] === []): ?>
                <p class="text-faint mb-0">Keine Daten für den laufenden Slot.</p>
            <?php else: ?>
                <div class="stack">
                    <?php foreach ($state['rooms'] as $room): ?>
                        <div>
                            <div class="cluster">
                                <span style="flex:1;min-width:140px;"><?= e($room['label']) ?></span>
                                <span class="text-sm text-soft">
                                    <?= e((string) $room['present']) ?> / <?= e((string) $room['capacity']) ?>
                                    <?php if ($room['over']): ?>
                                        <span class="badge badge-danger">über Kapazität</span>
                                    <?php endif; ?>
                                </span>
                            </div>
                            <div class="progress"><span style="width:<?= e((string) min(100, $room['load'])) ?>%;"></span></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($blockKey === 'auffaelligkeiten'): ?>
    <div class="grid-2">
        <div class="card">
            <div class="card-header"><h2 class="mt-0 mb-0">Falscher Raum</h2></div>
            <div class="card-body" data-ops-wrong>
                <?php if ($state['wrong_room'] === []): ?>
                    <p class="text-faint mb-0">Keine Meldungen.</p>
                <?php else: ?>
                    <div class="stack">
                        <?php foreach ($state['wrong_room'] as $row): ?>
                            <div class="text-sm">
                                <span class="text-faint"><?= e(format_datetime($row['checked_in_at'])) ?></span>
                                <strong><?= e(trim((string) $row['firstname'] . ' ' . (string) $row['lastname'])) ?></strong>
                                <?php if (!empty($row['class'])): ?>(<?= e((string) $row['class']) ?>)<?php endif; ?>
                                bei <?= e((string) $row['exhibitor_name']) ?>
                                <?php if (!empty($row['actual_room'])): ?>
                                    · gescannt in <?= e((string) $row['actual_room']) ?>
                                <?php endif; ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card">
            <div class="card-header"><h2 class="mt-0 mb-0">Schwache Slots</h2></div>
            <div class="card-body" data-ops-weak>
                <?php if ($state['weak_slots'] === []): ?>
                    <p class="text-faint mb-0">Alle Slots liegen über 60 % Anwesenheit.</p>
                <?php else: ?>
                    <div class="table-wrap">
                        <table class="data-table">
                            <thead><tr><th>Slot</th><th>Zeit</th><th>anwesend</th><th>Quote</th></tr></thead>
                            <tbody>
                            <?php foreach ($state['weak_slots'] as $row): ?>
                                <tr>
                                    <td><?= e($row['label']) ?></td>
                                    <td class="text-sm text-soft"><?= e($row['time']) ?></td>
                                    <td><?= e((string) $row['present']) ?> / <?= e((string) $row['expected']) ?></td>
                                    <td><span class="badge badge-warning"><?= e((string) $row['quote']) ?> %</span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
