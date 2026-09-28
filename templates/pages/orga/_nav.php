<?php
/**
 * Kopf und Reiter eines Orga-Raums (Team, Gesamtchat, Leitungsrunde).
 * Erwartet: $space, $active ('team'|'aufgaben'|'chat'|'protokolle'), $unread,
 * optional $sub (Untertitel), $actions (HTML für page-actions, bereits escaped).
 */
$s = static fn (string $path): string => $ctx->schoolUrl($path);
$count = (int) ($unread[$space['key']] ?? 0);
$isTeam = $space['channel'] === 'team';
$eyebrow = $isTeam ? 'Orga-Team' : 'Orga-Tool';
?>
<div class="page-header">
    <div class="page-title-group">
        <a class="page-eyebrow" href="<?= e($s('/orga')) ?>" style="text-decoration:none;">← <?= e($eyebrow) ?></a>
        <h1 class="page-title">
            <?php if ($isTeam && !empty($space['color'])): ?>
                <span class="team-dot" style="background: <?= e($space['color']) ?>; width:14px; height:14px;"></span>
            <?php endif; ?>
            <?= e($space['title']) ?>
        </h1>
        <?php if (!empty($sub)): ?>
            <p class="page-sub"><?= e($sub) ?></p>
        <?php endif; ?>
    </div>
    <?php if (!empty($actions)): ?>
        <div class="page-actions"><?= $actions ?></div>
    <?php endif; ?>
</div>

<nav class="tabs" aria-label="Bereiche">
    <?php if ($isTeam): ?>
        <a class="tab<?= $active === 'team' ? ' active' : '' ?>" href="<?= e($s('/orga/teams/' . (int) $space['team_id'])) ?>">🎯 Ziele &amp; Überblick</a>
        <a class="tab<?= $active === 'aufgaben' ? ' active' : '' ?>" href="<?= e($s('/orga/teams/' . (int) $space['team_id'] . '/aufgaben')) ?>">🗂️ Organisationspunkte</a>
    <?php endif; ?>
    <a class="tab<?= $active === 'chat' ? ' active' : '' ?>" href="<?= e($s('/orga/chat/' . $space['slug'])) ?>">
        💬 Chat<?php if ($count > 0 && $active !== 'chat'): ?><span class="nav-count"><?= e((string) $count) ?></span><?php endif; ?>
    </a>
    <a class="tab<?= $active === 'protokolle' ? ' active' : '' ?>" href="<?= e($s('/orga/protokolle/' . $space['slug'])) ?>">📋 Beschlüsse &amp; Protokolle</a>
</nav>
