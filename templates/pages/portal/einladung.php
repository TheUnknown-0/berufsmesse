<?php
/**
 * Aussteller-Einladung annehmen (Minimal-Layout, ohne Schulkontext).
 * Erwartet: $invite (array|null), $token, $currentUser (array|null), $old (gemerkte Eingaben).
 */
$old = $old ?? [];
$mode = (string) ($old['mode'] ?? '');
?>
<?php if ($invite === null): ?>
    <h1 style="font-size:1.35rem;">Einladung ungültig</h1>
    <div class="alert alert-error" role="status">
        Dieser Einladungslink ist ungültig, bereits eingelöst oder abgelaufen.
    </div>
    <p class="text-sm text-soft">
        Bitte wende dich an die Organisation der Schule. Sie kann dir einen neuen Link erstellen.
    </p>
    <p class="text-sm"><a class="text-soft" href="<?= e($ctx->url('/login')) ?>">Zur Anmeldung</a></p>
<?php else: ?>
    <h1 style="font-size:1.35rem;">Willkommen bei der Berufsmesse 🎉</h1>
    <p class="text-soft">
        Die Schule <strong><?= e($invite['school_name']) ?></strong> lädt dich ein, das Unternehmen
        <strong><?= e($invite['exhibitor_name']) ?></strong> im Aussteller-Portal zu betreuen.
    </p>

    <?php if ($currentUser !== null && $currentUser['role'] !== 'exhibitor'): ?>
        <div class="alert alert-error" role="status">
            Du bist gerade mit einem Konto der Schulverwaltung angemeldet. Einladungen können nur
            Aussteller-Konten annehmen. Melde dich ab und öffne den Link danach erneut.
        </div>
        <form method="post" action="<?= e($ctx->url('/logout')) ?>">
            <?= $csrf->field() ?>
            <button class="btn btn-ghost btn-block" type="submit">Abmelden</button>
        </form>

    <?php elseif ($currentUser !== null): ?>
        <form method="post" action="<?= e($ctx->url('/aussteller-einladung')) ?>">
            <?= $csrf->field() ?>
            <input type="hidden" name="token" value="<?= e($token) ?>">
            <input type="hidden" name="mode" value="konto">
            <p>Du bist angemeldet als <strong><?= e($currentUser['username']) ?></strong>.</p>
            <button class="btn btn-primary btn-block btn-lg" type="submit">Einladung mit diesem Konto annehmen</button>
        </form>
        <p class="text-sm text-soft" style="margin-top:12px;">
            Soll ein anderes Konto das Unternehmen betreuen? Dann melde dich ab und öffne den Link erneut.
        </p>

    <?php else: ?>
        <details class="card" style="margin-bottom:12px;" <?= $mode === 'anmelden' ? 'open' : '' ?>>
            <summary class="card-header" style="cursor:pointer;"><strong>Ich habe bereits ein Aussteller-Konto</strong></summary>
            <div class="card-body">
                <form method="post" action="<?= e($ctx->url('/aussteller-einladung')) ?>">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <input type="hidden" name="mode" value="anmelden">
                    <div class="field">
                        <label for="login-username">Benutzername</label>
                        <input class="input" type="text" id="login-username" name="username" required autocomplete="username"
                               value="<?= e($mode === 'anmelden' ? (string) ($old['username'] ?? '') : '') ?>">
                    </div>
                    <div class="field">
                        <label for="login-password">Passwort</label>
                        <input class="input" type="password" id="login-password" name="password" required autocomplete="current-password">
                    </div>
                    <button class="btn btn-primary btn-block" type="submit">Anmelden und annehmen</button>
                </form>
            </div>
        </details>

        <details class="card" <?= $mode !== 'anmelden' ? 'open' : '' ?>>
            <summary class="card-header" style="cursor:pointer;"><strong>Neues Konto anlegen</strong></summary>
            <div class="card-body">
                <form method="post" action="<?= e($ctx->url('/aussteller-einladung')) ?>">
                    <?= $csrf->field() ?>
                    <input type="hidden" name="token" value="<?= e($token) ?>">
                    <input type="hidden" name="mode" value="neu">
                    <div class="field">
                        <label for="new-username">Benutzername</label>
                        <input class="input" type="text" id="new-username" name="username" required
                               pattern="[a-zA-Z0-9._@+\-]{3,100}" autocomplete="username"
                               value="<?= e($mode === 'neu' ? (string) ($old['username'] ?? '') : '') ?>">
                        <div class="hint">3 bis 100 Zeichen: Buchstaben, Zahlen, . _ @ + -</div>
                    </div>
                    <div class="form-grid">
                        <div class="field">
                            <label for="new-firstname">Vorname</label>
                            <input class="input" type="text" id="new-firstname" name="firstname" required maxlength="100"
                                   value="<?= e((string) ($old['firstname'] ?? '')) ?>">
                        </div>
                        <div class="field">
                            <label for="new-lastname">Nachname</label>
                            <input class="input" type="text" id="new-lastname" name="lastname" required maxlength="100"
                                   value="<?= e((string) ($old['lastname'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="field">
                        <label for="new-email">E-Mail (optional)</label>
                        <input class="input" type="email" id="new-email" name="email" maxlength="255"
                               value="<?= e((string) ($old['email'] ?? '')) ?>">
                    </div>
                    <div class="field">
                        <label for="new-password">Passwort</label>
                        <input class="input" type="password" id="new-password" name="password" required minlength="8" autocomplete="new-password">
                        <div class="hint">Mindestens 8 Zeichen.</div>
                    </div>
                    <div class="field">
                        <label for="new-password-confirm">Passwort wiederholen</label>
                        <input class="input" type="password" id="new-password-confirm" name="password_confirm" required minlength="8" autocomplete="new-password">
                    </div>
                    <button class="btn btn-primary btn-block btn-lg" type="submit">Konto anlegen und annehmen</button>
                </form>
            </div>
        </details>
        <p class="text-sm text-soft" style="margin-top:12px;">
            Der Link gilt bis <?= e(format_datetime($invite['expires_at'])) ?> und lässt sich nur einmal einlösen.
        </p>
    <?php endif; ?>
<?php endif; ?>
