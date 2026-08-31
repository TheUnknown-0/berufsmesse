<?php
/**
 * Aussteller-Portal — Übersicht der eigenen Unternehmen an dieser Schule.
 * Erwartet: $exhibitors, $pendingRequests, $equipmentByExhibitor,
 *           $daysUntilEvent, $noticeDays.
 */
$statusLabels = ['pending' => 'Offen', 'approved' => 'Genehmigt', 'denied' => 'Abgelehnt'];
$statusBadges = ['pending' => 'badge-warning', 'approved' => 'badge-success', 'denied' => 'badge-danger'];
$totalRegistrations = 0;
foreach ($exhibitors as $exhibitorRow) {
    $totalRegistrations += (int) $exhibitorRow['registration_count'];
}
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><?= e($ctx->school['name']) ?></div>
        <h1 class="page-title">Aussteller-Portal</h1>
        <p class="page-sub">
            Deine Unternehmen bei <?= e($ctx->edition['name'] ?? 'dieser Messe') ?>.
            <?php if ($daysUntilEvent !== null): ?>
                <?php if ($daysUntilEvent > 0): ?>
                    Noch <?= e((string) $daysUntilEvent) ?> Tage bis zum Messetag.
                <?php elseif ($daysUntilEvent === 0): ?>
                    Die Messe findet heute statt.
                <?php else: ?>
                    Die Messe hat bereits stattgefunden.
                <?php endif; ?>
            <?php endif; ?>
        </p>
    </div>
</div>

