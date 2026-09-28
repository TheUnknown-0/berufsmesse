<?php
/**
 * Team bearbeiten: Stammdaten, Mitglieder und Leitungen.
 * Erwartet: $access, $team, $current (user_id => is_leader), $candidates, $colors.
 */
use App\Services\OrgaAccess;

$s = static fn (string $path): string => $ctx->schoolUrl($path);
$teamId = (int) $team['id'];
?>
<div class="page-header">
    <div class="page-title-group">
        <a class="page-eyebrow" href="<?= e($s('/orga/verwaltung')) ?>" style="text-decoration:none;">← Orga-Verwaltung</a>
        <h1 class="page-title"><span class="team-dot" style="background: <?= e($team['color']) ?>; width:14px; height:14px;"></span><?= e($team['name']) ?></h1>
        <p class="page-sub"><?= e((string) count($current)) ?> Mitglieder, davon <?= e((string) count(array_filter($current))) ?> in der Leitung.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($s('/orga/teams/' . $teamId)) ?>">Zum Team</a>
    </div>
</div>

<?php foreach (page_blocks('orga-verwaltung-team', [
    'mitglieder' => 'Mitglieder & Leitung',
    'stammdaten' => 'Stammdaten',
    'loeschen' => 'Team löschen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'mitglieder'): ?>
<div class="card mb-2">
    <div class="card-header"><h3>👥 Mitglieder &amp; Leitung</h3></div>
    <form method="post" action="<?= e($s('/orga/verwaltung/teams/' . $teamId . '/mitglieder')) ?>" data-member-form>
        <?= $csrf->field() ?>
        <?php if ($candidates === []): ?>
            <div class="card-body"><div class="alert alert-warning mb-0">Es gibt noch keine Konten mit der Rolle „Orga“.</div></div>
        <?php else: ?>
            <div class="card-body" style="padding-bottom:0;">
                <p class="text-sm text-soft">Nur Konten mit der Rolle „Orga“ können Teams angehören. Eine Person kann in mehreren Teams sein und mehrere leiten. Wer das Team verlässt, verliert seine Zuweisungen darin.</p>
                <input class="input mb-2" type="search" placeholder="Personen filtern …" data-member-filter aria-label="Personen filtern">
            </div>
            <div class="table-wrap" style="border-left:0; border-right:0; border-radius:0;">
                <table class="data-table">
                    <thead><tr><th>Person</th><th>Benutzername</th><th>Mitglied</th><th>Leitung</th></tr></thead>
                    <tbody>
                    <?php foreach ($candidates as $user): ?>
                        <?php $uid = (int) $user['id']; $isMember = array_key_exists($uid, $current); ?>
                        <tr data-member-row data-search="<?= e(mb_strtolower(OrgaAccess::displayName($user) . ' ' . $user['username'])) ?>">
                            <td><strong><?= e(OrgaAccess::displayName($user)) ?></strong></td>
                            <td class="mono text-sm">@<?= e($user['username']) ?></td>
                            <td><label class="checkbox-row mb-0"><input type="checkbox" name="members[]" value="<?= e((string) $uid) ?>" data-member <?= $isMember ? 'checked' : '' ?>><span class="sr-only" style="position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0);">Mitglied</span></label></td>
                            <td><label class="checkbox-row mb-0"><input type="checkbox" name="leaders[]" value="<?= e((string) $uid) ?>" data-leader <?= ($current[$uid] ?? false) ? 'checked' : '' ?>><span class="sr-only" style="position:absolute; width:1px; height:1px; overflow:hidden; clip:rect(0 0 0 0);">Leitung</span></label></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer"><button class="btn btn-primary" type="submit">Zuordnung speichern</button></div>
        <?php endif; ?>
    </form>
</div>

<?php elseif ($blockKey === 'stammdaten'): ?>
<div class="card mb-2">
    <div class="card-header"><h3>✏️ Stammdaten</h3></div>
    <form method="post" action="<?= e($s('/orga/verwaltung/teams/' . $teamId)) ?>">
        <?= $csrf->field() ?>
        <div class="card-body">
            <?= $view->renderPartial('pages/orga/_team_fields', ['team' => $team, 'colors' => $colors]) ?>
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Speichern</button></div>
    </form>
</div>

<?php elseif ($blockKey === 'loeschen'): ?>
<div class="card card-pad mb-2">
    <form method="post" action="<?= e($s('/orga/verwaltung/teams/' . $teamId . '/loeschen')) ?>"
          data-confirm="Team „<?= e($team['name']) ?>“ mit allen Zielen, Organisationspunkten, Chatnachrichten und Protokollen endgültig löschen?">
        <?= $csrf->field() ?>
        <p class="text-sm text-soft">Löscht das Team samt Zielen, Organisationspunkten, Chat und Protokollen. Das lässt sich nicht rückgängig machen.</p>
        <button class="btn btn-danger-ghost" type="submit">🗑️ Team löschen</button>
    </form>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
