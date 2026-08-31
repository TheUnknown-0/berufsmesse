<?php
/**
 * Anmeldungen verwalten: Schüler suchen, Anmeldungen anlegen/ändern/entfernen.
 * Erwartet: $edition, $query, $students, $student, $registrations, $exhibitors,
 *           $slots, $max, $canCreate, $canDelete, $searchLimit.
 */
$time = static fn (mixed $t): string => substr((string) $t, 0, 5);
$slotLabel = static function (array $slot) use ($time): string {
    $name = (string) ($slot['slot_name'] ?? '');
    $label = $name !== '' ? $name : 'Slot ' . (int) $slot['slot_number'];

    return $label . ' (' . $time($slot['start_time']) . '–' . $time($slot['end_time']) . ')';
};
$studentUrl = static fn (int $id): string => $ctx->schoolUrl('/admin/anmeldungen?student=' . $id);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($edition['name']) ?></div>
        <h1 class="page-title">Anmeldungen</h1>
        <p class="page-sub">Schüler:innen suchen, Anmeldungen prüfen und manuell anpassen.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($ctx->schoolUrl('/admin/dashboard')) ?>">📊 Dashboard</a>
    </div>
</div>

<?php $registrationBlocks = page_blocks('admin-anmeldungen', [
    'suche' => 'Suche',
    'schuelerliste' => 'Schüler:innen-Liste',
    'anmeldungen' => 'Anmeldungen der gewählten Person',
    'anmeldung-hinzufuegen' => 'Anmeldung hinzufügen',
    'warteliste' => 'Warteliste',
]); ?>
<?php foreach ($registrationBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'suche'): ?>
    <div class="card mb-2">
        <div class="card-body">
            <form method="get" action="<?= e($ctx->schoolUrl('/admin/anmeldungen')) ?>">
                <div class="cluster">
                    <div style="flex:1;min-width:240px;">
                        <label class="text-sm text-soft" for="q">Schüler:in suchen (Name, Klasse, Benutzername)</label>
                        <input class="input" type="search" id="q" name="q" value="<?= e($query) ?>" placeholder="z. B. Meier oder 10a">
                    </div>
                    <div style="align-self:flex-end;">
                        <button class="btn btn-primary" type="submit">Suchen</button>
                        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/anmeldungen')) ?>">Zurücksetzen</a>
                    </div>
                </div>
            </form>
        </div>
    </div>

<?php elseif ($blockKey === 'schuelerliste'): ?>
    <div class="card mb-2">
        <div class="card-header">
            <h2>Schüler:innen</h2>
            <span class="badge"><?= e((string) count($students)) ?> Treffer</span>
        </div>
        <?php if ($students === []): ?>
            <div class="card-body">
                <div class="empty-state">
                    <div class="empty-icon" aria-hidden="true">🔍</div>
                    <p>Keine Schüler:innen gefunden.</p>
                </div>
            </div>
        <?php else: ?>
            <div class="table-wrap">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Name</th>
                        <th>Klasse</th>
                        <th>Anmeldungen</th>
                        <th>zugeteilt</th>
                        <th></th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $row): ?>
                        <tr>
                            <td>
                                <strong><?= e($row['lastname']) ?>, <?= e($row['firstname']) ?></strong>
                                <div class="text-sm text-faint mono"><?= e($row['username']) ?></div>
                            </td>
                            <td><?= e($row['class'] ?? '—') ?></td>
                            <td><?= e((string) $row['anmeldungen']) ?></td>
                            <td><?= e((string) $row['zugeteilt']) ?></td>
                            <td>
                                <div class="row-actions">
                                    <a class="btn btn-sm" href="<?= e($studentUrl((int) $row['id'])) ?>">Anmeldungen</a>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($students) >= $searchLimit): ?>
                <div class="card-footer">
                    <span class="text-sm text-soft">Es werden höchstens <?= e((string) $searchLimit) ?> Treffer angezeigt — bitte Suche eingrenzen.</span>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

