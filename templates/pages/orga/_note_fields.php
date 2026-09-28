<?php
/**
 * Felder eines Beschlusses/Protokolls. Erwartet: $note (array|null).
 */
use App\Services\OrgaService;

$prefix = $note !== null ? 'note-' . (int) $note['id'] : 'note-new';
?>
<div class="form-grid">
    <div class="field">
        <label for="<?= e($prefix) ?>-kind">Art</label>
        <select class="input" id="<?= e($prefix) ?>-kind" name="kind">
            <?php foreach (OrgaService::NOTE_KINDS as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= ($note['kind'] ?? 'protocol') === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="<?= e($prefix) ?>-date">Sitzungsdatum</label>
        <input class="input" type="date" id="<?= e($prefix) ?>-date" name="meeting_date" value="<?= e((string) ($note['meeting_date'] ?? date('Y-m-d'))) ?>">
    </div>
</div>
<div class="field">
    <label for="<?= e($prefix) ?>-title">Titel</label>
    <input class="input" id="<?= e($prefix) ?>-title" name="title" required maxlength="200" value="<?= e((string) ($note['title'] ?? '')) ?>">
</div>
<div class="field">
    <label for="<?= e($prefix) ?>-body">Inhalt</label>
    <textarea class="input" id="<?= e($prefix) ?>-body" name="body" rows="10" required><?= e((string) ($note['body'] ?? '')) ?></textarea>
    <div class="hint">Anwesende, Tagesordnungspunkte, Ergebnisse, nächste Schritte …</div>
</div>
