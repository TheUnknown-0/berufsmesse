<?php
/**
 * Orga-Tool: Einstieg.
 * Erwartet: $access, $teams, $stats, $memberships, $spaces, $unread, $deadline, $myOpen, $myOpenCount.
 */
use App\Services\OrgaService;

$s = static fn (string $path): string => $ctx->schoolUrl($path);
$myTeams = array_values(array_filter($teams, static fn (array $t): bool => array_key_exists((int) $t['id'], $memberships)));
$otherTeams = array_values(array_filter($teams, static fn (array $t): bool => !array_key_exists((int) $t['id'], $memberships)));
$percent = static fn (int $part, int $total): int => $total > 0 ? (int) round($part / $total * 100) : 0;

$teamCard = static function (array $team, array $stat, ?bool $leader) use ($s, $percent): string {
    $id = (int) $team['id'];
    ob_start(); ?>
    <div class="card card-pad team-card stack" style="--team-color: <?= e($team['color']) ?>; gap:10px;">
        <div class="cluster" style="justify-content:space-between;">
            <h3><a class="team-card-title" href="<?= e($s('/orga/teams/' . $id)) ?>"><?= e($team['name']) ?></a></h3>
            <?php if ($leader === true): ?>
                <span class="badge badge-accent">Leitung</span>
            <?php elseif ($leader === false): ?>
                <span class="badge">Mitglied</span>
            <?php endif; ?>
        </div>
        <div class="text-sm text-soft">
            <?= e((string) $stat['members']) ?> Mitglieder
            <?php if ($stat['leaders'] !== []): ?> · Leitung: <?= e(implode(', ', $stat['leaders'])) ?><?php endif; ?>
        </div>
        <div>
            <div class="cluster text-sm" style="justify-content:space-between; margin-bottom:4px;">
                <span>🎯 Ziele</span>
                <span><?= e((string) $stat['goals_done']) ?> / <?= e((string) $stat['goals']) ?></span>
            </div>
            <div class="progress"><span style="width: <?= e((string) $percent($stat['goals_done'], $stat['goals'])) ?>%; background: var(--success);"></span></div>
        </div>
        <div>
            <div class="cluster text-sm" style="justify-content:space-between; margin-bottom:4px;">
                <span>🗂️ Organisationspunkte</span>
                <span><?= e((string) $stat['done']) ?> / <?= e((string) $stat['tasks']) ?></span>
            </div>
            <div class="progress"><span style="width: <?= e((string) $percent($stat['done'], $stat['tasks'])) ?>%;"></span></div>
        </div>
        <?php if ($stat['overdue'] > 0): ?>
            <div class="text-sm text-danger">⚠️ <?= e((string) $stat['overdue']) ?> überfällig</div>
        <?php endif; ?>
        <div class="cluster">
            <a class="btn btn-sm" href="<?= e($s('/orga/teams/' . $id . '/aufgaben')) ?>">Organisationspunkte</a>
            <a class="btn btn-sm btn-ghost" href="<?= e($s('/orga/chat/team-' . $id)) ?>">💬 Chat</a>
        </div>
    </div>
    <?php
    return (string) ob_get_clean();
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($ctx->edition['name'] ?? '') ?></div>
        <h1 class="page-title">Orga-Tool</h1>
        <p class="page-sub">Teams, Ziele, Organisationspunkte und Absprachen der Messe-Organisation.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($s('/orga/meine-aufgaben')) ?>">✅ Meine Aufgaben<?php if ($myOpenCount > 0): ?> <span class="badge badge-accent"><?= e((string) $myOpenCount) ?></span><?php endif; ?></a>
        <?php if ($access->manages()): ?>
            <a class="btn btn-primary" href="<?= e($s('/orga/verwaltung')) ?>">⚙️ Verwaltung</a>
        <?php endif; ?>
    </div>
</div>

<?php foreach (page_blocks('orga-uebersicht', [
    'ankuendigungen' => 'Ankündigungen',
    'countdown' => 'Countdown bis zum Abschluss',
    'kommunikation' => 'Chats',
    'meine-aufgaben' => 'Meine nächsten Aufgaben',
    'meine-teams' => 'Meine Teams',
    'alle-teams' => 'Weitere Teams',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'ankuendigungen'): ?>
    <?= $view->renderPartial('partials/announcements', ['announcements' => $announcements]) ?>
<?php elseif ($blockKey === 'countdown'): ?>
<div class="card card-pad mb-2">
    <?= $view->renderPartial('pages/orga/_countdown', ['deadline' => $deadline, 'canSet' => $access->manages()]) ?>
</div>

<?php elseif ($blockKey === 'kommunikation'): ?>
<div class="card mb-2">
    <div class="card-header"><h3>💬 Chats</h3></div>
    <div class="card-body cluster">
        <?php foreach ($spaces as $space): ?>
            <?php
            $count = (int) ($unread[$space['key']] ?? 0);
            $icon = match ($space['channel']) { 'all' => '📢', 'leads' => '🧭', default => '👥' };
            ?>
            <a class="btn" href="<?= e($s('/orga/chat/' . $space['slug'])) ?>">
                <?php if ($space['channel'] === 'team'): ?>
                    <span class="team-dot" style="background: <?= e((string) $space['color']) ?>;"></span>
                <?php else: ?>
                    <?= $icon ?>
                <?php endif; ?>
                <?= e($space['title']) ?>
                <?php if ($count > 0): ?><span class="nav-count"><?= e((string) $count) ?></span><?php endif; ?>
            </a>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif ($blockKey === 'meine-aufgaben'): ?>
<div class="card mb-2">
    <div class="card-header">
        <h3>✅ Meine nächsten Aufgaben</h3>
        <a class="btn btn-sm btn-ghost" href="<?= e($s('/orga/meine-aufgaben')) ?>">Alle anzeigen</a>
    </div>
    <?php if ($myOpen === []): ?>
        <div class="empty-state" style="padding:24px;"><p class="mb-0">Dir ist gerade nichts Offenes zugewiesen.</p></div>
    <?php else: ?>
        <div class="table-wrap" style="border:0; border-radius:0;">
            <table class="data-table">
                <tbody>
                <?php foreach ($myOpen as $task): ?>
                    <?php $overdue = OrgaService::isOverdue($task['due_date'], (string) $task['status']); ?>
                    <tr>
                        <td><a href="<?= e($s('/orga/aufgaben/' . (int) $task['id'])) ?>"><strong><?= e($task['title']) ?></strong></a></td>
                        <td class="text-sm"><span class="team-dot" style="background: <?= e($task['team_color']) ?>;"></span><?= e($task['team_name']) ?></td>
                        <td><span class="badge <?= e(OrgaService::STATUS_BADGES[$task['status']]) ?>"><?= e(OrgaService::STATUS[$task['status']]) ?></span></td>
                        <td class="text-sm<?= $overdue ? ' text-danger' : '' ?>"><?= $task['due_date'] ? '📅 ' . e(format_date($task['due_date'])) : '' ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<?php elseif ($blockKey === 'meine-teams'): ?>
<h2 class="mt-2">Meine Teams</h2>
<?php if ($myTeams === []): ?>
    <div class="empty-state card mb-2">
        <div class="empty-icon">👥</div>
        <p class="mb-0">Du bist noch keinem Team zugeordnet.<?= $access->manages() ? ' Teams legst du in der Verwaltung an.' : '' ?></p>
    </div>
<?php else: ?>
    <div class="grid-3 mb-2">
        <?php foreach ($myTeams as $team): ?>
            <?= $teamCard($team, $stats[(int) $team['id']], $memberships[(int) $team['id']]) ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'alle-teams'): ?>
<?php if ($access->seesAll() && $otherTeams !== []): ?>
    <h2 class="mt-2">Weitere Teams</h2>
    <p class="text-soft text-sm">Du siehst diese Teams über deine Verwaltungs- bzw. Einsichtsrechte.</p>
    <div class="grid-3 mb-2">
        <?php foreach ($otherTeams as $team): ?>
            <?= $teamCard($team, $stats[(int) $team['id']], null) ?>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
