<?php
/**
 * Meine Aufgaben: alle mir zugewiesenen Organisationspunkte über alle Teams.
 * Erwartet: $access, $tasks, $unassigned, $showDone.
 */
use App\Services\OrgaService;

$s = static fn (string $path): string => $ctx->schoolUrl($path);
$base = $s('/orga/meine-aufgaben');
$overdueCount = count(array_filter($tasks, static fn (array $t): bool => OrgaService::isOverdue($t['due_date'], (string) $t['status'])));
?>
<div class="page-header">
    <div class="page-title-group">
        <a class="page-eyebrow" href="<?= e($s('/orga')) ?>" style="text-decoration:none;">← Orga-Tool</a>
        <h1 class="page-title">Meine Aufgaben</h1>
        <p class="page-sub">Alle Organisationspunkte, für die du zuständig bist, über alle Teams hinweg.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($showDone ? $base : $base . '?erledigt=1') ?>"><?= $showDone ? 'Erledigte ausblenden' : 'Erledigte anzeigen' ?></a>
    </div>
</div>

<?php foreach (page_blocks('orga-meine-aufgaben', [
    'kennzahlen' => 'Kennzahlen',
    'liste' => 'Meine Organisationspunkte',
    'unverteilt' => 'Unverteilt in meinen Teams',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card"><div class="stat-value"><?= e((string) count(array_filter($tasks, static fn (array $t): bool => $t['status'] !== 'done'))) ?></div><div class="stat-label">offen</div></div>
    <div class="stat-card stat-danger"><div class="stat-value"><?= e((string) $overdueCount) ?></div><div class="stat-label">überfällig</div></div>
    <div class="stat-card stat-accent"><div class="stat-value"><?= e((string) count(array_filter($tasks, static fn (array $t): bool => $t['status'] === 'blocked'))) ?></div><div class="stat-label">blockiert</div></div>
</div>

<?php elseif ($blockKey === 'liste'): ?>
<?php if ($tasks === []): ?>
    <div class="empty-state card mb-2">
        <div class="empty-icon">🎉</div>
        <p class="mb-0">Dir ist gerade nichts <?= $showDone ? '' : 'Offenes ' ?>zugewiesen.</p>
    </div>
<?php else: ?>
    <div class="table-wrap mb-2">
        <table class="data-table">
            <thead><tr><th>Organisationspunkt</th><th>Team</th><th>Status</th><th>Priorität</th><th>Frist</th><th>Bezug</th></tr></thead>
            <tbody>
            <?php foreach ($tasks as $task): ?>
                <?php $overdue = OrgaService::isOverdue($task['due_date'], (string) $task['status']); ?>
                <tr>
                    <td><a href="<?= e($s('/orga/aufgaben/' . (int) $task['id'])) ?>"><strong><?= e($task['title']) ?></strong></a>
                        <?php if ((int) $task['check_total'] > 0): ?><div class="text-sm text-faint">☑️ <?= e((string) $task['check_done']) ?>/<?= e((string) $task['check_total']) ?></div><?php endif; ?>
                    </td>
                    <td class="text-sm"><span class="team-dot" style="background: <?= e($task['team_color']) ?>;"></span><?= e($task['team_name']) ?></td>
                    <td><span class="badge <?= e(OrgaService::STATUS_BADGES[$task['status']]) ?>"><?= e(OrgaService::STATUS[$task['status']]) ?></span></td>
                    <td><span class="badge <?= e(OrgaService::PRIORITY_BADGES[$task['priority']]) ?>"><?= e(OrgaService::PRIORITY[$task['priority']]) ?></span></td>
                    <td class="text-sm<?= $overdue ? ' text-danger' : '' ?>"><?= e(format_date($task['due_date'])) ?><?= $overdue ? ' ⚠️' : '' ?></td>
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

<?php elseif ($blockKey === 'unverteilt'): ?>
<?php if ($unassigned !== []): ?>
    <div class="card mb-2">
        <div class="card-header"><h3>📥 Unverteilt in Teams, die du leitest</h3><span class="text-sm text-soft"><?= e((string) count($unassigned)) ?></span></div>
        <div class="table-wrap" style="border:0; border-radius:0;">
            <table class="data-table">
                <tbody>
                <?php foreach ($unassigned as $task): ?>
                    <tr>
                        <td><a href="<?= e($s('/orga/aufgaben/' . (int) $task['id'])) ?>"><strong><?= e($task['title']) ?></strong></a></td>
                        <td class="text-sm"><span class="team-dot" style="background: <?= e($task['team_color']) ?>;"></span><?= e($task['team_name']) ?></td>
                        <td class="text-sm"><?= e(format_date($task['due_date'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
