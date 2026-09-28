<?php
/**
 * Organisationspunkt im Detail: Beschreibung, Checkliste, Kommentare, Status.
 * Erwartet: $access, $team, $space, $task, $checklist, $comments, $members, $goals, $exhibitors,
 *           $canLead, $canWork, $canComment, $unread.
 */
use App\Services\OrgaAccess;
use App\Services\OrgaService;

$s = static fn (string $path): string => $ctx->schoolUrl($path);
$taskId = (int) $task['id'];
$back = $s('/orga/aufgaben/' . $taskId);
$overdue = OrgaService::isOverdue($task['due_date'], (string) $task['status']);
$checkDone = count(array_filter($checklist, static fn (array $c): bool => (int) $c['is_done'] === 1));

$actions = '<a class="btn" href="' . e($s('/orga/teams/' . (int) $team['id'] . '/aufgaben')) . '">← Zum Board</a>';
if ($canLead) {
    $actions .= '<button class="btn btn-primary" type="button" data-open-modal="task-edit">✏️ Bearbeiten</button>';
}
?>
<?= $view->renderPartial('pages/orga/_nav', [
    'space' => $space,
    'active' => 'aufgaben',
    'unread' => $unread,
    'actions' => $actions,
]) ?>

<h2 style="margin-top:0;"><?= e($task['title']) ?></h2>

