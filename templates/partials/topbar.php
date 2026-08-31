<?php
/** Topbar: Mobile-Sidebar-Toggle, Titel, Theme-Umschalter, Benutzermenü. */
$user = $auth->user();
?>
<header class="topbar">
    <button class="sidebar-toggle" type="button" data-sidebar-toggle aria-label="Navigation öffnen">☰</button>
    <div class="topbar-spacer"></div>

    <?php if (\App\Core\PageBlocks::canArrange() && !\App\Core\PageBlocks::isArranging()): ?>
        <a class="btn btn-sm" href="<?= e((parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/') . '?anordnen=1') ?>"
           title="Abschnitte dieser Seite anordnen">🧩 Anordnen</a>
    <?php endif; ?>

    <button class="btn btn-sm" type="button" data-start-tour aria-label="Einführungstour starten" title="Einführungstour">❓</button>
    <button class="btn btn-sm" type="button" data-toggle-theme aria-label="Hell-/Dunkelmodus umschalten">◐</button>

    <?php if ($user !== null): ?>
        <div class="menu">
            <button class="user-chip btn btn-sm" type="button" data-menu-toggle>
                <span class="avatar"><?= e(mb_strtoupper(mb_substr($user['firstname'] !== '' ? $user['firstname'] : $user['username'], 0, 1))) ?></span>
                <span class="nowrap"><?= e(trim($user['firstname'] . ' ' . $user['lastname']) !== '' ? trim($user['firstname'] . ' ' . $user['lastname']) : $user['username']) ?></span>
            </button>
            <div class="menu-list" hidden>
                <a class="menu-item" href="<?= e($ctx->url('/passwort-aendern')) ?>">🔒 Passwort ändern</a>
                <form method="post" action="<?= e($ctx->url('/logout')) ?>">
                    <?= $csrf->field() ?>
                    <button class="menu-item danger" type="submit">🚪 Abmelden</button>
                </form>
            </div>
        </div>
    <?php endif; ?>
</header>
