<?php
/**
 * Ankündigungen als Hinweiskästen (Startseiten aller Rollen).
 * Erwartet: $announcements (siehe Services\Announcements::activeFor).
 */
?>
<?php foreach ($announcements as $announcement): ?>
    <?php
    $type = (string) $announcement['type'];
    $class = in_array($type, ['info', 'success', 'warning', 'error'], true) ? $type : 'info';
    ?>
    <div class="alert alert-<?= e($class) ?>">
        <span aria-hidden="true">📣</span>
        <div>
            <strong><?= e($announcement['title']) ?></strong>
            <?php if (!empty($announcement['body'])): ?>
                <div class="text-sm"><?= nl2br(e($announcement['body'])) ?></div>
            <?php endif; ?>
        </div>
    </div>
<?php endforeach; ?>
