<?php
/**
 * Global-Admin — Messe-Editionen je Schule.
 * Erwartet: $schools, $editionsBySchool, $statuses, $old.
 */
$statusLabels = ['draft' => 'Entwurf', 'active' => 'Aktiv', 'archived' => 'Archiviert'];
$statusBadges = ['draft' => 'badge-warning', 'active' => 'badge-success', 'archived' => 'badge-info'];
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">System</div>
        <h1 class="page-title">Messe-Editionen</h1>
        <p class="page-sub">Pro Schule ist genau eine Edition aktiv. Beim Aktivieren wird die bisherige archiviert.</p>
    </div>
    <div class="page-actions">
        <button class="btn btn-primary" type="button" data-open-modal="edition-neu">➕ Edition anlegen</button>
    </div>
</div>

<?= $view->renderPartial('pages/global/_nav', ['active' => '/global-admin/editionen']) ?>

<?php if ($schools === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🏫</div>
        <p>Lege zuerst eine Schule an.</p>
    </div>
<?php endif; ?>

<?php foreach ($schools as $school): ?>
    <?php $editions = $editionsBySchool[(int) $school['id']] ?? []; ?>
    <div class="card">
        <div class="card-header">
            <h3 style="margin:0;"><?= e($school['name']) ?></h3>
            <span class="badge badge-info mono">/<?= e($school['slug']) ?>/</span>
        </div>
        <div class="card-body">
            <?php if ($editions === []): ?>
                <p class="text-sm text-soft">Noch keine Edition angelegt.</p>
            <?php else: ?>
                <div class="table-wrap">
                    <table class="data-table">
                        <thead>
                        <tr>
                            <th>Edition</th>
                            <th>Jahr</th>
                            <th>Messetag</th>
                            <th>Slots</th>
                            <th>Aussteller</th>
                            <th>Anmeldungen</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($editions as $edition): ?>
                            <tr>
                                <td><strong><?= e($edition['name']) ?></strong></td>
                                <td><?= e((string) $edition['year']) ?></td>
                                <td class="nowrap"><?= e($edition['event_date'] !== null ? format_date($edition['event_date']) : '—') ?></td>
                                <td><?= e((string) $edition['timeslot_count']) ?></td>
                                <td><?= e((string) $edition['exhibitor_count']) ?></td>
                                <td><?= e((string) $edition['registration_count']) ?></td>
                                <td>
                                    <span class="badge <?= e($statusBadges[$edition['status']] ?? 'badge-info') ?>">
                                        <?= e($statusLabels[$edition['status']] ?? $edition['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="row-actions">
                                        <button class="btn btn-sm btn-ghost" type="button"
                                                data-open-modal="edition-<?= e((string) $edition['id']) ?>">Bearbeiten</button>
                                        <form method="post"
                                              action="<?= e($ctx->url('/global-admin/editionen/' . (int) $edition['id'] . '/status')) ?>">
                                            <?= $csrf->field() ?>
                                            <select class="input" name="status" aria-label="Status ändern">
                                                <?php foreach ($statuses as $status): ?>
                                                    <option value="<?= e($status) ?>" <?= $edition['status'] === $status ? 'selected' : '' ?>>
                                                        <?= e($statusLabels[$status] ?? $status) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <button class="btn btn-sm btn-ghost" type="submit">Setzen</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>

<dialog class="modal" id="edition-neu">
    <form method="post" action="<?= e($ctx->url('/global-admin/editionen')) ?>">
        <?= $csrf->field() ?>
        <div class="modal-header">
            <h3>Neue Edition</h3>
            <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
        </div>
        <div class="modal-body">
            <div class="field">
                <label for="edition-school">Schule</label>
                <select class="input" id="edition-school" name="school_id" required>
                    <option value="">— bitte wählen —</option>
                    <?php foreach ($schools as $school): ?>
                        <option value="<?= e((string) $school['id']) ?>"><?= e($school['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-grid">
                <div class="field">
                    <label for="edition-name">Bezeichnung</label>
                    <input class="input" type="text" id="edition-name" name="name" required maxlength="200"
                           placeholder="z. B. Berufsmesse <?= e((string) ((int) date('Y') + 1)) ?>"
                           value="<?= e($old['name'] ?? '') ?>">
                </div>
                <div class="field">
                    <label for="edition-year">Jahr</label>
                    <input class="input" type="number" id="edition-year" name="year" required min="2000" max="2100"
                           value="<?= e($old['year'] ?? (string) ((int) date('Y') + 1)) ?>">
                </div>
                <div class="field">
                    <label for="edition-status">Status</label>
                    <select class="input" id="edition-status" name="status">
                        <option value="draft">Entwurf</option>
                        <option value="active">Aktiv (archiviert die bisherige Edition)</option>
                        <option value="archived">Archiviert</option>
                    </select>
                </div>
            </div>
            <label class="checkbox-row">
                <input type="checkbox" name="copy_timeslots" value="1" checked>
                <span>Zeitslots aus der letzten Edition dieser Schule übernehmen</span>
            </label>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="submit">Anlegen</button>
        </div>
    </form>
</dialog>

<?php foreach ($editionsBySchool as $schoolEditions): ?>
    <?php foreach ($schoolEditions as $edition): ?>
        <?php $eid = (int) $edition['id']; ?>
        <dialog class="modal" id="edition-<?= e((string) $eid) ?>">
            <form method="post" action="<?= e($ctx->url('/global-admin/editionen/' . $eid)) ?>">
                <?= $csrf->field() ?>
                <div class="modal-header">
                    <h3>Edition bearbeiten</h3>
                    <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-grid">
                        <div class="field">
                            <label for="e-name-<?= e((string) $eid) ?>">Bezeichnung</label>
                            <input class="input" type="text" id="e-name-<?= e((string) $eid) ?>" name="name"
                                   required maxlength="200" value="<?= e($edition['name']) ?>">
                        </div>
                        <div class="field">
                            <label for="e-year-<?= e((string) $eid) ?>">Jahr</label>
                            <input class="input" type="number" id="e-year-<?= e((string) $eid) ?>" name="year"
                                   required min="2000" max="2100" value="<?= e((string) $edition['year']) ?>">
                        </div>
                        <div class="field">
                            <label for="e-date-<?= e((string) $eid) ?>">Messetag</label>
                            <input class="input" type="date" id="e-date-<?= e((string) $eid) ?>" name="event_date"
                                   value="<?= e($edition['event_date'] !== null ? format_date($edition['event_date'], 'Y-m-d') : '') ?>">
                        </div>
                        <div class="field">
                            <label for="e-max-<?= e((string) $eid) ?>">Max. Anmeldungen je Schüler:in</label>
                            <input class="input" type="number" id="e-max-<?= e((string) $eid) ?>"
                                   name="max_registrations_per_student" min="1" max="20"
                                   value="<?= e((string) $edition['max_registrations_per_student']) ?>">
                        </div>
                        <div class="field">
                            <label for="e-rs-<?= e((string) $eid) ?>">Einschreibung ab</label>
                            <input class="input" type="datetime-local" id="e-rs-<?= e((string) $eid) ?>" name="registration_start"
                                   value="<?= e($edition['registration_start'] !== null ? format_date($edition['registration_start'], 'Y-m-d\TH:i') : '') ?>">
                        </div>
                        <div class="field">
                            <label for="e-re-<?= e((string) $eid) ?>">Einschreibung bis</label>
                            <input class="input" type="datetime-local" id="e-re-<?= e((string) $eid) ?>" name="registration_end"
                                   value="<?= e($edition['registration_end'] !== null ? format_date($edition['registration_end'], 'Y-m-d\TH:i') : '') ?>">
                        </div>
                    </div>
                    <div class="alert alert-info" role="status">
                        Zeitraum, Messetag und Anmelde-Maximum gelten ausschließlich aus dieser Edition.
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
