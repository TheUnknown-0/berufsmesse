<?php
/**
 * Formularfelder eines Organisationspunkts (Neu-Dialog und Bearbeiten).
 * Erwartet: $task (array|null), $members, $goals, $exhibitors, $prefix (ID-Präfix).
 */
use App\Services\OrgaAccess;
use App\Services\OrgaService;

$v = static fn (string $key, string $default = ''): string => (string) ($task[$key] ?? $default);
?>
<div class="field">
    <label for="<?= e($prefix) ?>-title">Titel</label>
    <input class="input" id="<?= e($prefix) ?>-title" name="title" required maxlength="200" value="<?= e($v('title')) ?>">
</div>
<div class="field">
    <label for="<?= e($prefix) ?>-desc">Beschreibung</label>
    <textarea class="input" id="<?= e($prefix) ?>-desc" name="description" rows="4" maxlength="5000"><?= e($v('description')) ?></textarea>
</div>
<div class="form-grid">
    <div class="field">
        <label for="<?= e($prefix) ?>-status">Status</label>
        <select class="input" id="<?= e($prefix) ?>-status" name="status">
            <?php foreach (OrgaService::STATUS as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $v('status', 'open') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="<?= e($prefix) ?>-priority">Priorität</label>
        <select class="input" id="<?= e($prefix) ?>-priority" name="priority">
            <?php foreach (OrgaService::PRIORITY as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $v('priority', 'normal') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="<?= e($prefix) ?>-due">Frist</label>
        <input class="input" type="date" id="<?= e($prefix) ?>-due" name="due_date" value="<?= e($v('due_date')) ?>">
    </div>
    <div class="field">
        <label for="<?= e($prefix) ?>-assignee">Zuständig</label>
        <select class="input" id="<?= e($prefix) ?>-assignee" name="assignee_id">
            <option value="">niemand</option>
            <?php foreach ($members as $member): ?>
                <option value="<?= e((string) $member['id']) ?>" <?= $v('assignee_id') === (string) $member['id'] ? 'selected' : '' ?>>
                    <?= e(OrgaAccess::displayName($member)) ?><?= (int) $member['is_leader'] === 1 ? ' (Leitung)' : '' ?>
                </option>
            <?php endforeach; ?>
        </select>
        <?php if ($members === []): ?><div class="hint">Das Team hat noch keine Mitglieder.</div><?php endif; ?>
    </div>
    <div class="field">
        <label for="<?= e($prefix) ?>-goal">Gehört zum Ziel</label>
        <select class="input" id="<?= e($prefix) ?>-goal" name="goal_id">
            <option value="">kein Ziel</option>
            <?php foreach ($goals as $goal): ?>
                <option value="<?= e((string) $goal['id']) ?>" <?= $v('goal_id') === (string) $goal['id'] ? 'selected' : '' ?>><?= e($goal['title']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="<?= e($prefix) ?>-exhibitor">Betrifft Aussteller</label>
        <select class="input" id="<?= e($prefix) ?>-exhibitor" name="exhibitor_id">
            <option value="">keinen</option>
            <?php foreach ($exhibitors as $exhibitor): ?>
                <option value="<?= e((string) $exhibitor['id']) ?>" <?= $v('exhibitor_id') === (string) $exhibitor['id'] ? 'selected' : '' ?>><?= e($exhibitor['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</div>
