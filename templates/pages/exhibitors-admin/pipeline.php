<?php
/**
 * Aussteller-Pipeline: Akquisestand je Unternehmen, gruppiert nach Stufe.
 * Erwartet: $byStage, $stages, $total, $dueCount, $onlyDue, $canEdit.
 */
$base = $ctx->schoolUrl('/admin/aussteller');

$stageBadge = static fn (string $stage): string => match ($stage) {
    'confirmed' => 'badge badge-success',
    'contacted' => 'badge badge-info',
    'lead' => 'badge badge-warning',
    'declined' => 'badge badge-danger',
    default => 'badge',
};
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($base) ?>">← Aussteller</a></div>
        <h1 class="page-title">Pipeline</h1>
        <p class="page-sub">
            Akquisestand der Unternehmen für die laufende Messe. „Zugesagt“ schaltet einen Aussteller
            automatisch für Schüler:innen sichtbar, „Abgesagt“ und „Storniert“ blenden ihn aus.
        </p>
    </div>
    <div class="page-actions">
        <?php if ($onlyDue): ?>
            <a class="btn btn-ghost" href="<?= e($base . '/pipeline') ?>">Alle anzeigen</a>
        <?php else: ?>
            <a class="btn btn-ghost" href="<?= e($base . '/pipeline?faellig=1') ?>">
                ⏰ Nur Wiedervorlagen<?= $dueCount > 0 ? ' (' . e((string) $dueCount) . ')' : '' ?>
            </a>
        <?php endif; ?>
    </div>
</div>

