<?php
/**
 * Global-Admin — Administrator anlegen/bearbeiten.
 * Erwartet: $admin (null beim Anlegen), $old, $schools, $action.
 */
$isNew = $admin === null;
$value = static fn (string $key, mixed $fallback = ''): string => (string) ($old[$key] ?? ($admin[$key] ?? $fallback) ?? '');
$currentSchool = (int) ($old['school_id'] ?? ($admin['school_id'] ?? 0));
$isVisible = isset($old['username'])
    ? isset($old['visible_in_school_list'])
    : (int) ($admin['visible_in_school_list'] ?? 0) === 1;
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">
            <a class="text-soft" href="<?= e($ctx->url('/global-admin/administratoren')) ?>">← Administratoren</a>
        </div>
        <h1 class="page-title">
            <?= $isNew ? 'Neuer Administrator' : e(trim((string) $admin['firstname'] . ' ' . (string) $admin['lastname'])) ?>
        </h1>
        <?php if (!$isNew): ?>
            <p class="page-sub">Benutzername <span class="mono"><?= e($admin['username']) ?></span></p>
        <?php endif; ?>
    </div>
</div>

<form class="card card-pad" method="post" action="<?= e($action) ?>">
    <?= $csrf->field() ?>

    <div class="alert alert-warning">
        Ein globaler Administrator hat in <strong>allen</strong> Schulen sämtliche Rechte —
        auch für Konten, Berechtigungen und Einstellungen. Vergib die Rolle sparsam.
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="firstname">Vorname *</label>
            <input class="input" type="text" id="firstname" name="firstname" required maxlength="100"
                   value="<?= e($value('firstname')) ?>">
        </div>
        <div class="field">
            <label for="lastname">Nachname *</label>
            <input class="input" type="text" id="lastname" name="lastname" required maxlength="100"
                   value="<?= e($value('lastname')) ?>">
        </div>
    </div>

    <div class="form-grid">
        <div class="field">
            <label for="username">Benutzername *</label>
            <input class="input" type="text" id="username" name="username" required
                   pattern="[a-zA-Z0-9._\-]{3,50}" autocomplete="off"
                   value="<?= e($value('username')) ?>">
            <div class="hint">3–50 Zeichen: Buchstaben, Zahlen, Punkt, Minus, Unterstrich.</div>
        </div>
        <div class="field">
            <label for="email">E-Mail</label>
            <input class="input" type="email" id="email" name="email" maxlength="255"
                   value="<?= e($value('email')) ?>">
        </div>
    </div>

    <hr class="divider">

    <div class="field">
        <label for="school_id">Schulzuordnung</label>
        <select id="school_id" name="school_id">
            <option value="0"<?= $currentSchool === 0 ? ' selected' : '' ?>>Keine — systemweites Konto</option>
            <?php foreach ($schools as $school): ?>
                <option value="<?= e((string) $school['id']) ?>"<?= $currentSchool === (int) $school['id'] ? ' selected' : '' ?>>
                    <?= e($school['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div class="hint">
            Rein organisatorisch: Der Zugriff auf alle Schulen besteht ohnehin. Die Zuordnung entscheidet nur,
            in welcher Benutzerliste das Konto erscheinen kann.
        </div>
    </div>

    <label class="checkbox-row">
        <input type="checkbox" name="visible_in_school_list" value="1"<?= $isVisible ? ' checked' : '' ?>>
        <span>In der Benutzerliste der zugeordneten Schule anzeigen</span>
    </label>
    <div class="hint">
        Ohne Häkchen bleibt das Konto in <span class="mono">/{schule}/admin/benutzer</span> unsichtbar.
        Bearbeitet werden kann es in jedem Fall nur hier.
    </div>

    <?php if ($isNew): ?>
        <hr class="divider">
        <div class="field">
            <label for="password">Passwort (optional)</label>
            <input class="input" type="password" id="password" name="password" minlength="8"
                   autocomplete="new-password">
            <div class="hint">
                Leer lassen für ein Konto <strong>ohne Passwort</strong> — es kann sich erst anmelden,
                wenn hier eines vergeben wurde. Ein gesetztes Passwort braucht mindestens 8 Zeichen.
            </div>
        </div>
        <label class="checkbox-row">
            <input type="checkbox" name="must_change_password" value="1" checked>
            <span>Passwortwechsel beim ersten Login erzwingen (ohne Passwort immer aktiv)</span>
        </label>
    <?php else: ?>
        <hr class="divider">
        <div class="alert alert-info">
            Das Passwort wird hier nicht geändert — nutze dafür „🔑 Passwort“ in der Liste.
        </div>
    <?php endif; ?>

    <div class="cluster">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Administrator anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn-ghost" href="<?= e($ctx->url('/global-admin/administratoren')) ?>">Abbrechen</a>
    </div>
</form>
