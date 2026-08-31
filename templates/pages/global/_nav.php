<?php
/** Reiter-Navigation des Global-Admins. Erwartet: $active (string). */
$globalTabs = [
    '/global-admin' => 'Übersicht',
    '/global-admin/schulen' => 'Schulen',
    '/global-admin/editionen' => 'Editionen',
    '/global-admin/aussteller-konten' => 'Aussteller-Konten',
    '/global-admin/administratoren' => 'Administratoren',
    '/global-admin/logs' => 'Logs',
];
?>
<div class="tabs">
    <?php foreach ($globalTabs as $path => $label): ?>
        <a class="tab<?= ($active ?? '') === $path ? ' active' : '' ?>" href="<?= e($ctx->url($path)) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>
