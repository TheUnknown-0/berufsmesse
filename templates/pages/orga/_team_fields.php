<?php
/**
 * Felder eines Teams. Erwartet: $team (array|null), $colors.
 */
$current = (string) ($team['color'] ?? $colors[0]);
$deadline = !empty($team['deadline']) ? format_date($team['deadline'], 'Y-m-d\TH:i') : '';
$prefix = $team !== null ? 'team-' . (int) $team['id'] : 'team-new';
?>
<div class="field">
    <label for="<?= e($prefix) ?>-name">Name</label>
    <input class="input" id="<?= e($prefix) ?>-name" name="name" required maxlength="100" value="<?= e((string) ($team['name'] ?? '')) ?>" placeholder="z. B. Aussteller-Akquise">
</div>
<div class="field">
    <label for="<?= e($prefix) ?>-desc">Aufgabe des Teams</label>
    <textarea class="input" id="<?= e($prefix) ?>-desc" name="description" rows="3" maxlength="3000"><?= e((string) ($team['description'] ?? '')) ?></textarea>
</div>
<div class="form-grid">
    <div class="field">
        <label>Farbe</label>
        <div class="cluster" role="radiogroup" aria-label="Teamfarbe">
            <?php foreach ($colors as $color): ?>
                <label class="checkbox-row mb-0" title="<?= e($color) ?>">
                    <input type="radio" name="color" value="<?= e($color) ?>" <?= strtolower($current) === $color ? 'checked' : '' ?>>
                    <span class="team-dot" style="background: <?= e($color) ?>; width:18px; height:18px;"></span>
                </label>
            <?php endforeach; ?>
        </div>
    </div>
    <div class="field">
        <label for="<?= e($prefix) ?>-deadline">Eigener Abschlusstermin</label>
        <input class="input" type="datetime-local" id="<?= e($prefix) ?>-deadline" name="deadline" value="<?= e($deadline) ?>">
        <div class="hint">Leer = Abschlusstermin des Orga-Teams.</div>
    </div>
</div>
