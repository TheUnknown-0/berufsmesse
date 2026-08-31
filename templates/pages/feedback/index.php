<?php
/**
 * Feedback — Übersicht der Bögen, die diese Person ausfüllen kann.
 * Erwartet: $forms (mit Schlüssel `submitted`).
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Deine Rückmeldung</div>
        <h1 class="page-title">Feedback</h1>
        <p class="page-sub">Erzähl uns, wie die Messe für dich gelaufen ist.</p>
    </div>
</div>

<?php foreach (page_blocks('feedback', [
    'boegen' => 'Offene Feedback-Bögen',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'boegen'): ?>
    <?php if ($forms === []): ?>
        <div class="empty-state">
            <div class="empty-icon">💬</div>
            <p>Gerade ist kein Feedback-Bogen offen. Schau nach der Messe noch einmal vorbei.</p>
        </div>
    <?php else: ?>
        <div class="stack">
            <?php foreach ($forms as $form): ?>
                <div class="card card-pad">
                    <div class="cluster">
                        <div style="flex:1;min-width:240px;">
                            <h2 class="mt-0 mb-0"><?= e((string) $form['title']) ?></h2>
                            <?php if (!empty($form['description'])): ?>
                                <p class="text-sm text-soft"><?= e((string) $form['description']) ?></p>
                            <?php endif; ?>
                            <div class="chip-row">
                                <?php if ((int) $form['is_anonymous'] === 1): ?>
                                    <span class="badge badge-success">anonym</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">namentlich</span>
                                <?php endif; ?>
                                <?php if ($form['closes_at'] !== null): ?>
                                    <span class="badge badge-info">bis <?= e(format_datetime($form['closes_at'])) ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div>
                            <?php if ($form['submitted']): ?>
                                <span class="badge badge-success">✓ abgegeben</span>
                            <?php else: ?>
                                <a class="btn btn-primary"
                                   href="<?= e($ctx->schoolUrl('/feedback/' . (int) $form['id'])) ?>">Ausfüllen</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
