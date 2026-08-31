<?php
/**
 * Untere Leiste des Anordnen-Modus — nur sichtbar, wenn aktiv.
 * Lädt arrange.js; trägt Seitenkey, Ziel-Rolle und Speicher-URL als data-Attribute.
 */

use App\Core\PageBlocks;

if (!PageBlocks::isArranging()) {
    return;
}
$exitUrl = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/';
$targetRole = PageBlocks::targetRole();
$roleOptions = [
    '' => 'Alle Rollen (Basis)',
    'student' => 'Schüler:innen',
    'teacher' => 'Lehrkräfte',
    'exhibitor' => 'Aussteller',
    'admin' => 'Verwaltung',
];
?>
<div class="arrange-bar" id="arrange-bar"
     data-page="<?= e((string) PageBlocks::pageKey()) ?>"
     data-role="<?= e($targetRole) ?>"
     data-save-url="<?= e($ctx->schoolUrl('/api/darstellung/seite')) ?>"
     data-exit-url="<?= e($exitUrl) ?>">
    <span class="arrange-info">
        🧩 <strong>Anordnen-Modus</strong> — ziehe Abschnitte in die Reihenfolge deiner Wahl oder blende sie aus.
    </span>
    <label class="cluster text-sm" style="gap:6px;">
        <span class="nowrap">Gilt für:</span>
        <select class="input" style="width:auto;padding:5px 8px;" data-arrange-role>
            <?php foreach ($roleOptions as $value => $label): ?>
                <option value="<?= e($value) ?>" <?= $targetRole === $value ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <span class="cluster">
        <button class="btn btn-sm" type="button" data-arrange-reset>↺ Standard</button>
        <a class="btn btn-sm" href="<?= e($exitUrl) ?>">Abbrechen</a>
        <button class="btn btn-primary btn-sm" type="button" data-arrange-save>💾 Speichern</button>
    </span>
</div>
<script src="<?= e($ctx->config['app']['base_url']) ?>/assets/js/arrange.js"></script>
