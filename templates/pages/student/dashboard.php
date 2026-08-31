<?php
/**
 * Schüler-Übersicht: Begrüßung + konfigurierbare Widgets (Ankündigungen,
 * Status-Kacheln, Hinweise, Tagesplan-Vorschau). Reihenfolge/Sichtbarkeit
 * pro Schule anpassbar (Darstellung → Schüler-Startseite).
 * Erwartet: $edition, $student, $timeline, $chosen, $assigned, $managedCount,
 *           $max, $open, $daysUntil, $announcements.
 */

$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$countdown = static function (?int $days): string {
    if ($days === null) {
        return 'Datum folgt';
    }
    if ($days > 1) {
        return 'in ' . $days . ' Tagen';
    }
    if ($days === 1) {
        return 'morgen';
    }
    if ($days === 0) {
        return 'heute';
    }

    return 'vorbei';
};

$widgets = page_blocks('uebersicht', [
    'ankuendigungen' => 'Ankündigungen',
    'statuskacheln' => 'Status-Kacheln',
    'hinweise' => 'Hinweise',
    'tagesplan' => 'Tagesplan-Vorschau',
]);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($edition['name']) ?></div>
        <h1 class="page-title">Hallo, <?= e($student['firstname'] !== '' ? $student['firstname'] : $student['username']) ?>! 👋</h1>
        <p class="page-sub">Hier siehst du auf einen Blick, wie es um deine Berufsmesse steht.</p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e($ctx->schoolUrl('/einschreibung')) ?>">📝 Einschreibung</a>
        <a class="btn" href="<?= e($ctx->schoolUrl('/drucken')) ?>">🖨️ Plan drucken</a>
    </div>
</div>

<?php foreach ($widgets as $blockKey => $blockLabel): ?>
    <?= block_open($blockKey, $blockLabel) ?>
    <?php if ($blockKey === 'ankuendigungen'): ?>
        <?php foreach ($announcements as $announcement): ?>
            <?php
            $type = (string) $announcement['type'];
            $class = in_array($type, ['info', 'success', 'warning', 'error'], true) ? $type : 'info';
            ?>
            <div class="alert alert-<?= e($class) ?>">
                <span aria-hidden="true">📣</span>
                <div>
                    <strong><?= e($announcement['title']) ?></strong>
                    <?php if (!empty($announcement['body'])): ?>
                        <div class="text-sm"><?= nl2br(e($announcement['body'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>

    <?php elseif ($blockKey === 'statuskacheln'): ?>
        <div class="stat-grid">
            <div class="stat-card stat-accent">
                <div class="stat-value"><?= e($countdown($daysUntil)) ?></div>
                <div class="stat-label">
                    Messetag
                    <?php if (!empty($edition['event_date'])): ?>
                        · <?= e(format_date($edition['event_date'])) ?>
                    <?php endif; ?>
                </div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?= e((string) $chosen) ?> / <?= e((string) $max) ?></div>
                <div class="stat-label">Aussteller gewählt</div>
            </div>
            <div class="stat-card <?= $managedCount > 0 && $assigned >= $managedCount ? 'stat-success' : '' ?>">
                <div class="stat-value"><?= e((string) $assigned) ?> / <?= e((string) $managedCount) ?></div>
                <div class="stat-label">Feste Slots zugeteilt</div>
            </div>
            <div class="stat-card <?= $open ? 'stat-success' : 'stat-danger' ?>">
                <div class="stat-value"><?= $open ? 'offen' : 'zu' ?></div>
                <div class="stat-label">
                    Einschreibung
                    <?php if ($open && !empty($edition['registration_end'])): ?>
                        · bis <?= e(format_datetime($edition['registration_end'])) ?>
                    <?php elseif (!$open && !empty($edition['registration_start'])): ?>
                        · ab <?= e(format_datetime($edition['registration_start'])) ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    <?php elseif ($blockKey === 'hinweise'): ?>
        <?php if ($chosen === 0): ?>
            <div class="alert alert-warning">
                <span aria-hidden="true">⚠️</span>
                <div>
                    Du hast dich noch für keinen Aussteller eingeschrieben.
                    <?php if ($open): ?>
                        <a href="<?= e($ctx->schoolUrl('/einschreibung')) ?>">Jetzt auswählen</a>.
                    <?php endif; ?>
                </div>
            </div>
        <?php elseif ($managedCount > 0 && $assigned < $managedCount): ?>
            <div class="alert alert-info">
                <span aria-hidden="true">⏳</span>
                <div>Deine Zeitslots werden noch zugeteilt. Schau später wieder in deinen Tagesplan.</div>
            </div>
        <?php endif; ?>

    <?php elseif ($blockKey === 'tagesplan'): ?>
        <div class="card mb-2">
            <div class="card-header">
                <h2>Dein Tag im Überblick</h2>
                <a class="btn btn-sm btn-ghost" href="<?= e($ctx->schoolUrl('/tagesplan')) ?>">Vollständiger Tagesplan</a>
            </div>
            <?php if ($timeline === []): ?>
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-icon" aria-hidden="true">🗓️</div>
                        <p>Der Ablauf der Messe steht noch nicht fest.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <tbody>
                        <?php foreach ($timeline as $slot): ?>
                            <tr>
                                <td class="mono nowrap" style="width:130px;">
                                    <?= e($time($slot['start_time'])) ?>–<?= e($time($slot['end_time'])) ?>
                                </td>
                                <td>
                                    <?php if ($slot['kind'] === 'break'): ?>
                                        <span class="badge badge-accent">☕ <?= e($slot['slot_name'] ?? 'Pause') ?></span>
                                    <?php elseif ($slot['kind'] === 'free'): ?>
                                        <span class="badge badge-info">👆 Freie Wahl</span>
                                        <span class="text-sm text-soft"><?= e($slot['slot_name'] ?? '') ?></span>
                                    <?php elseif ($slot['registration'] !== null): ?>
                                        <strong><?= e($slot['registration']['exhibitor_name']) ?></strong>
                                        <?php if (!empty($slot['registration']['room_number'])): ?>
                                            <span class="text-sm text-soft">· Raum <?= e($slot['registration']['room_number']) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-soft">Noch keine Zuteilung</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>
    <?= block_close() ?>
<?php endforeach; ?>
