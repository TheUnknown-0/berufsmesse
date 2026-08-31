<?php
/**
 * Haupt-Layout für eingeloggte Bereiche (Sidebar + Topbar).
 * Erwartet: $content (HTML), optional $title, $pageScripts (list<string> Pfade
 * relativ zu /assets/js/), $ctx, $auth, $csrf (via View::share).
 */
$baseUrl = $ctx->config['app']['base_url'];
?><!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= e($csrf->token()) ?>">
    <title><?= e(isset($title) ? $title . ' · Berufsmesse' : 'Berufsmesse') ?></title>
    <link rel="stylesheet" href="<?= e($baseUrl) ?>/assets/css/app.css">
    <?= $view->renderPartial('partials/theme') ?>
    <script src="<?= e($baseUrl) ?>/assets/js/theme-init.js"></script>
</head>
<body>
<div class="app-shell">
    <?= $view->renderPartial('partials/sidebar') ?>
    <div class="sidebar-backdrop" hidden></div>
    <div class="app-main">
        <?= $view->renderPartial('partials/topbar', ['title' => $title ?? null]) ?>
        <main class="app-content">
            <?= $view->renderPartial('partials/flash') ?>
            <?= $view->renderPartial('partials/notifications-modal') ?>
            <?= $content ?>
        </main>
    </div>
</div>
<?= $view->renderPartial('partials/arrange-bar') ?>
<script src="<?= e($baseUrl) ?>/assets/js/app.js"></script>
<script src="<?= e($baseUrl) ?>/assets/js/easter-egg.js"></script>
<script src="<?= e($baseUrl) ?>/assets/js/tour.js"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e($baseUrl) ?>/assets/js/<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
