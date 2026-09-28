<?php
/**
 * Orga-Verwaltung: Abschlusstermin, Teams anlegen, Übersicht der Zuordnungen.
 * Erwartet: $access, $teams, $stats, $settings, $candidates, $colors.
 */
$s = static fn (string $path): string => $ctx->schoolUrl($path);
$deadlineValue = !empty($settings['deadline']) ? format_date($settings['deadline'], 'Y-m-d\TH:i') : '';
?>
<div class="page-header">
    <div class="page-title-group">
        <a class="page-eyebrow" href="<?= e($s('/orga')) ?>" style="text-decoration:none;">← Orga-Tool</a>
        <h1 class="page-title">Orga-Verwaltung</h1>
        <p class="page-sub">Teams für <?= e($ctx->edition['name'] ?? 'diesen Jahrgang') ?> anlegen, Mitglieder und Leitungen zuordnen, Abschlusstermin setzen.</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" type="button" data-open-modal="team-new">＋ Team anlegen</button>
    </div>
</div>

<?php foreach (page_blocks('orga-verwaltung', [
    'termin' => 'Abschlusstermin',
    'teams' => 'Teams',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'termin'): ?>
<div class="card mb-2">
    <div class="card-header"><h3>⏳ Abschlusstermin</h3></div>
    <form method="post" action="<?= e($s('/orga/verwaltung/termin')) ?>">
        <?= $csrf->field() ?>
        <div class="card-body">
            <p class="text-sm text-soft">Bis zu diesem Zeitpunkt soll die Orga-Arbeit abgeschlossen sein. Alle sehen dazu einen Countdown. Teams können einen eigenen Termin bekommen.</p>
            <div class="form-grid">
                <div class="field">
                    <label for="deadline">Termin</label>
                    <input class="input" type="datetime-local" id="deadline" name="deadline" value="<?= e($deadlineValue) ?>">
                    <div class="hint">Leer lassen, um den Countdown auszublenden.</div>
                </div>
                <div class="field">
                    <label for="deadline_label">Bezeichnung</label>
                    <input class="input" id="deadline_label" name="deadline_label" maxlength="150"
                           value="<?= e((string) ($settings['deadline_label'] ?? '')) ?>" placeholder="z. B. Orga-Schluss vor der Messe">
                </div>
            </div>
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Termin speichern</button></div>
    </form>
</div>

<?php elseif ($blockKey === 'teams'): ?>
<?php if ($candidates === []): ?>
    <div class="alert alert-warning">Es gibt noch keine Konten mit der Rolle „Orga“. Lege sie unter Benutzer an, damit du sie Teams zuordnen kannst.</div>
<?php endif; ?>
<?php if ($teams === []): ?>
    <div class="empty-state card">
        <div class="empty-icon">👥</div>
        <p class="mb-0">Noch keine Teams. Lege das erste über „＋ Team anlegen“ an.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead><tr><th>Team</th><th>Leitung</th><th>Mitglieder</th><th>Ziele</th><th>Punkte</th><th>Eigener Termin</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($teams as $team): ?>
                <?php $st = $stats[(int) $team['id']]; ?>
                <tr>
                    <td><span class="team-dot" style="background: <?= e($team['color']) ?>;"></span><a href="<?= e($s('/orga/teams/' . (int) $team['id'])) ?>"><strong><?= e($team['name']) ?></strong></a></td>
                    <td class="text-sm"><?= $st['leaders'] !== [] ? e(implode(', ', $st['leaders'])) : '<span class="badge badge-warning">keine</span>' ?></td>
                    <td><?= e((string) $st['members']) ?></td>
                    <td><?= e((string) $st['goals_done']) ?> / <?= e((string) $st['goals']) ?></td>
                    <td><?= e((string) $st['done']) ?> / <?= e((string) $st['tasks']) ?><?= $st['overdue'] > 0 ? ' <span class="badge badge-danger">' . e((string) $st['overdue']) . ' überfällig</span>' : '' ?></td>
                    <td class="text-sm"><?= $team['deadline'] ? e(format_datetime($team['deadline'])) : '<span class="text-faint">–</span>' ?></td>
                    <td><div class="row-actions"><a class="btn btn-sm" href="<?= e($s('/orga/verwaltung/teams/' . (int) $team['id'])) ?>">Bearbeiten</a></div></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<dialog class="modal" id="team-new">
    <form method="post" action="<?= e($s('/orga/verwaltung/teams')) ?>">
        <?= $csrf->field() ?>
        <div class="modal-header"><h3>Neues Team</h3><button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button></div>
        <div class="modal-body">
            <?= $view->renderPartial('pages/orga/_team_fields', ['team' => null, 'colors' => $colors]) ?>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Anlegen</button>
        </div>
    </form>
</dialog>