<?php foreach (page_blocks('orga-aufgabe', [
    'details' => 'Details & Status',
    'beschreibung' => 'Beschreibung & Checkliste',
    'kommentare' => 'Kommentare',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'details'): ?>
<div class="card mb-2">
    <div class="card-body">
        <div class="cluster" style="gap:18px;">
            <div><div class="text-sm text-faint">Status</div>
                <span class="badge <?= e(OrgaService::STATUS_BADGES[$task['status']]) ?>"><?= e(OrgaService::STATUS[$task['status']]) ?></span></div>
            <div><div class="text-sm text-faint">Priorität</div>
                <span class="badge <?= e(OrgaService::PRIORITY_BADGES[$task['priority']]) ?>"><?= e(OrgaService::PRIORITY[$task['priority']]) ?></span></div>
            <div><div class="text-sm text-faint">Zuständig</div>
                <?= $task['username'] !== null ? '👤 ' . e(OrgaAccess::displayName($task)) : '<span class="text-faint">niemand</span>' ?></div>
            <div><div class="text-sm text-faint">Frist</div>
                <span class="<?= $overdue ? 'text-danger' : '' ?>"><?= $task['due_date'] ? e(format_date($task['due_date'])) . ($overdue ? ' (überfällig)' : '') : '–' ?></span></div>
            <?php if ($task['goal_title'] !== null): ?>
                <div><div class="text-sm text-faint">Ziel</div>🎯 <?= e($task['goal_title']) ?></div>
            <?php endif; ?>
            <?php if ($task['exhibitor_name'] !== null): ?>
                <div><div class="text-sm text-faint">Aussteller</div>🏢 <?= e($task['exhibitor_name']) ?></div>
            <?php endif; ?>
        </div>
        <?php if ($canWork): ?>
            <hr class="divider">
            <div class="text-sm text-faint mb-0" style="margin-bottom:6px;">Status setzen</div>
            <div class="cluster">
                <?php foreach (OrgaService::STATUS as $status => $label): ?>
                    <form method="post" action="<?= e($s('/orga/aufgaben/' . $taskId . '/status')) ?>">
                        <?= $csrf->field() ?>
                        <input type="hidden" name="status" value="<?= e($status) ?>">
                        <input type="hidden" name="_back" value="<?= e($back) ?>">
                        <button class="btn btn-sm<?= $task['status'] === $status ? ' btn-primary' : '' ?>" type="submit" <?= $task['status'] === $status ? 'disabled' : '' ?>><?= e($label) ?></button>
                    </form>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <p class="text-sm text-faint mt-2 mb-0">
            Angelegt <?= e(format_datetime($task['created_at'])) ?>
            <?php if ($task['creator_username'] !== null): ?>
                von <?= e(OrgaAccess::displayName(['firstname' => $task['creator_firstname'], 'lastname' => $task['creator_lastname'], 'username' => $task['creator_username']])) ?>
            <?php endif; ?>
            <?php if ($task['completed_at']): ?> · erledigt <?= e(format_datetime($task['completed_at'])) ?><?php endif; ?>
        </p>
    </div>
</div>

<?php elseif ($blockKey === 'beschreibung'): ?>
<div class="grid-2 mb-2">
    <div class="card">
        <div class="card-header"><h3>📝 Beschreibung</h3></div>
        <div class="card-body">
            <?php if (!empty($task['description'])): ?>
                <div class="note-body"><?= e($task['description']) ?></div>
            <?php else: ?>
                <span class="text-faint">Keine Beschreibung.</span>
            <?php endif; ?>
        </div>
    </div>
    <div class="card">
        <div class="card-header"><h3>☑️ Checkliste</h3><span class="text-sm text-soft"><?= e((string) $checkDone) ?> / <?= e((string) count($checklist)) ?></span></div>
        <div class="card-body">
            <?php if ($checklist === []): ?>
                <p class="text-faint">Noch keine Einträge.</p>
            <?php else: ?>
                <ul class="goal-list">
                    <?php foreach ($checklist as $item): ?>
                        <?php $done = (int) $item['is_done'] === 1; ?>
                        <li class="goal-item<?= $done ? ' is-done' : '' ?>" style="padding:8px 0;">
                            <?php if ($canWork): ?>
                                <form method="post" action="<?= e($s('/orga/checkliste/' . (int) $item['id'] . '/umschalten')) ?>">
                                    <?= $csrf->field() ?>
                                    <input type="hidden" name="_back" value="<?= e($back) ?>">
                                    <button class="goal-check" type="submit" aria-label="<?= e(($done ? 'Wieder öffnen: ' : 'Abhaken: ') . $item['label']) ?>">✓</button>
                                </form>
                            <?php else: ?>
                                <button class="goal-check" type="button" disabled aria-label="<?= $done ? 'Erledigt' : 'Offen' ?>">✓</button>
                            <?php endif; ?>
                            <div class="goal-main"><span class="goal-title" style="font-weight:400;"><?= e($item['label']) ?></span></div>
                            <?php if ($canLead): ?>
                                <form method="post" action="<?= e($s('/orga/checkliste/' . (int) $item['id'] . '/loeschen')) ?>" data-confirm="Eintrag löschen?">
                                    <?= $csrf->field() ?>
                                    <input type="hidden" name="_back" value="<?= e($back) ?>">
                                    <button class="btn btn-sm btn-ghost" type="submit" aria-label="Eintrag löschen">✕</button>
                                </form>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            <?php endif; ?>
            <?php if ($canLead): ?>
                <form method="post" action="<?= e($s('/orga/aufgaben/' . $taskId . '/checkliste')) ?>" class="cluster mt-2" style="flex-wrap:nowrap;">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="_back" value="<?= e($back) ?>">
                    <input class="input" name="label" maxlength="300" placeholder="Neuer Eintrag" required aria-label="Neuer Checklisten-Eintrag">
                    <button class="btn btn-sm" type="submit">＋</button>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php elseif ($blockKey === 'kommentare'): ?>
<div class="card mb-2" id="kommentare">
    <div class="card-header"><h3>💬 Kommentare</h3><span class="text-sm text-soft"><?= e((string) count($comments)) ?></span></div>
    <div class="card-body stack">
        <?php if ($comments === []): ?>
            <p class="text-faint mb-0">Noch keine Kommentare.</p>
        <?php endif; ?>
        <?php foreach ($comments as $comment): ?>
            <?php $name = $comment['username'] !== null ? OrgaAccess::displayName($comment) : 'Gelöschtes Konto'; ?>
            <div class="chat-msg" style="padding:0;">
                <span class="avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($name, 0, 1))) ?></span>
                <div class="chat-main">
                    <div class="chat-head"><span class="chat-author"><?= e($name) ?></span><span class="chat-time"><?= e(format_datetime($comment['created_at'])) ?></span></div>
                    <div class="chat-body"><?= e($comment['body']) ?></div>
                </div>
            </div>
        <?php endforeach; ?>
        <?php if ($canComment): ?>
            <form method="post" action="<?= e($s('/orga/aufgaben/' . $taskId . '/kommentare')) ?>">
                <?= $csrf->field() ?>
                <input type="hidden" name="_back" value="<?= e($back . '#kommentare') ?>">
                <div class="field">
                    <label for="comment-body">Kommentar schreiben</label>
                    <textarea class="input" id="comment-body" name="body" rows="3" maxlength="4000" required></textarea>
                </div>
                <button class="btn btn-primary" type="submit">Kommentieren</button>
            </form>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php if ($canLead): ?>
<dialog class="modal" id="task-edit">
    <form method="post" action="<?= e($s('/orga/aufgaben/' . $taskId)) ?>">
        <?= $csrf->field() ?>
        <input type="hidden" name="_back" value="<?= e($back) ?>">
        <div class="modal-header"><h3>Organisationspunkt bearbeiten</h3><button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button></div>
        <div class="modal-body">
            <?= $view->renderPartial('pages/orga/_task_form', [
                'task' => $task,
                'members' => $members,
                'goals' => $goals,
                'exhibitors' => $exhibitors,
                'prefix' => 'edit',
            ]) ?>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Speichern</button>
        </div>
    </form>
    <form method="post" action="<?= e($s('/orga/aufgaben/' . $taskId . '/loeschen')) ?>" data-confirm="Organisationspunkt samt Checkliste und Kommentaren löschen?"
          style="padding: 0 22px 16px;">
        <?= $csrf->field() ?>
        <button class="btn btn-sm btn-danger-ghost" type="submit">🗑️ Organisationspunkt löschen</button>
    </form>
</dialog>
<?php endif; ?>
