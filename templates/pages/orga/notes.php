<?php
/**
 * Beschlüsse & Protokolle eines Orga-Raums.
 * Erwartet: $access, $space, $team, $notes, $kind, $q, $editing, $canWrite, $unread.
 */
use App\Services\OrgaAccess;
use App\Services\OrgaService;

$s = static fn (string $path): string => $ctx->schoolUrl($path);
$base = $s('/orga/protokolle/' . $space['slug']);
$formAction = $editing !== null ? $s('/orga/protokolle/eintrag/' . (int) $editing['id']) : $base;
$kindBadge = ['protocol' => 'badge-info', 'decision' => 'badge-accent'];
$person = static fn (?string $first, ?string $last, ?string $user): string => $user !== null
    ? OrgaAccess::displayName(['firstname' => $first, 'lastname' => $last, 'username' => $user])
    : 'Unbekannt';
?>
<?= $view->renderPartial('pages/orga/_nav', [
    'space' => $space,
    'active' => 'protokolle',
    'unread' => $unread,
    'sub' => 'Festgehaltene Beschlüsse und Sitzungsprotokolle.',
    'actions' => $canWrite && $editing === null
        ? '<button class="btn btn-primary" type="button" data-open-modal="note-form">＋ Eintrag</button>'
        : '',
]) ?>

<?php foreach (page_blocks('orga-protokolle', [
    'bearbeiten' => 'Eintrag bearbeiten',
    'filter' => 'Filter',
    'liste' => 'Einträge',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'bearbeiten'): ?>
<?php if ($editing !== null && $canWrite): ?>
<div class="card mb-2">
    <div class="card-header"><h3>Eintrag bearbeiten</h3><a class="btn btn-sm btn-ghost" href="<?= e($base) ?>">Abbrechen</a></div>
    <form method="post" action="<?= e($formAction) ?>">
        <?= $csrf->field() ?>
        <div class="card-body">
            <?= $view->renderPartial('pages/orga/_note_fields', ['note' => $editing]) ?>
        </div>
        <div class="card-footer"><button class="btn btn-primary" type="submit">Speichern</button></div>
    </form>
</div>
<?php endif; ?>

<?php elseif ($blockKey === 'filter'): ?>
<form method="get" action="<?= e($base) ?>" class="card card-pad mb-2" data-live="orga-notes">
    <div class="form-grid">
        <div class="field mb-0">
            <label for="n-q">Suche</label>
            <input class="input" type="search" id="n-q" name="q" value="<?= e($q) ?>" placeholder="Titel oder Inhalt">
        </div>
        <div class="field mb-0">
            <label for="n-art">Art</label>
            <select class="input" id="n-art" name="art">
                <option value="">alle</option>
                <?php foreach (OrgaService::NOTE_KINDS as $key => $label): ?>
                    <option value="<?= e($key) ?>" <?= $kind === $key ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <noscript><button class="btn btn-sm mt-2" type="submit">Filtern</button></noscript>
</form>

<?php elseif ($blockKey === 'liste'): ?>
<div data-live-target="orga-notes" class="stack">
    <?php if ($notes === []): ?>
        <div class="empty-state card">
            <div class="empty-icon">📋</div>
            <p class="mb-0">Noch keine Beschlüsse oder Protokolle<?= $q !== '' || $kind !== '' ? ' zu diesem Filter' : '' ?>.</p>
        </div>
    <?php endif; ?>
    <?php foreach ($notes as $i => $note): ?>
        <details class="card card-pad note-entry" <?= $i === 0 ? 'open' : '' ?>>
            <summary>
                <span class="badge <?= e($kindBadge[$note['kind']] ?? '') ?>"><?= e(OrgaService::NOTE_KINDS[$note['kind']] ?? $note['kind']) ?></span>
                <strong style="flex:1;"><?= e($note['title']) ?></strong>
                <span class="text-sm text-faint">
                    <?= $note['meeting_date'] ? '📅 ' . e(format_date($note['meeting_date'])) : e(format_date($note['created_at'])) ?>
                </span>
            </summary>
            <div class="note-body mt-2"><?= e($note['body']) ?></div>
            <div class="cluster mt-2 text-sm text-faint" style="justify-content:space-between;">
                <span>
                    Erstellt von <?= e($person($note['firstname'], $note['lastname'], $note['username'])) ?>
                    <?php if ($note['updated_at'] !== $note['created_at'] && $note['upd_username'] !== null): ?>
                        · zuletzt geändert <?= e(format_datetime($note['updated_at'])) ?> von <?= e($person($note['upd_firstname'], $note['upd_lastname'], $note['upd_username'])) ?>
                    <?php endif; ?>
                </span>
                <?php if ($canWrite): ?>
                    <span class="cluster">
                        <a class="btn btn-sm" href="<?= e($base . '?bearbeiten=' . (int) $note['id']) ?>">Bearbeiten</a>
                        <form method="post" action="<?= e($s('/orga/protokolle/eintrag/' . (int) $note['id'] . '/loeschen')) ?>" data-confirm="Eintrag wirklich löschen?">
                            <?= $csrf->field() ?>
                            <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                        </form>
                    </span>
                <?php endif; ?>
            </div>
        </details>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php if ($canWrite && $editing === null): ?>
<dialog class="modal" id="note-form">
    <form method="post" action="<?= e($formAction) ?>">
        <?= $csrf->field() ?>
        <div class="modal-header"><h3>Neuer Eintrag</h3><button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button></div>
        <div class="modal-body">
            <?= $view->renderPartial('pages/orga/_note_fields', ['note' => null]) ?>
        </div>
        <div class="modal-footer">
            <button class="btn" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Speichern</button>
        </div>
    </form>
</dialog>
<?php endif; ?>