<?php elseif ($blockKey === 'anmeldungen'): ?>
    <?php if ($student !== null): ?>
        <div class="card mb-2">
            <div class="card-header">
                <h2><?= e($student['firstname']) ?> <?= e($student['lastname']) ?></h2>
                <span class="badge badge-primary">Klasse <?= e($student['class'] ?? '—') ?></span>
                <span class="badge"><?= e((string) count($registrations)) ?> / <?= e((string) $max) ?> Anmeldungen</span>
            </div>

            <?php if ($registrations === []): ?>
                <div class="card-body">
                    <div class="empty-state">
                        <div class="empty-icon" aria-hidden="true">📝</div>
                        <p>Für diese Person bestehen noch keine Anmeldungen.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Aussteller</th>
                            <th>Priorität</th>
                            <th>Art</th>
                            <th>Zeitslot</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($registrations as $row): ?>
                            <?php $registrationId = (int) $row['id']; ?>
                            <tr>
                                <td>
                                    <strong><?= e($row['exhibitor_name']) ?></strong>
                                    <?php if (!empty($row['room_number'])): ?>
                                        <div class="text-sm text-faint">Raum <?= e($row['room_number']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e($row['priority'] !== null ? (string) $row['priority'] : '—') ?></td>
                                <td>
                                    <?php if ($row['registration_type'] === 'automatic'): ?>
                                        <span class="badge badge-info">Automatisch</span>
                                    <?php elseif ($row['registration_type'] === 'qr_checkin'): ?>
                                        <span class="badge badge-accent">Check-in</span>
                                    <?php else: ?>
                                        <span class="badge badge-primary">Manuell</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($canCreate): ?>
                                        <form method="post" action="<?= e($ctx->schoolUrl('/admin/anmeldungen/slot')) ?>">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="registration_id" value="<?= e((string) $registrationId) ?>">
                                            <label class="text-sm text-soft" for="slot-<?= e((string) $registrationId) ?>">Zeitslot</label>
                                            <select id="slot-<?= e((string) $registrationId) ?>" name="timeslot_id">
                                                <option value="">— keine Zuteilung —</option>
                                                <?php foreach ($slots as $slot): ?>
                                                    <option value="<?= e((string) $slot['id']) ?>"
                                                        <?= $row['timeslot_id'] !== null && (int) $row['timeslot_id'] === (int) $slot['id'] ? 'selected' : '' ?>>
                                                        <?= e($slotLabel($slot)) ?><?= (int) $slot['is_managed'] === 1 ? '' : ' · freie Wahl' ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <label class="checkbox-row" style="margin-top:6px;">
                                                <input type="checkbox" name="ignore_capacity" value="1">
                                                <span class="text-sm">Kapazität ignorieren</span>
                                            </label>
                                            <button class="btn btn-sm" type="submit">Slot übernehmen</button>
                                        </form>
                                    <?php elseif ($row['timeslot_id'] === null): ?>
                                        <span class="badge badge-warning">offen</span>
                                    <?php else: ?>
                                        <strong><?= e($row['slot_name'] ?? ('Slot ' . (int) $row['slot_number'])) ?></strong>
                                        <div class="text-sm text-soft mono">
                                            <?= e($time($row['start_time'])) ?>–<?= e($time($row['end_time'])) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <?php if ($canDelete): ?>
                                            <form method="post" action="<?= e($ctx->schoolUrl('/admin/anmeldungen/entfernen')) ?>"
                                                  data-confirm="Anmeldung wirklich entfernen?">
                                                <?= $csrf->field() ?>
                                                <input type="hidden" name="registration_id" value="<?= e((string) $registrationId) ?>">
                                                <button class="btn btn-sm btn-danger-ghost" type="submit">Entfernen</button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    <?php endif; ?>

<?php elseif ($blockKey === 'anmeldung-hinzufuegen'): ?>
    <?php if ($student !== null && $canCreate): ?>
        <div class="card">
            <div class="card-header"><h3>Anmeldung hinzufügen</h3></div>
            <div class="card-body">
                <form method="post" action="<?= e($ctx->schoolUrl('/admin/anmeldungen/hinzufuegen')) ?>">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="student_id" value="<?= e((string) $student['id']) ?>">

                    <div class="form-grid">
                        <div class="field">
                            <label for="add-exhibitor">Aussteller</label>
                            <select id="add-exhibitor" name="exhibitor_id" required>
                                <option value="">— bitte wählen —</option>
                                <?php foreach ($exhibitors as $exhibitor): ?>
                                    <option value="<?= e((string) $exhibitor['id']) ?>">
                                        <?= e($exhibitor['name']) ?><?= (int) $exhibitor['active'] === 1 ? '' : ' (inaktiv)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="add-slot">Zeitslot (optional)</label>
                            <select id="add-slot" name="timeslot_id">
                                <option value="">— später automatisch zuteilen —</option>
                                <?php foreach ($slots as $slot): ?>
                                    <option value="<?= e((string) $slot['id']) ?>"><?= e($slotLabel($slot)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="field">
                            <label for="add-priority">Priorität (optional)</label>
                            <select id="add-priority" name="priority">
                                <option value="">— ohne —</option>
                                <?php for ($p = 1; $p <= $max; $p++): ?>
                                    <option value="<?= e((string) $p) ?>"><?= e((string) $p) ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                    </div>

                    <label class="checkbox-row">
                        <input type="checkbox" name="ignore_capacity" value="1">
                        <span>Kapazität ignorieren (Überbuchung bewusst zulassen)</span>
                    </label>

                    <button class="btn btn-primary" type="submit">Anmeldung anlegen</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
<?php elseif ($blockKey === 'warteliste'): ?>
    <div class="card">
        <div class="card-header">
            <h2 class="mt-0 mb-0">Warteliste</h2>
        </div>
        <div class="card-body">
            <?php if ($waitlist === []): ?>
                <p class="text-faint mb-0">
                    Niemand wartet — jeder Wunsch hat einen Platz bekommen.
                </p>
            <?php else: ?>
                <p class="text-sm text-soft">
                    Wünsche ohne Zuteilung. Wird bei einem dieser Aussteller ein Platz frei — durch
                    Abmeldung oder Entfernen einer Anmeldung —, rückt automatisch die Person mit der
                    höchsten Priorität und dem ältesten Wunsch nach und wird in der App benachrichtigt.
                </p>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Aussteller</th>
                            <th>wartend</th>
                            <th>davon Erstwünsche</th>
                            <th>ältester Wunsch</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($waitlist as $row): ?>
                            <tr>
                                <td>
                                    <a href="<?= e($ctx->schoolUrl('/admin/aussteller/' . (int) $row['id'])) ?>">
                                        <?= e($row['name']) ?>
                                    </a>
                                </td>
                                <td><strong><?= e((string) $row['wartend']) ?></strong></td>
                                <td><?= e((string) (int) $row['erstwuensche']) ?></td>
                                <td class="text-sm text-soft"><?= e(format_datetime($row['aeltester'])) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
