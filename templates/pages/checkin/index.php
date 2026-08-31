<?php
/**
 * Selbst-Check-in: Kamera-Scanner + manuelle Code-Eingabe.
 * Erwartet: $enabled, $plan, $prefillToken, $edition
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Messetag</div>
        <h1 class="page-title">Check-in</h1>
        <p class="page-sub">Scanne den QR-Code am Stand — damit bist du für diesen Zeitslot als anwesend eingetragen.</p>
    </div>
</div>

<?php foreach (page_blocks('checkin', [
    'hinweis' => 'Hinweis',
    'scanner' => 'Scanner & Code-Eingabe',
    'tagesplan' => 'Dein Tagesplan',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'hinweis'): ?>
<?php if (!$enabled): ?>
    <div class="alert alert-warning">
        <span>⚠️</span>
        <div>Der Selbst-Check-in ist an deiner Schule gerade deaktiviert. Bitte melde dich bei der Aufsicht im Raum.</div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'scanner'): ?>
<?php if ($enabled): ?>
    <div id="checkin-app"
         data-endpoint="<?= e($ctx->schoolUrl('/api/checkin')) ?>"
         data-token="<?= e($prefillToken) ?>">
        <div class="grid-2">
            <div class="card">
                <div class="card-header"><h2>📷 Scannen</h2></div>
                <div class="card-body">
                    <div id="checkin-result" role="status"></div>
                    <div id="checkin-camera" class="mb-2"></div>
                    <div class="cluster">
                        <button class="btn btn-primary" type="button" id="checkin-start">Kamera starten</button>
                        <button class="btn btn-ghost" type="button" id="checkin-stop" hidden>Kamera stoppen</button>
                    </div>
                    <p class="hint mt-2 mb-0">
                        Die Kamera läuft nur in deinem Browser. Es wird kein Bild an den Server gesendet.
                    </p>
                </div>
            </div>

            <div class="card">
                <div class="card-header"><h2>⌨️ Code eintippen</h2></div>
                <div class="card-body">
                    <form id="checkin-form">
                        <div class="field">
                            <label for="checkin-token">Code vom Aushang</label>
                            <input class="input mono" type="text" id="checkin-token" name="token"
                                   autocomplete="off" autocapitalize="characters" spellcheck="false"
                                   placeholder="z. B. K7RM4PQX2TVB" value="<?= e($prefillToken) ?>">
                            <div class="hint">Funktioniert auch ohne Kamera — der Code steht unter dem QR-Code.</div>
                        </div>
                        <button class="btn btn-accent btn-block" type="submit">Check-in bestätigen</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php elseif ($blockKey === 'tagesplan'): ?>
<?php if ($enabled): ?>
    <div class="card mt-2">
        <div class="card-header"><h2>🗓️ Dein Tagesplan</h2></div>
        <?php if ($plan === []): ?>
            <div class="empty-state">
                <div class="empty-icon">🗓️</div>
                <p>Dir sind noch keine Zeitslots zugeteilt.</p>
            </div>
        <?php else: ?>
            <div class="table-wrap" style="border:none;">
                <table class="data-table">
                    <thead>
                    <tr>
                        <th>Zeit</th>
                        <th>Aussteller</th>
                        <th>Raum</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($plan as $row): ?>
                        <tr>
                            <td class="nowrap">
                                <strong><?= e($row['slot_name'] ?? '') ?></strong>
                                <div class="text-sm text-faint">
                                    <?= e(substr((string) $row['start_time'], 0, 5)) ?>–<?= e(substr((string) $row['end_time'], 0, 5)) ?>
                                </div>
                            </td>
                            <td><?= e($row['exhibitor_name']) ?></td>
                            <td><?= e(trim((string) ($row['room_number'] ?? '') . ' ' . (string) ($row['room_name'] ?? ''))) ?: '—' ?></td>
                            <td>
                                <?php if ($row['attendance_id'] !== null): ?>
                                    <span class="badge badge-success">✓ eingecheckt <?= e(format_date($row['checked_in_at'], 'H:i')) ?></span>
                                <?php else: ?>
                                    <span class="badge">offen</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
