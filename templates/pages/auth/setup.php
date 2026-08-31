<?php /** Erst-Einrichtung (nur solange keine Benutzer existieren). */ $old = $ctx->session->pullOldInput(); ?>
<h1 style="font-size:1.35rem;">Willkommen! 🎉</h1>
<p class="text-soft">
    Richte in einem Schritt das Admin-Konto, deine Schule und die erste Messe ein.
</p>

<form method="post" action="">
    <?= $csrf->field() ?>

    <h3>Admin-Konto</h3>
    <div class="form-grid">
        <div class="field">
            <label for="username">Benutzername</label>
            <input class="input" type="text" id="username" name="username" required pattern="[a-zA-Z0-9._-]{3,50}" value="<?= e($old['username'] ?? '') ?>">
        </div>
    </div>
    <div class="form-grid">
        <div class="field">
            <label for="password">Passwort</label>
            <input class="input" type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
            <div class="hint">Mindestens 8 Zeichen.</div>
        </div>
        <div class="field">
            <label for="password_confirm">Passwort wiederholen</label>
            <input class="input" type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
        </div>
    </div>

    <hr class="divider">
    <h3>Schule</h3>
    <div class="form-grid">
        <div class="field">
            <label for="school_name">Name der Schule</label>
            <input class="input" type="text" id="school_name" name="school_name" required value="<?= e($old['school_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="school_slug">URL-Name</label>
            <input class="input" type="text" id="school_slug" name="school_slug" required pattern="[a-z0-9-]{2,100}"
                   placeholder="z. b. bso" value="<?= e($old['school_slug'] ?? '') ?>">
            <div class="hint">Erscheint in der Adresse: /<strong>url-name</strong>/ — nur Kleinbuchstaben, Zahlen, Minus.</div>
        </div>
    </div>

    <hr class="divider">
    <h3>Erste Messe</h3>
    <div class="form-grid">
        <div class="field">
            <label for="edition_name">Bezeichnung</label>
            <input class="input" type="text" id="edition_name" name="edition_name" placeholder="z. B. Berufsmesse 2027" value="<?= e($old['edition_name'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="year">Jahr</label>
            <input class="input" type="number" id="year" name="year" min="2020" max="2100" value="<?= e($old['year'] ?? date('Y')) ?>">
        </div>
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Einrichtung abschließen</button>
</form>
