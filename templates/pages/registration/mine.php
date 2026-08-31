<?php
/**
 * Eigene Anmeldungen mit Priorität, Zuteilung und Abmelde-Möglichkeit.
 * Erwartet: $edition, $registrations, $max, $open.
 */
$prioBadge = static function (?int $p): string {
    if ($p === null) {
        return '<span class="badge">ohne Priorität</span>';
    }

    return match ($p) {
        1 => '<span class="badge badge-danger">Prio 1 · Hoch</span>',
        2 => '<span class="badge badge-warning">Prio 2 · Mittel</span>',
        3 => '<span class="badge">Prio 3 · Niedrig</span>',
        default => '<span class="badge">Prio ' . e((string) $p) . '</span>',
    };
};
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($edition['name']) ?></div>
        <h1 class="page-title">Meine Anmeldungen</h1>
        <p class="page-sub">
            <?= e((string) count($registrations)) ?> von <?= e((string) $max) ?> möglichen Anmeldungen.
        </p>
    </div>
    <div class="page-actions">
        <a class="btn btn-primary" href="<?= e($ctx->schoolUrl('/einschreibung')) ?>">📝 Auswahl ändern</a>
        <a class="btn" href="<?= e($ctx->schoolUrl('/tagesplan')) ?>">🗓️ Tagesplan</a>
    </div>
</div>

<?php $mineBlocks = page_blocks('meine-anmeldungen', [
    'hinweis-zeitraum' => 'Hinweis zum Einschreibezeitraum',
    'anmeldungen' => 'Anmeldungsliste',
    'gut-zu-wissen' => 'Gut zu wissen',
]); ?>
<?php foreach ($mineBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweis-zeitraum'): ?>
    <?php if (!$open): ?>
        <div class="alert alert-info">
            <span aria-hidden="true">🔒</span>
            <div>Der Einschreibezeitraum ist beendet. Bereits zugeteilte Anmeldungen kannst du nicht mehr selbst entfernen.</div>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'anmeldungen'): ?>
    <?php if ($registrations === []): ?>
        <div class="card card-pad">
            <div class="empty-state">
                <div class="empty-icon" aria-hidden="true">⭐</div>
                <p>Du hast dich noch für keinen Aussteller eingeschrieben.</p>
                <a class="btn btn-primary" href="<?= e($ctx->schoolUrl('/einschreibung')) ?>">Jetzt einschreiben</a>
            </div>
        </div>
    <?php else: ?>
        <div class="table-wrap">
            <table class="data-table">
                <thead>
                <tr>
                    <th>Aussteller</th>
                    <th>Priorität</th>
                    <th>Zeitslot</th>
                    <th>Raum</th>
                    <th>Art</th>
                    <th></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($registrations as $row): ?>
                    <?php $canCancel = $open || $row['timeslot_id'] === null; ?>
                    <tr>
                        <td>
                            <strong><?= e($row['exhibitor_name']) ?></strong>
                            <?php if (!empty($row['short_description'])): ?>
                                <div class="text-sm text-soft"><?= e($row['short_description']) ?></div>
                            <?php endif; ?>
                        </td>
                        <td><?= $prioBadge($row['priority'] !== null ? (int) $row['priority'] : null) ?></td>
                        <td>
                            <?php if ($row['timeslot_id'] === null): ?>
                                <span class="badge badge-warning">Noch keine Zuteilung</span>
                            <?php else: ?>
                                <strong><?= e($row['slot_name'] ?? ('Slot ' . (int) $row['slot_number'])) ?></strong>
                                <div class="text-sm text-soft mono">
                                    <?= e($time($row['start_time'])) ?>–<?= e($time($row['end_time'])) ?> Uhr
                                </div>
                            <?php endif; ?>
                        </td>
                        <td class="text-sm text-soft">
                            <?php if (!empty($row['room_number'])): ?>
                                <?= e($row['room_number']) ?>
                                <?php if (!empty($row['room_name'])): ?>
                                    <div class="text-faint"><?= e($row['room_name']) ?></div>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="text-faint">—</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($row['registration_type'] === 'automatic'): ?>
                                <span class="badge badge-info">Automatisch</span>
                            <?php elseif ($row['registration_type'] === 'qr_checkin'): ?>
                                <span class="badge badge-accent">Check-in</span>
                            <?php else: ?>
                                <span class="badge badge-primary">Eigene Wahl</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="row-actions">
                                <?php if ($canCancel): ?>
                                    <form method="post" action="<?= e($ctx->schoolUrl('/meine-anmeldungen/abmelden')) ?>"
                                          data-confirm="Möchtest du dich wirklich abmelden?">
                                        <?= $csrf->field() ?>
                                        <input type="hidden" name="registration_id" value="<?= e((string) $row['id']) ?>">
                                        <button class="btn btn-sm btn-danger-ghost" type="submit">Abmelden</button>
                                    </form>
                                <?php else: ?>
                                    <span class="text-sm text-faint">fest zugeteilt</span>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'gut-zu-wissen'): ?>
    <div class="card mt-2">
        <div class="card-header"><h3>Gut zu wissen</h3></div>
        <div class="card-body text-sm">
            <ul style="margin:0;padding-left:20px;">
                <li>Solange der Einschreibezeitraum läuft, kannst du dich jederzeit wieder abmelden.</li>
                <li>Ist noch kein Zeitslot zugeteilt, geht das Abmelden auch danach noch.</li>
                <li>Deinen fertigen Ablauf findest du im <a href="<?= e($ctx->schoolUrl('/tagesplan')) ?>">Tagesplan</a>.</li>
                <li>Bitte sei pünktlich bei deinen Terminen.</li>
            </ul>
        </div>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
