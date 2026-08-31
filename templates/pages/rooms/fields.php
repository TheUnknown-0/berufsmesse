<?php
/**
 * Formularfelder eines Raums (geteilt von Anlegen- und Bearbeiten-Dialog).
 * Erwartet: $room (leeres Array beim Anlegen), $prefix (eindeutige ID-Basis).
 */
$id = static fn (string $field): string => $prefix . '-' . $field;
?>
<div class="form-grid">
    <div class="field">
        <label for="<?= e($id('room_number')) ?>">Raumnummer *</label>
        <input class="input" type="text" id="<?= e($id('room_number')) ?>" name="room_number" required maxlength="50"
               value="<?= e($room['room_number'] ?? '') ?>">
    </div>
    <div class="field">
        <label for="<?= e($id('room_name')) ?>">Raumname</label>
        <input class="input" type="text" id="<?= e($id('room_name')) ?>" name="room_name" maxlength="200"
               value="<?= e($room['room_name'] ?? '') ?>">
    </div>
    <div class="field">
        <label for="<?= e($id('building')) ?>">Gebäude</label>
        <input class="input" type="text" id="<?= e($id('building')) ?>" name="building" maxlength="100"
               value="<?= e($room['building'] ?? '') ?>">
    </div>
    <div class="field">
        <label for="<?= e($id('floor')) ?>">Etage</label>
        <input class="input" type="text" id="<?= e($id('floor')) ?>" name="floor" maxlength="50"
               value="<?= e($room['floor'] ?? '') ?>">
    </div>
    <div class="field">
        <label for="<?= e($id('capacity')) ?>">Kapazität</label>
        <input class="input" type="number" id="<?= e($id('capacity')) ?>" name="capacity" min="0" max="9999"
               value="<?= e((string) (int) ($room['capacity'] ?? 30)) ?>">
        <div class="hint">Standardplätze je Zeitslot.</div>
    </div>
</div>
<div class="field mb-0">
    <label for="<?= e($id('equipment')) ?>">Ausstattung</label>
    <input class="input" type="text" id="<?= e($id('equipment')) ?>" name="equipment" maxlength="500"
           placeholder="z. B. Beamer, Whiteboard, WLAN" value="<?= e($room['equipment'] ?? '') ?>">
</div>