<?php foreach (page_blocks('admin-aussteller-pipeline', [
    'kennzahlen' => 'Kennzahlen',
    'stufen' => 'Pipeline-Stufen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'kennzahlen'): ?>
    <div class="stat-grid">
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $total) ?></div>
            <div class="stat-label">Unternehmen gesamt</div>
        </div>
        <div class="stat-card stat-success">
            <div class="stat-value"><?= e((string) count($byStage['confirmed'])) ?></div>
            <div class="stat-label">zugesagt</div>
        </div>
        <div class="stat-card stat-accent">
            <div class="stat-value"><?= e((string) (count($byStage['lead']) + count($byStage['contacted']))) ?></div>
            <div class="stat-label">offen</div>
        </div>
        <div class="stat-card<?= $dueCount > 0 ? ' stat-danger' : '' ?>">
            <div class="stat-value"><?= e((string) $dueCount) ?></div>
            <div class="stat-label">Wiedervorlagen fällig</div>
        </div>
    </div>

<?php elseif ($blockKey === 'stufen'): ?>
    <?php if ($onlyDue && $dueCount === 0): ?>
        <div class="empty-state">
            <div class="empty-icon">⏰</div>
            <p>Keine fälligen Wiedervorlagen. Alles im Griff.</p>
        </div>
    <?php endif; ?>

    <?php foreach ($stages as $stageKey => $stageLabel): ?>
        <?php $items = $byStage[$stageKey] ?? []; ?>
        <?php if ($items === [] && $onlyDue): ?>
            <?php continue; ?>
        <?php endif; ?>

        <div class="card">
            <div class="card-header">
                <h2 class="mt-0 mb-0">
                    <span class="<?= e($stageBadge($stageKey)) ?>"><?= e($stageLabel) ?></span>
                    <span class="text-soft text-sm"><?= e((string) count($items)) ?></span>
                </h2>
            </div>
            <div class="card-body">
                <?php if ($items === []): ?>
                    <p class="text-faint text-sm mb-0">Keine Unternehmen in dieser Stufe.</p>
                <?php else: ?>
                    <div class="stack">
                        <?php foreach ($items as $exhibitor): ?>
                            <?php
                            $id = (int) $exhibitor['id'];
                            $summary = $exhibitor['summary'];
                            ?>
                            <div class="card card-pad">
                                <div class="cluster">
                                    <div style="flex:1;min-width:220px;">
                                        <strong><a href="<?= e($base . '/' . $id) ?>"><?= e($exhibitor['name']) ?></a></strong>
                                        <?php if ($exhibitor['is_due']): ?>
                                            <span class="badge badge-danger">Wiedervorlage fällig</span>
                                        <?php endif; ?>

                                        <div class="text-sm text-soft">
                                            <?php if (!empty($exhibitor['contact_person'])): ?>
                                                <?= e($exhibitor['contact_person']) ?>
                                            <?php endif; ?>
                                            <?php if (!empty($exhibitor['email'])): ?>
                                                · <span class="mono"><?= e($exhibitor['email']) ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($exhibitor['phone'])): ?>
                                                · <?= e($exhibitor['phone']) ?>
                                            <?php endif; ?>
                                        </div>

                                        <div class="chip-row">
                                            <?php if ($summary['last_year'] !== null): ?>
                                                <span class="badge badge-info">
                                                    <?= e((string) $summary['years']) ?>. Jahrgang
                                                </span>
                                                <span class="badge">
                                                    <?= e((string) $summary['last_year']) ?>:
                                                    <?= e((string) $summary['last_attendances']) ?> Besuche
                                                </span>
                                            <?php else: ?>
                                                <span class="badge badge-accent">neu</span>
                                            <?php endif; ?>
                                            <?php if ($exhibitor['follow_up_at'] !== null): ?>
                                                <span class="badge">Wiedervorlage <?= e(format_date($exhibitor['follow_up_at'])) ?></span>
                                            <?php endif; ?>
                                        </div>

                                        <?php if (!empty($exhibitor['last_note'])): ?>
                                            <p class="text-sm mb-0">
                                                <span class="text-faint"><?= e(format_datetime($exhibitor['last_note_at'])) ?>:</span>
                                                <?= e(mb_strimwidth((string) $exhibitor['last_note'], 0, 160, ' …')) ?>
                                                <?php if ((int) $exhibitor['note_count'] > 1): ?>
                                                    <a class="text-soft" href="<?= e($base . '/' . $id) ?>">
                                                        (<?= e((string) $exhibitor['note_count']) ?> Einträge)
                                                    </a>
                                                <?php endif; ?>
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <?php if ($canEdit): ?>
                                        <button class="btn btn-sm btn-ghost" type="button"
                                                data-open-modal="stage-<?= e((string) $id) ?>">Status ändern</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php if ($canEdit): ?>
    <?php foreach ($byStage as $items): ?>
        <?php foreach ($items as $exhibitor): ?>
            <?php $id = (int) $exhibitor['id']; ?>
            <dialog class="modal" id="stage-<?= e((string) $id) ?>">
                <form method="post" action="<?= e($base . '/' . $id . '/pipeline') ?>">
                    <?= $csrf->field() ?>
                    <div class="modal-header">
                        <h3><?= e($exhibitor['name']) ?></h3>
                        <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
                    </div>
                    <div class="modal-body">
                        <div class="field">
                            <label for="stage-select-<?= e((string) $id) ?>">Status</label>
                            <select id="stage-select-<?= e((string) $id) ?>" name="pipeline_status">
                                <?php foreach ($stages as $stageKey => $stageLabel): ?>
                                    <option value="<?= e($stageKey) ?>"<?= (string) $exhibitor['pipeline_status'] === $stageKey ? ' selected' : '' ?>>
                                        <?= e($stageLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="hint">„Zugesagt“ macht den Aussteller sichtbar, alle anderen Stufen blenden ihn aus.</div>
                        </div>

                        <div class="field">
                            <label for="follow-<?= e((string) $id) ?>">Wiedervorlage am</label>
                            <input class="input" type="date" id="follow-<?= e((string) $id) ?>" name="follow_up_at"
                                   value="<?= e($exhibitor['follow_up_at'] !== null ? format_date($exhibitor['follow_up_at'], 'Y-m-d') : '') ?>">
                            <div class="hint">Leer lassen, wenn nichts nachzuhalten ist.</div>
                        </div>

                        <div class="field">
                            <label for="note-<?= e((string) $id) ?>">Notiz zum Gespräch</label>
                            <textarea id="note-<?= e((string) $id) ?>" name="note" rows="3" maxlength="2000"
                                      placeholder="z. B. „Frau Meyer sagt bis Ende der Woche Bescheid.“"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                        <button class="btn btn-primary" type="submit">Speichern</button>
                    </div>
                </form>
            </dialog>
        <?php endforeach; ?>
    <?php endforeach; ?>
<?php endif; ?>
