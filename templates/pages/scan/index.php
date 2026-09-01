<?php
/**
 * Lehrer-Scanner: Raum + Slot wählen, Schüler-QR-Codes scannen.
 * Erwartet: $enabled, $rooms, $slots, $assignments, $selectedRoomId,
 *           $selectedSlotId, $edition
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Aufsicht</div>
        <h1 class="page-title">Scanner</h1>
        <p class="page-sub">Persönliche QR-Codes der Schüler:innen scannen und die Anwesenheit im Raum erfassen.</p>
        <div class="alert alert-warning" data-scan-queue hidden role="status"></div>
    </div>
</div>

<?php foreach (page_blocks('scan', [
    'aufsichten' => 'Deine Aufsichten',
    'hinweise' => 'Hinweise',
    'scanner' => 'Scanner & Raum-Abgleich',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'aufsichten'): ?>
<?php if ($assignments !== []): ?>
    <div class="card card-pad mb-2">
        <h3 class="mb-0">🦺 Deine Aufsichten</h3>
        <div class="chip-row mt-2">
            <?php foreach ($assignments as $assignment): ?>
                <span class="badge badge-primary">
                    <?= e(trim((string) ($assignment['room_number'] ?? '') . ' ' . (string) ($assignment['room_name'] ?? ''))) ?>
                    ·
                    <?php if ($assignment['timeslot_id'] === null): ?>
                        ganztags
                    <?php else: ?>
                        <?= e($assignment['slot_name'] ?? '') ?>
                        (<?= e(substr((string) $assignment['start_time'], 0, 5)) ?>–<?= e(substr((string) $assignment['end_time'], 0, 5)) ?>)
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'hinweise'): ?>
<?php if (!$enabled): ?>
    <div class="alert alert-warning">
        <span>⚠️</span>
        <div>Der Lehrer-Check-in ist an dieser Schule deaktiviert. Die Anwesenheit lässt sich weiterhin in der Verwaltung pflegen.</div>
    </div>
<?php elseif ($rooms === [] || $slots === []): ?>
    <div class="alert alert-warning">
        <span>⚠️</span>
        <div>Für diese Messe sind noch keine Räume oder Zeitslots angelegt.</div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'scanner'): ?>
<?php if ($enabled && $rooms !== [] && $slots !== []): ?>
    <div id="scan-app"
         data-checkin-url="<?= e($ctx->schoolUrl('/api/scan/checkin')) ?>"
         data-roster-url="<?= e($ctx->schoolUrl('/api/scan/roster')) ?>">

        <div class="card card-pad mb-2">
            <div class="form-grid">
                <div class="field">
                    <label for="scan-room">Raum</label>
                    <select id="scan-room">
                        <?php foreach ($rooms as $room): ?>
                            <option value="<?= e((string) (int) $room['id']) ?>"
                                <?= $selectedRoomId === (int) $room['id'] ? 'selected' : '' ?>>
                                <?= e(trim((string) $room['room_number'] . ' ' . (string) ($room['room_name'] ?? ''))) ?>
                                <?= !empty($room['exhibitor_name']) ? ' — ' . e($room['exhibitor_name']) : ' — kein Aussteller' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="field">
                    <label for="scan-slot">Zeitslot</label>
                    <select id="scan-slot">
                        <?php foreach ($slots as $slot): ?>
                            <option value="<?= e((string) (int) $slot['id']) ?>"
                                <?= $selectedSlotId === (int) $slot['id'] ? 'selected' : '' ?>>
                                <?= e($slot['slot_name'] ?? ('Slot ' . $slot['slot_number'])) ?>
                                (<?= e(substr((string) $slot['start_time'], 0, 5)) ?>–<?= e(substr((string) $slot['end_time'], 0, 5)) ?>)
                                <?= (int) $slot['is_managed'] === 0 ? ' · freie Wahl' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php /* Erscheint nur, wenn sich mehrere Aussteller einen Raum teilen. */ ?>
            <div class="form-row" id="scan-exhibitor-choice" hidden></div>
        </div>

        <div class="grid-2">
            <div class="card">
                <div class="card-header"><h2>📷 Scannen</h2></div>
                <div class="card-body">
                    <div id="scan-result" role="status"></div>
                    <div id="scan-camera" class="mb-2"></div>
                    <div class="cluster">
                        <button class="btn btn-primary" type="button" id="scan-start">Kamera starten</button>
                        <button class="btn btn-ghost" type="button" id="scan-stop" hidden>Kamera stoppen</button>
                    </div>
                    <hr class="divider">
                    <form id="scan-form">
                        <div class="field">
                            <label for="scan-token">Code manuell eingeben</label>
                            <input class="input mono" type="text" id="scan-token" autocomplete="off"
                                   spellcheck="false" placeholder="S-…">
                        </div>
                        <button class="btn btn-accent" type="submit">Einchecken</button>
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header">
                    <h2>✅ Raum-Abgleich</h2>
                    <span class="badge" id="scan-counter">–</span>
                </div>
                <div class="card-body">
                    <p class="text-soft text-sm" id="scan-room-label">Wird geladen …</p>
                    <h3>Anwesend</h3>
                    <div id="scan-present" class="stack" style="gap:6px;"></div>
                    <hr class="divider">
                    <h3>Fehlt noch</h3>
                    <div id="scan-missing" class="stack" style="gap:6px;"></div>
                </div>
                <div class="card-footer">
                    <button class="btn btn-sm btn-ghost" type="button" id="scan-refresh">Aktualisieren</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

<?php if ($enabled && $rooms !== [] && $slots !== []): ?>
    <dialog class="modal" id="scan-confirm">
        <div class="modal-header">
            <h3>Falscher Raum?</h3>
            <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
        </div>
        <div class="modal-body">
            <p id="scan-confirm-text"></p>
            <p class="text-soft text-sm mb-0">
                Beim Bestätigen wird der Check-in mit dem Vermerk „falscher Raum“ gespeichert.
            </p>
        </div>
        <div class="modal-footer">
            <button class="btn btn-ghost" type="button" data-close-modal>Abbrechen</button>
            <button class="btn btn-primary" type="button" id="scan-confirm-ok">Trotzdem einchecken</button>
        </div>
    </dialog>
<?php endif; ?>
