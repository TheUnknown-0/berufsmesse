<?php
/**
 * Minimal-Layout: Login, Registrierung, Fehlerseiten, Setup.
 * Erwartet: $content, optional $title, $wide (bool), $pageScripts.
 * Schulspezifisch: Logo, Farben und optionales Login-Hintergrundbild.
 */

use App\Services\Customization;

$baseUrl = $ctx->config['app']['base_url'];
$loginImage = null;
if ($ctx->school !== null) {
    $theme = (new Customization($ctx->settings, $ctx->schoolId()))->theme();
    $loginImage = $theme['login_image'];
}
$wrapStyle = $loginImage !== null
    ? 'background:linear-gradient(rgb(17 24 39 / 0.45), rgb(17 24 39 / 0.45)), url(' .
      e($ctx->url('/medien/branding/' . $loginImage)) . ') center/cover no-repeat;'
    : '';
?><!DOCTYPE html>
<html lang="de" class="no-js">
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
<div class="minimal-wrap" style="<?= $wrapStyle ?>">
    <div class="minimal-card<?= !empty($wide) ? ' wide' : '' ?>">
        <div class="brand-row">
            <?php if ($ctx->school !== null && !empty($ctx->school['logo'])): ?>
                <img class="brand-logo" style="width:38px;height:38px;"
                     src="<?= e($ctx->url('/medien/logos/' . $ctx->school['logo'])) ?>" alt="">
            <?php else: ?>
                <span class="brand-mark" style="width:38px;height:38px;border-radius:8px;background:var(--primary);display:grid;place-items:center;color:var(--on-primary);font-family:var(--font-display);font-weight:800;">B</span>
            <?php endif; ?>
            <div>
                <strong style="font-family:var(--font-display);font-size:1.05rem;">Berufsmesse</strong>
                <?php if ($ctx->school !== null): ?>
                    <div class="text-sm text-soft"><?= e($ctx->school['name']) ?></div>
                <?php endif; ?>
            </div>
        </div>
        <?= $view->renderPartial('partials/flash') ?>
        <?= $content ?>
    </div>
</div>
<script src="<?= e($baseUrl) ?>/assets/js/app.js"></script>
<?php foreach ($pageScripts ?? [] as $script): ?>
    <script src="<?= e($baseUrl) ?>/assets/js/<?= e($script) ?>"></script>
<?php endforeach; ?>
</body>
</html>