<?php foreach (page_blocks('portal', [
    'hinweis' => 'Hinweis ohne Unternehmen',
    'kennzahlen' => 'Kennzahlen',
    'unternehmen' => 'Meine Unternehmen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweis'): ?>
<?php if ($exhibitors === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🏢</div>
        <p>Deinem Konto ist an dieser Schule aktuell kein Unternehmen zugeordnet.</p>
        <p class="text-sm text-soft">Bitte wende dich an die Organisation der Schule.</p>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'kennzahlen'): ?>
<?php if ($exhibitors !== []): ?>
    <div class="stat-grid">
        <div class="stat-card stat-accent">
            <div class="stat-value"><?= e((string) count($exhibitors)) ?></div>
            <div class="stat-label">Unternehmen</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= e((string) $totalRegistrations) ?></div>
            <div class="stat-label">Anmeldungen gesamt</div>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'unternehmen'): ?>
<?php if ($exhibitors !== []): ?>
    <div class="grid-2">
        <?php foreach ($exhibitors as $exhibitor): ?>
            <?php
            $exhibitorId = (int) $exhibitor['id'];
            $requests = $equipmentByExhibitor[$exhibitorId] ?? [];
            $openRequests = 0;
            foreach ($requests as $request) {
                if ($request['status'] === 'pending') {
                    $openRequests++;
                }
            }
            $pending = $pendingRequests[$exhibitorId] ?? null;
            ?>
            <div class="card">
                <div class="card-header">
                    <div class="cluster">
                        <?php if (!empty($exhibitor['logo'])): ?>
                            <img src="<?= e($ctx->url('/medien/logos/' . $exhibitor['logo'])) ?>"
                                 alt="Logo <?= e($exhibitor['name']) ?>"
                                 style="width:44px;height:44px;object-fit:contain;border-radius:8px;">
                        <?php else: ?>
                            <span aria-hidden="true" style="font-size:1.6rem;">🏢</span>
                        <?php endif; ?>
                        <h3 style="margin:0;"><?= e($exhibitor['name']) ?></h3>
                    </div>
                </div>
                <div class="card-body">
                    <div class="chip-row">
                        <span class="badge badge-info">
                            <?= $exhibitor['room_number'] !== null
                                ? 'Raum ' . e($exhibitor['room_number'])
                                : 'Kein Raum zugewiesen' ?>
                        </span>
                        <span class="badge badge-primary"><?= e((string) $exhibitor['registration_count']) ?> Anmeldungen</span>
                        <?php if ($openRequests > 0): ?>
                            <span class="badge badge-warning"><?= e((string) $openRequests) ?> offene Ausstattungsanfrage(n)</span>
                        <?php endif; ?>
                    </div>

                    <?php if ($pending !== null): ?>
                        <div class="alert alert-warning" role="status" style="margin-top:12px;">
                            Deine Absage vom <?= e(format_datetime($pending['created_at'])) ?> wartet auf Bestätigung durch die Schule.
                            Bis dahin bist du fest eingeplant.
                        </div>
                    <?php endif; ?>

                    <?php if ($requests !== []): ?>
                        <div class="divider"></div>
                        <div class="text-sm text-soft" style="margin-bottom:6px;">Ausstattungsanfragen</div>
                        <div class="chip-row">
                            <?php foreach (array_slice($requests, 0, 5) as $request): ?>
                                <span class="badge <?= e($statusBadges[$request['status']] ?? 'badge-info') ?>">
                                    <?= e($request['option_name'] ?? $request['custom_text'] ?? 'Wunsch') ?>
                                    · <?= e((string) $request['quantity']) ?>×
                                    · <?= e($statusLabels[$request['status']] ?? $request['status']) ?>
                                </span>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="card-footer">
                    <div class="cluster">
                        <?php if ((int) $exhibitor['can_edit_profile'] === 1): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e($ctx->schoolUrl('/portal/profil/' . $exhibitorId)) ?>">✏️ Profil</a>
                        <?php endif; ?>
                        <a class="btn btn-sm btn-ghost" href="<?= e($ctx->schoolUrl('/portal/slots')) ?>">🗓️ Slots</a>
                        <a class="btn btn-sm btn-ghost" href="<?= e($ctx->schoolUrl('/portal/ausstattung')) ?>">🔌 Ausstattung</a>
                        <?php if ((int) $exhibitor['can_manage_documents'] === 1): ?>
                            <a class="btn btn-sm btn-ghost" href="<?= e($ctx->schoolUrl('/portal/dokumente')) ?>">📄 Dokumente</a>
                        <?php endif; ?>
                        <?php if ($pending === null): ?>
                            <button class="btn btn-sm btn-danger-ghost" type="button"
                                    data-open-modal="absage-<?= e((string) $exhibitorId) ?>">🚫 Teilnahme absagen</button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php foreach ($exhibitors as $exhibitor): ?>
        <?php
        $exhibitorId = (int) $exhibitor['id'];
        if (($pendingRequests[$exhibitorId] ?? null) !== null) {
            continue;
        }
        ?>
        <dialog class="modal" id="absage-<?= e((string) $exhibitorId) ?>">
            <form method="post" action="<?= e($ctx->schoolUrl('/portal/absage/' . $exhibitorId)) ?>">
                <?= $csrf->field() ?>
                <div class="modal-header">
                    <h3>Teilnahme absagen</h3>
                    <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
                </div>
                <div class="modal-body">
                    <p>
                        Du sagst die Teilnahme von <strong><?= e($exhibitor['name']) ?></strong> an
                        <?= e($ctx->edition['name'] ?? 'dieser Messe') ?> ab.
                    </p>
                    <?php if ($daysUntilEvent !== null && $daysUntilEvent < $noticeDays): ?>
                        <div class="alert alert-warning" role="status">
                            Weniger als <?= e((string) $noticeDays) ?> Tage bis zur Messe: Deine Absage muss von der
                            Schule bestätigt werden. Bis dahin bleibst du eingeplant.
                        </div>
                    <?php else: ?>
                        <div class="alert alert-error" role="status">
                            Die Absage wird sofort wirksam. Alle bestehenden Anmeldungen werden aufgelöst und die
                            betroffenen Schüler:innen benachrichtigt.
                        </div>
                    <?php endif; ?>
                    <div class="field">
                        <label for="reason-<?= e((string) $exhibitorId) ?>">Begründung (Pflicht)</label>
                        <textarea class="input" id="reason-<?= e((string) $exhibitorId) ?>" name="reason"
                                  rows="4" required maxlength="500"></textarea>
                        <div class="hint">Die Begründung geht an die Organisation der Schule.</div>
                    </div>
                    <label class="checkbox-row">
                        <input type="checkbox" name="confirm" value="1" required>
                        <span>Ich bestätige, dass die Teilnahme verbindlich abgesagt wird.</span>
                    </label>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
                    <button class="btn btn-danger" type="submit">Absage abschicken</button>
                </div>
            </form>
        </dialog>
<?php endforeach; ?>
