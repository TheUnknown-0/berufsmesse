<?php
/**
 * Benutzer anlegen/bearbeiten.
 * Erwartet: $user (null beim Anlegen), $old, $roles, $action
 */
$isNew = $user === null;
$value = static fn (string $key, mixed $fallback = ''): string => (string) ($old[$key] ?? ($user[$key] ?? $fallback) ?? '');
$currentRole = (string) ($old['role'] ?? ($user['role'] ?? 'student'));
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow"><a class="text-soft" href="<?= e($ctx->schoolUrl('/admin/benutzer')) ?>">← Benutzer</a></div>
        <h1 class="page-title"><?= $isNew ? 'Neuer Benutzer' : e(trim((string) $user['firstname'] . ' ' . (string) $user['lastname'])) ?></h1>
        <?php if (!$isNew): ?>
            <p class="page-sub">Benutzername <span class="mono"><?= e($user['username']) ?></span></p>
        <?php endif; ?>
    </div>
</div>

<form class="card card-pad" method="post" action="<?= e($action) ?>">
    <?= $csrf->field() ?>

<?php $formBlocks = page_blocks('admin-benutzer-formular', [
    'name' => 'Vor- und Nachname',
    'zugang' => 'Zugangsdaten',
    'zuordnung' => 'Klasse & Rolle',
    'passwort' => 'Passwort',
]); ?>
<?php foreach ($formBlocks as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'name'): ?>
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

<?php elseif ($blockKey === 'zugang'): ?>
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

<?php elseif ($blockKey === 'zuordnung'): ?>
    <div class="form-grid">
        <div class="field">
            <label for="class">Klasse</label>
            <input class="input" type="text" id="class" name="class" maxlength="50" placeholder="z. B. 10b"
                   value="<?= e($value('class')) ?>">
        </div>
        <div class="field">
            <label for="role">Rolle *</label>
            <select id="role" name="role" required>
                <?php foreach ($roles as $key => $label): ?>
                    <option value="<?= e($key) ?>"<?= $currentRole === $key ? ' selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <?php if (!$isNew && $auth->id() === (int) $user['id']): ?>
                <div class="hint">Deine eigene Rolle kannst du nicht ändern.</div>
            <?php endif; ?>
        </div>
    </div>

<?php elseif ($blockKey === 'passwort'): ?>
    <?php if ($isNew): ?>
        <hr class="divider">
        <div class="field">
            <label for="password">Passwort (optional)</label>
            <input class="input" type="password" id="password" name="password" minlength="8"
                   autocomplete="new-password">
            <div class="hint">
                Leer lassen für ein Konto <strong>ohne Passwort</strong> — die Zugangsdaten werden später
                über das Zugangsdaten-PDF verteilt. Ein gesetztes Passwort braucht mindestens 8 Zeichen.
            </div>
        </div>
        <label class="checkbox-row">
            <input type="checkbox" name="must_change_password" value="1" checked>
            <span>Passwortwechsel beim ersten Login erzwingen (bei Konten ohne Passwort immer aktiv)</span>
        </label>
    <?php else: ?>
        <div class="alert alert-info">
            Das Passwort wird hier nicht geändert — nutze dafür „🔑 Passwort“ in der Benutzerliste.
        </div>
    <?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>

    <div class="cluster">
        <button class="btn btn-primary" type="submit"><?= $isNew ? 'Benutzer anlegen' : 'Änderungen speichern' ?></button>
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/benutzer')) ?>">Abbrechen</a>
    </div>
</form>
