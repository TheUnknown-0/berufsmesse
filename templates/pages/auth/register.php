<?php /** Schüler-Selbstregistrierung. */ $old = $ctx->session->pullOldInput(); ?>
<h1 style="font-size:1.35rem;">Registrieren</h1>
<p class="text-soft text-sm">Erstelle dein Schüler-Konto für die Berufsmesse.</p>

<form method="post" action="">
    <?= $csrf->field() ?>

    <div class="form-grid">
        <div class="field">
            <label for="firstname">Vorname</label>
            <input class="input" type="text" id="firstname" name="firstname" required value="<?= e($old['firstname'] ?? '') ?>">
        </div>
        <div class="field">
            <label for="lastname">Nachname</label>
            <input class="input" type="text" id="lastname" name="lastname" required value="<?= e($old['lastname'] ?? '') ?>">
        </div>
    </div>
    <div class="form-grid">
        <div class="field">
            <label for="username">Benutzername</label>
            <input class="input" type="text" id="username" name="username" required autocomplete="username"
                   pattern="[a-zA-Z0-9._-]{3,50}" value="<?= e($old['username'] ?? '') ?>">
            <div class="hint">3–50 Zeichen, Buchstaben/Zahlen/Punkt/Minus/Unterstrich.</div>
        </div>
        <div class="field">
            <label for="class">Klasse</label>
            <input class="input" type="text" id="class" name="class" placeholder="z. B. 10b" value="<?= e($old['class'] ?? '') ?>">
        </div>
    </div>
    <div class="field">
        <label for="password">Passwort</label>
        <input class="input" type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
        <div class="hint">Mindestens 8 Zeichen.</div>
    </div>
    <div class="field">
        <label for="password_confirm">Passwort wiederholen</label>
        <input class="input" type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Konto erstellen</button>
</form>

<p class="text-sm" style="margin-top:16px;">
    <a class="text-soft" href="<?= e($ctx->schoolUrl('/login')) ?>">← Zurück zum Login</a>
</p>
