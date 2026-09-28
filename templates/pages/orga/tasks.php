<?php
/**
 * Organisationspunkte eines Teams als Board (Ziehen = Status ändern) oder Liste.
 * Erwartet: $access, $team, $space, $tasks, $mode, $filters, $members, $goals, $exhibitors, $canLead, $unread.
 */
use App\Services\OrgaAccess;
use App\Services\OrgaService;

$s = static fn (string $path): string => $ctx->schoolUrl($path);
$teamId = (int) $team['id'];
$base = $s('/orga/teams/' . $teamId . '/aufgaben');
$query = static fn (array $extra): string => $base . '?' . http_build_query(array_filter(
    array_merge($filters, $extra),
    static fn (mixed $v): bool => $v !== '' && $v !== 0 && $v !== null,
));
$canWork = static fn (array $task): bool => $canLead
    || ($access->isMember($teamId) && $task['assignee_id'] !== null && (int) $task['assignee_id'] === $access->userId());

$byStatus = array_fill_keys(array_keys(OrgaService::STATUS), []);
foreach ($tasks as $task) {
    $byStatus[$task['status']][] = $task;
}

$card = static function (array $task) use ($s, $canWork): string {
    $overdue = OrgaService::isOverdue($task['due_date'], (string) $task['status']);
    $draggable = $canWork($task);
    ob_start(); ?>
    <a class="board-card<?= $overdue ? ' is-overdue' : '' ?>" href="<?= e($s('/orga/aufgaben/' . (int) $task['id'])) ?>"
       data-task="<?= e((string) $task['id']) ?>" <?= $draggable ? 'draggable="true"' : '' ?>>
        <div class="board-card-title"><?= e($task['title']) ?></div>
        <div class="board-card-meta">
            <?php if ($task['priority'] !== 'normal'): ?>
                <span class="badge <?= e(OrgaService::PRIORITY_BADGES[$task['priority']]) ?>"><?= e(OrgaService::PRIORITY[$task['priority']]) ?></span>
            <?php endif; ?>
            <?php if ($task['username'] !== null): ?><span>👤 <?= e(OrgaAccess::displayName($task)) ?></span><?php endif; ?>
            <?php if ($task['due_date']): ?><span class="<?= $overdue ? 'text-danger' : '' ?>">📅 <?= e(format_date($task['due_date'])) ?></span><?php endif; ?>
            <?php if ((int) $task['check_total'] > 0): ?><span>☑️ <?= e((string) $task['check_done']) ?>/<?= e((string) $task['check_total']) ?></span><?php endif; ?>
            <?php if ((int) $task['comment_count'] > 0): ?><span>💬 <?= e((string) $task['comment_count']) ?></span><?php endif; ?>
            <?php if ($task['exhibitor_name'] !== null): ?><span>🏢 <?= e($task['exhibitor_name']) ?></span><?php endif; ?>
            <?php if ($task['goal_title'] !== null): ?><span>🎯 <?= e($task['goal_title']) ?></span><?php endif; ?>
        </div>
    </a>
    <?php
    return (string) ob_get_clean();
};

$actions = '<a class="btn' . ($mode === 'board' ? ' btn-primary' : '') . '" href="' . e($query(['ansicht' => 'board', 'status' => ''])) . '">▦ Board</a>'
    . '<a class="btn' . ($mode === 'liste' ? ' btn-primary' : '') . '" href="' . e($query(['ansicht' => 'liste'])) . '">☰ Liste</a>';
if ($canLead) {
    $actions .= '<button class="btn btn-accent" type="button" data-open-modal="task-new">＋ Organisationspunkt</button>';
}
?>
<?= $view->renderPartial('pages/orga/_nav', [
    'space' => $space,
    'active' => 'aufgaben',
    'unread' => $unread,
    'actions' => $actions,
]) ?>

