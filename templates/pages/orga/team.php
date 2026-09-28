<?php
/**
 * Team-Überblick: Ziele (von der Leitung abhakbar), Countdown, Mitglieder,
 * nächste Organisationspunkte.
 * Erwartet: $access, $team, $space, $stats, $members, $goals, $upcoming, $deadline, $canLead, $unread.
 */
use App\Services\OrgaAccess;
use App\Services\OrgaService;

$s = static fn (string $path): string => $ctx->schoolUrl($path);
$teamId = (int) $team['id'];
$back = $s('/orga/teams/' . $teamId);
$goalPercent = $stats['goals'] > 0 ? (int) round($stats['goals_done'] / $stats['goals'] * 100) : 0;

$actions = $canLead
    ? '<button class="btn btn-primary" type="button" data-open-modal="goal-new">＋ Ziel</button>'
    : '';
if ($access->manages()) {
    $actions .= '<a class="btn" href="' . e($s('/orga/verwaltung/teams/' . $teamId)) . '">⚙️ Team verwalten</a>';
}
?>
<?= $view->renderPartial('pages/orga/_nav', [
    'space' => $space,
    'active' => 'team',
    'unread' => $unread,
    'sub' => (string) ($team['description'] ?? ''),
    'actions' => $actions,
]) ?>

