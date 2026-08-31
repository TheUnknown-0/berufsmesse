<?php
/**
 * Bestätigung nach dem Absenden (oder Hinweis bei bereits abgegebenem Bogen).
 * Erwartet: $form, $alreadyDone (bool).
 */
$thanks = trim((string) ($form['thank_you_text'] ?? ''));
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">
            <a class="text-soft" href="<?= e($ctx->schoolUrl('/feedback')) ?>">← Übersicht</a>
        </div>
        <h1 class="page-title"><?= e((string) $form['title']) ?></h1>
    </div>
</div>

<div class="card card-pad">
    <div class="empty-state">
        <div class="empty-icon"><?= $alreadyDone ? '✅' : '🎉' ?></div>
        <?php if ($alreadyDone): ?>
            <p>Du hast diesen Bogen bereits abgegeben. Vielen Dank!</p>
        <?php else: ?>
            <p><?= $thanks !== '' ? nl2br(e($thanks)) : 'Danke für deine Rückmeldung!' ?></p>
            <?php if ((int) $form['is_anonymous'] === 1): ?>
                <p class="text-sm text-soft">Deine Antworten wurden anonym gespeichert.</p>
            <?php endif; ?>
        <?php endif; ?>
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/feedback')) ?>">Zurück zur Übersicht</a>
    </div>
</div>