<?php foreach (page_blocks('orga-aufgaben', [
    'filter' => 'Filter',
    'punkte' => 'Organisationspunkte',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'filter'): ?>
<form method="get" action="<?= e($base) ?>" class="card card-pad mb-2" data-live="orga-tasks">
    <input type="hidden" name="ansicht" value="<?= e($mode) ?>">
    <div class="form-grid">
        <div class="field mb-0">
            <label for="f-q">Suche</label>
            <input class="input" type="search" id="f-q" name="q" value="<?= e($filters['q']) ?>" placeholder="Titel oder Beschreibung">
        </div>
        <div class="field mb-0">
            <label for="f-person">Zuständig</label>
            <select class="input" id="f-person" name="person">
                <option value="">alle</option>
                <option value="ich" <?= $filters['person'] === 'ich' ? 'selected' : '' ?>>nur meine</option>
                <option value="niemand" <?= $filters['person'] === 'niemand' ? 'selected' : '' ?>>nicht zugewiesen</option>
                <?php foreach ($members as $member): ?>
                    <option value="<?= e((string) $member['id']) ?>" <?= $filters['person'] === (string) $member['id'] ? 'selected' : '' ?>><?= e(OrgaAccess::displayName($member)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field mb-0">
            <label for="f-ziel">Ziel</label>
            <select class="input" id="f-ziel" name="ziel">
                <option value="">alle</option>
                <?php foreach ($goals as $goal): ?>
                    <option value="<?= e((string) $goal['id']) ?>" <?= $filters['ziel'] === (int) $goal['id'] ? 'selected' : '' ?>><?= e($goal['title']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field mb-0">
            <label for="f-aussteller">Aussteller</label>
            <select class="input" id="f-aussteller" name="aussteller">
                <option value="">alle</option>
                <?php foreach ($exhibitors as $exhibitor): ?>
                    <option value="<?= e((string) $exhibitor['id']) ?>" <?= $filters['aussteller'] === (int) $exhibitor['id'] ? 'selected' : '' ?>><?= e($exhibitor['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <?php if ($mode === 'liste'): ?>
            <div class="field mb-0">
                <label for="f-status">Status</label>
                <select class="input" id="f-status" name="status">
                    <option value="">alle</option>
                    <?php foreach (OrgaService::STATUS as $key => $label): ?>
                        <option value="<?= e($key) ?>" <?= $filters['status'] === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>
    </div>
    <noscript><button class="btn btn-sm mt-2" type="submit">Filtern</button></noscript>
</form>

<?php elseif ($blockKey === 'punkte'): ?>
<div data-live-target="orga-tasks">
<?php if ($mode === 'board'): ?>
    <p class="text-sm text-soft js-only">Karten lassen sich zwischen den Spalten ziehen<?= $canLead ? '' : ' (bei Punkten, die dir zugewiesen sind)' ?>.</p>
    <div class="board" data-board data-status-url="<?= e($s('/api/orga/aufgaben/{id}/status')) ?>">
        <?php foreach (OrgaService::STATUS as $status => $label): ?>
            <div class="board-col" data-status="<?= e($status) ?>">
                <div class="board-col-head">
                    <span class="badge <?= e(OrgaService::STATUS_BADGES[$status]) ?>"><?= e($label) ?></span>
                    <span class="text-faint" data-col-count><?= e((string) count($byStatus[$status])) ?></span>
                </div>
                <?php foreach ($byStatus[$status] as $task): ?>
                    <?= $card($task) ?>
                <?php endforeach; ?>
            </div>
        <?php endforeach; ?>
    </div>
<?php elseif ($tasks === []): ?>
    <div class="empty-state card">
        <div class="empty-icon">🗂️</div>
        <p class="mb-0">Keine Organisationspunkte gefunden.</p>
    </div>
<?php else: ?>
    <div class="table-wrap">
        <table class="data-table">
            <thead>
            <tr><th>Titel</th><th>Status</th><th>Priorität</th><th>Zuständig</th><th>Frist</th><th>Ziel / Aussteller</th></tr>
            </thead>
            <tbody>
            <?php foreach ($tasks as $task): ?>
                <?php $overdue = OrgaService::isOverdue($task['due_date'], (string) $task['status']); ?>
                <tr>
                    <td><a href="<?= e($s('/orga/aufgaben/' . (int) $task['id'])) ?>"><strong><?= e($task['title']) ?></strong></a>
                        <?php if ((int) $task['check_total'] > 0): ?><div class="text-sm text-faint">☑️ <?= e((string) $task['check_done']) ?>/<?= e((string) $task['check_total']) ?></div><?php endif; ?>
                    </td>
                    <td><span class="badge <?= e(OrgaService::STATUS_BADGES[$task['status']]) ?>"><?= e(OrgaService::STATUS[$task['status']]) ?></span></td>
                    <td><span class="badge <?= e(OrgaService::PRIORITY_BADGES[$task['priority']]) ?>"><?= e(OrgaService::PRIORITY[$task['priority']]) ?></span></td>
                    <td class="text-sm"><?= $task['username'] !== null ? e(OrgaAccess::displayName($task)) : '<span class="text-faint">niemand</span>' ?></td>
                    <td class="text-sm<?= $overdue ? ' text-danger' : '' ?>"><?= e(format_date($task['due_date'])) ?></td>
                    <td class="text-sm">
                        <?php if ($task['goal_title'] !== null): ?><div>🎯 <?= e($task['goal_title']) ?></div><?php endif; ?>
                        <?php if ($task['exhibitor_name'] !== null): ?><div>🏢 <?= e($task['exhibitor_name']) ?></div><?php endif; ?>
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

<?php if ($canLead): ?>
<dialog class="modal" id="task-new">
    <form method="post" action="<?= e($s('/orga/teams/' . $teamId . '/aufgaben')) ?>">
        <?= $csrf->field() ?>
        <input type="hidden" name="_back" value="<?= e($_SERVER['REQUEST_URI'] ?? $base) ?>">
        <div class="modal-header"><h3>Neuer Organisationspunkt</h3><button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button></div>
        <div class="modal-body">
            <?= $view->renderPartial('pages/orga/_task_form', [
                'task' => null,
                'members' => $members,
                'goals' => array_values(array_filter($goals, static fn (array $g): bool => (int) $g['is_done'] === 0)),
                'exhibitors' => $exhibitors,
                'prefix' => 'new',
            ]) ?>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Anlegen</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
