<?php /** Login (global oder schulspezifisch). Erwartet optional: $redirect. */ ?>
<h1 style="font-size:1.35rem;">Anmelden</h1>
<p class="text-soft text-sm">
    <?= $ctx->school !== null
        ? 'Melde dich mit deinem Schul-Konto an.'
        : 'Globaler Login für Administrator:innen und Aussteller.' ?>
</p>

<form method="post" action="">
    <?= $csrf->field() ?>
    <?php if (!empty($redirect)): ?>
        <input type="hidden" name="redirect" value="<?= e($redirect) ?>">
    <?php endif; ?>

    <div class="field">
        <label for="username">Benutzername</label>
        <input class="input" type="text" id="username" name="username" required autofocus autocomplete="username">
    </div>
    <div class="field">
        <label for="password">Passwort</label>
        <input class="input" type="password" id="password" name="password" required autocomplete="current-password">
    </div>

    <button class="btn btn-primary btn-block btn-lg" type="submit">Anmelden</button>
</form>

<?php if ($ctx->school !== null): ?>
    <?php $regOpen = $ctx->settings->getBool('registration_page_enabled', $ctx->schoolId()); ?>
    <?php if ($regOpen): ?>
        <p class="text-sm text-soft" style="margin-top:16px;">
            Noch kein Konto? <a href="<?= e($ctx->schoolUrl('/registrieren')) ?>">Jetzt registrieren</a>
        </p>
    <?php endif; ?>
    <p class="text-sm" style="margin-top:8px;">
        <a class="text-soft" href="<?= e($ctx->url('/')) ?>">← Andere Schule wählen</a>
    </p>
<?php else: ?>
    <p class="text-sm" style="margin-top:16px;">
        <a class="text-soft" href="<?= e($ctx->url('/')) ?>">← Zur Schulauswahl</a>
    </p>
<?php endif; ?>