<?php foreach (page_blocks('orga-team', [
    'ziele' => 'Ziele',
    'countdown' => 'Countdown',
    'naechste' => 'Nächste Organisationspunkte',
    'mitglieder' => 'Mitglieder',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'ziele'): ?>
<div class="card mb-2">
    <div class="card-header">
        <h3>🎯 Ziele</h3>
        <span class="text-sm text-soft"><?= e((string) $stats['goals_done']) ?> von <?= e((string) $stats['goals']) ?> erreicht</span>
    </div>
    <div class="card-body">
        <div class="progress mb-2"><span style="width: <?= e((string) $goalPercent) ?>%; background: var(--success);"></span></div>
        <?php if ($goals === []): ?>
            <div class="empty-state" style="padding:20px;">
                <p class="mb-0">Noch keine Ziele gesetzt.<?= $canLead ? ' Lege über „＋ Ziel“ fest, was das Team erreichen soll.' : '' ?></p>
            </div>
        <?php else: ?>
            <ul class="goal-list">
                <?php foreach ($goals as $goal): ?>
                    <?php
                    $done = (int) $goal['is_done'] === 1;
                    $overdue = !$done && OrgaService::isOverdue($goal['due_date']);
                    $taskTotal = (int) $goal['task_total'];
                    ?>
                    <li class="goal-item<?= $done ? ' is-done' : '' ?>">
                        <?php if ($canLead): ?>
                            <form method="post" action="<?= e($s('/orga/ziele/' . (int) $goal['id'] . '/abhaken')) ?>">
                                <?= $csrf->field() ?>
                                <input type="hidden" name="_back" value="<?= e($back) ?>">
                                <button class="goal-check" type="submit" title="<?= $done ? 'Wieder öffnen' : 'Abhaken' ?>"
                                        aria-label="<?= e(($done ? 'Wieder öffnen: ' : 'Abhaken: ') . $goal['title']) ?>">✓</button>
                            </form>
                        <?php else: ?>
                            <button class="goal-check" type="button" disabled aria-label="<?= $done ? 'Erreicht' : 'Offen' ?>">✓</button>
                        <?php endif; ?>
                        <div class="goal-main">
                            <div class="goal-title"><?= e($goal['title']) ?></div>
                            <?php if (!empty($goal['description'])): ?>
                                <div class="text-sm text-soft note-body"><?= e($goal['description']) ?></div>
                            <?php endif; ?>
                            <div class="cluster text-sm text-faint" style="gap:12px; margin-top:4px;">
                                <?php if ($goal['due_date']): ?>
                                    <span class="<?= $overdue ? 'text-danger' : '' ?>">📅 bis <?= e(format_date($goal['due_date'])) ?></span>
                                <?php endif; ?>
                                <?php if ($taskTotal > 0): ?>
                                    <a href="<?= e($s('/orga/teams/' . $teamId . '/aufgaben?ansicht=liste&ziel=' . (int) $goal['id'])) ?>">
                                        🗂️ <?= e((string) (int) $goal['task_done']) ?>/<?= e((string) $taskTotal) ?> Punkte erledigt
                                    </a>
                                <?php endif; ?>
                                <?php if ($done && $goal['done_at']): ?>
                                    <span>✓ <?= e(format_date($goal['done_at'])) ?>
                                        <?php if ($goal['done_username'] !== null): ?>
                                            von <?= e(OrgaAccess::displayName(['firstname' => $goal['done_firstname'], 'lastname' => $goal['done_lastname'], 'username' => $goal['done_username']])) ?>
                                        <?php endif; ?>
                                    </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($canLead): ?>
                                <details class="mt-2">
                                    <summary class="text-sm text-soft" style="cursor:pointer;">Bearbeiten</summary>
                                    <form method="post" action="<?= e($s('/orga/ziele/' . (int) $goal['id'])) ?>" class="mt-2">
                                        <?= $csrf->field() ?>
                                        <input type="hidden" name="_back" value="<?= e($back) ?>">
                                        <div class="form-grid">
                                            <div class="field">
                                                <label>Ziel</label>
                                                <input class="input" name="title" required maxlength="200" value="<?= e($goal['title']) ?>">
                                            </div>
                                            <div class="field">
                                                <label>Zieldatum</label>
                                                <input class="input" type="date" name="due_date" value="<?= e((string) $goal['due_date']) ?>">
                                            </div>
                                        </div>
                                        <div class="field">
                                            <label>Beschreibung</label>
                                            <textarea class="input" name="description" rows="2" maxlength="2000"><?= e((string) $goal['description']) ?></textarea>
                                        </div>
                                        <button class="btn btn-sm btn-primary" type="submit">Speichern</button>
                                    </form>
                                    <form method="post" action="<?= e($s('/orga/ziele/' . (int) $goal['id'] . '/loeschen')) ?>" class="mt-2"
                                          data-confirm="Ziel wirklich löschen? Verknüpfte Organisationspunkte bleiben erhalten.">
                                        <?= $csrf->field() ?>
                                        <input type="hidden" name="_back" value="<?= e($back) ?>">
                                        <button class="btn btn-sm btn-danger-ghost" type="submit">Ziel löschen</button>
                                    </form>
                                </details>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($blockKey === 'countdown'): ?>
<div class="card card-pad mb-2">
    <?= $view->renderPartial('pages/orga/_countdown', ['deadline' => $deadline, 'canSet' => $access->manages()]) ?>
</div>

<?php elseif ($blockKey === 'naechste'): ?>
<div class="card mb-2">
    <div class="card-header">
        <h3>🗂️ Nächste Organisationspunkte</h3>
        <span class="text-sm text-soft"><?= e((string) $stats['done']) ?> / <?= e((string) $stats['tasks']) ?> erledigt</span>
        <a class="btn btn-sm btn-ghost" href="<?= e($s('/orga/teams/' . $teamId . '/aufgaben')) ?>">Zum Board</a>
    </div>
    <?php if ($upcoming === []): ?>
        <div class="empty-state" style="padding:24px;"><p class="mb-0">Keine offenen Punkte.</p></div>
    <?php else: ?>
        <div class="table-wrap" style="border:0; border-radius:0;">
            <table class="data-table">
                <tbody>
                <?php foreach ($upcoming as $task): ?>
                    <?php $overdue = OrgaService::isOverdue($task['due_date'], (string) $task['status']); ?>
                    <tr>
                        <td><a href="<?= e($s('/orga/aufgaben/' . (int) $task['id'])) ?>"><strong><?= e($task['title']) ?></strong></a></td>
                        <td><span class="badge <?= e(OrgaService::STATUS_BADGES[$task['status']]) ?>"><?= e(OrgaService::STATUS[$task['status']]) ?></span></td>
                        <td class="text-sm"><?= $task['username'] !== null ? '👤 ' . e(OrgaAccess::displayName($task)) : '<span class="text-faint">nicht zugewiesen</span>' ?></td>
                        <td class="text-sm<?= $overdue ? ' text-danger' : '' ?>"><?= $task['due_date'] ? '📅 ' . e(format_date($task['due_date'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php elseif ($blockKey === 'mitglieder'): ?>
<div class="card mb-2">
    <div class="card-header"><h3>👥 Mitglieder</h3><span class="text-sm text-soft"><?= e((string) count($members)) ?></span></div>
    <div class="card-body chip-row">
        <?php if ($members === []): ?>
            <span class="text-faint">Noch keine Mitglieder zugeordnet.</span>
        <?php endif; ?>
        <?php foreach ($members as $member): ?>
            <span class="badge<?= (int) $member['is_leader'] === 1 ? ' badge-accent' : '' ?>">
                <?= (int) $member['is_leader'] === 1 ? '⭐ ' : '' ?><?= e(OrgaAccess::displayName($member)) ?>
                <span class="text-faint">@<?= e($member['username']) ?></span>
            </span>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php if ($canLead): ?>
<dialog class="modal" id="goal-new">
    <form method="post" action="<?= e($s('/orga/teams/' . $teamId . '/ziele')) ?>">
        <?= $csrf->field() ?>
        <input type="hidden" name="_back" value="<?= e($back) ?>">
        <div class="modal-header"><h3>Neues Ziel</h3><button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button></div>
        <div class="modal-body">
            <div class="field">
                <label for="goal-title">Ziel</label>
                <input class="input" id="goal-title" name="title" required maxlength="200" placeholder="z. B. 40 Aussteller verbindlich zugesagt">
            </div>
            <div class="field">
                <label for="goal-desc">Beschreibung</label>
                <textarea class="input" id="goal-desc" name="description" rows="3" maxlength="2000"></textarea>
            </div>
            <div class="field">
                <label for="goal-due">Zieldatum</label>
                <input class="input" type="date" id="goal-due" name="due_date">
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Ziel anlegen</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
