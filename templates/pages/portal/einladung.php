<?php
/**
 * Aussteller-Einladung annehmen (Minimal-Layout, ohne Schulkontext).
 * Erwartet: $invite (array|null), $token.
 */
?>
<?php if ($invite === null): ?>
    <h1 style="font-size:1.35rem;">Einladung ungültig</h1>
    <div class="alert alert-error" role="status">
        Dieser Einladungslink ist ungültig, bereits eingelöst oder abgelaufen.
    </div>
    <p class="text-sm text-soft">
        Bitte wende dich an die Organisation der Schule — sie kann dir einen neuen Link erstellen.
    </p>
    <p class="text-sm"><a class="text-soft" href="<?= e($ctx->url('/login')) ?>">Zur Anmeldung</a></p>
<?php else: ?>
    <h1 style="font-size:1.35rem;">Willkommen bei der Berufsmesse 🎉</h1>
    <p class="text-soft">
        Du wurdest als Ansprechpartner:in für <strong><?= e($invite['exhibitor_name']) ?></strong>
        an der Schule <strong><?= e($invite['school_name']) ?></strong> eingeladen.
        Vergib jetzt ein Passwort für dein Konto <strong><?= e($invite['username']) ?></strong>.
    </p>

    <form method="post" action="<?= e($ctx->url('/aussteller-einladung')) ?>">
        <?= $csrf->field() ?>
        <input type="hidden" name="token" value="<?= e($token) ?>">

        <div class="field">
            <label for="password">Passwort</label>
            <input class="input" type="password" id="password" name="password" required minlength="8" autocomplete="new-password">
            <div class="hint">Mindestens 8 Zeichen.</div>
        </div>
        <div class="field">
            <label for="password_confirm">Passwort wiederholen</label>
            <input class="input" type="password" id="password_confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
        </div>

        <button class="btn btn-primary btn-block btn-lg" type="submit">Einladung annehmen</button>
    </form>
<?php endif; ?>
