<?php
/**
 * Räume & Aussteller-Zuteilung.
 * Erwartet: $rooms, $assigned (room_id => Aussteller), $unassigned, $exhibitorCount.
 */

use App\Core\Permissions as P;

$schoolId = $ctx->schoolId();
$can = static fn (string $p): bool => $auth->can($p, $schoolId);
$canEdit = $can(P::RAEUME_BEARBEITEN);
$assignedCount = $exhibitorCount - count($unassigned);

/** Rendert die Felder des Raum-Formulars (Anlegen und Bearbeiten teilen sie). */
$roomFields = static function (array $room, string $prefix) use ($view): string {
    return $view->renderPartial('pages/rooms/fields', ['room' => $room, 'prefix' => $prefix]);
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Verwaltung</div>
        <h1 class="page-title">Räume</h1>
        <p class="page-sub">Raumdaten pflegen und Aussteller den Räumen zuordnen.</p>
    </div>
    <div class="page-actions">
        <a class="btn" href="<?= e($ctx->schoolUrl('/admin/raumplan')) ?>">🗺️ Raumplanung</a>
        <?php if ($can(P::RAEUME_ERSTELLEN)): ?>
            <button class="btn btn-primary" type="button" data-open-modal="room-new">➕ Neuer Raum</button>
        <?php endif; ?>
        <?php if ($canEdit && $assignedCount > 0): ?>
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/raeume/zuteilungen-aufheben')) ?>"
                  data-confirm="Wirklich alle Raumzuteilungen aufheben? Danach hat kein Aussteller mehr einen Raum.">
                <?= $csrf->field() ?>
                <button class="btn btn-danger-ghost" type="submit">Alle Zuteilungen aufheben</button>
            </form>
        <?php endif; ?>
    </div>
</div>

<?php foreach (page_blocks('admin-raeume', [
    'kennzahlen' => 'Kennzahlen',
    'ohne-raum' => 'Aussteller ohne Raum',
    'raumliste' => 'Raumliste & Zuteilung',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
<div class="stat-grid">
    <div class="stat-card">
        <div class="stat-value"><?= e((string) count($rooms)) ?></div>
        <div class="stat-label">Räume</div>
    </div>
    <div class="stat-card stat-success">
        <div class="stat-value"><?= e((string) $assignedCount) ?></div>
        <div class="stat-label">Aussteller mit Raum</div>
    </div>
    <div class="stat-card stat-danger">
        <div class="stat-value"><?= e((string) count($unassigned)) ?></div>
        <div class="stat-label">Ohne Raum</div>
    </div>
</div>

<?php elseif ($blockKey === 'ohne-raum'): ?>
<?php if ($unassigned !== []): ?>
    <div class="alert alert-warning">
        <div>
            <strong>Noch nicht zugeordnet</strong>
            <div class="chip-row mt-2">
                <?php foreach ($unassigned as $exhibitor): ?>
                    <span class="badge<?= (int) $exhibitor['active'] === 1 ? ' badge-warning' : '' ?>">
                        <?= e($exhibitor['name']) ?><?= (int) $exhibitor['active'] === 1 ? '' : ' (inaktiv)' ?>
                    </span>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'raumliste'): ?>
<?php if ($rooms === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🚪</div>
        <p>Es sind noch keine Räume angelegt.</p>
    </div>
<?php else: ?>
    <div class="stack">
        <?php foreach ($rooms as $room): ?>
            <?php
            $roomId = (int) $room['id'];
            $inRoom = $assigned[$roomId] ?? [];
            ?>
            <div class="card">
                <div class="card-header">
                    <h3>
                        <?= e($room['room_number']) ?>
                        <?php if (!empty($room['room_name'])): ?>
                            <span class="text-soft text-sm">· <?= e($room['room_name']) ?></span>
                        <?php endif; ?>
                    </h3>
                    <span class="badge badge-info"><?= e((string) count($inRoom)) ?> Aussteller</span>
                    <span class="badge">Kapazität <?= e((string) (int) $room['capacity']) ?></span>
                    <?php if ($canEdit): ?>
                        <button class="btn btn-sm" type="button" data-open-modal="room-edit-<?= e((string) $roomId) ?>">Bearbeiten</button>
                    <?php endif; ?>
                    <?php if ($can(P::RAEUME_LOESCHEN)): ?>
                        <form method="post" action="<?= e($ctx->schoolUrl('/admin/raeume/' . $roomId . '/loeschen')) ?>"
                              data-confirm="Raum <?= e($room['room_number']) ?> löschen? <?= e((string) count($inRoom)) ?> zugewiesene Aussteller verlieren dadurch ihren Raum; auch die Slot-Kapazitäten des Raums werden entfernt.">
                            <?= $csrf->field() ?>
                            <button class="btn btn-sm btn-danger-ghost" type="submit">Löschen</button>
                        </form>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <p class="text-soft text-sm">
                        <?php if (!empty($room['building'])): ?>🏫 <?= e($room['building']) ?><?php endif; ?>
                        <?php if (!empty($room['floor'])): ?> · Etage <?= e($room['floor']) ?><?php endif; ?>
                        <?php if (!empty($room['equipment'])): ?> · 🔌 <?= e($room['equipment']) ?><?php endif; ?>
                    </p>

                    <?php if ($inRoom === []): ?>
                        <p class="text-faint">Diesem Raum ist noch kein Aussteller zugeordnet.</p>
                    <?php else: ?>
                        <div class="stack mb-2">
                            <?php foreach ($inRoom as $exhibitor): ?>
                                <div class="cluster">
                                    <span style="flex:1;min-width:160px;">
                                        <?= e($exhibitor['name']) ?>
                                        <?php if ((int) $exhibitor['active'] !== 1): ?>
                                            <span class="badge">inaktiv</span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($canEdit): ?>
                                        <form method="post" action="<?= e($ctx->schoolUrl('/admin/raeume/zuteilung-loesen')) ?>"
                                              data-confirm="Zuteilung von &quot;<?= e($exhibitor['name']) ?>&quot; lösen?">
                                            <?= $csrf->field() ?>
                                            <input type="hidden" name="exhibitor_id" value="<?= e((string) (int) $exhibitor['id']) ?>">
                                            <button class="btn btn-sm btn-ghost" type="submit">Lösen</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if ($canEdit && $unassigned !== []): ?>
                        <form method="post" class="cluster" action="<?= e($ctx->schoolUrl('/admin/raeume/zuteilen')) ?>">
                            <?= $csrf->field() ?>
                            <input type="hidden" name="room_id" value="<?= e((string) $roomId) ?>">
                            <label class="text-sm text-soft" for="assign-<?= e((string) $roomId) ?>">Aussteller zuweisen</label>
                            <select id="assign-<?= e((string) $roomId) ?>" name="exhibitor_id" required style="max-width:280px;">
                                <option value="">Bitte wählen …</option>
                                <?php foreach ($unassigned as $exhibitor): ?>
                                    <option value="<?= e((string) (int) $exhibitor['id']) ?>"><?= e($exhibitor['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <button class="btn btn-sm btn-primary" type="submit">Zuweisen</button>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php if ($can(P::RAEUME_ERSTELLEN)): ?>
    <dialog class="modal" id="room-new">
        <form method="post" action="<?= e($ctx->schoolUrl('/admin/raeume/neu')) ?>">
            <?= $csrf->field() ?>
            <div class="modal-header">
                <h3>Neuer Raum</h3>
                <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
            </div>
            <div class="modal-body">
                <?= $roomFields([], 'new') ?>
            </div>
            <div class="modal-footer">
                <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                <button class="btn btn-primary" type="submit">Raum anlegen</button>
            </div>
        </form>
    </dialog>
<?php endif; ?>

<?php if ($canEdit): ?>
    <?php foreach ($rooms as $room): ?>
        <dialog class="modal" id="room-edit-<?= e((string) (int) $room['id']) ?>">
            <form method="post" action="<?= e($ctx->schoolUrl('/admin/raeume/' . (int) $room['id'])) ?>">
                <?= $csrf->field() ?>
                <div class="modal-header">
                    <h3>Raum <?= e($room['room_number']) ?> bearbeiten</h3>
                    <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
                </div>
                <div class="modal-body">
                    <?= $roomFields($room, 'edit-' . (int) $room['id']) ?>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                    <button class="btn btn-primary" type="submit">Speichern</button>
                </div>
            </form>
        </dialog>
    <?php endforeach; ?>
<?php endif; ?>
