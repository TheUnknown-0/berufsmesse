<?php
/**
 * Hilfe-Ruf: rufen (eigener Raum), Status des eigenen Rufs, offene Rufe mit
 * „Ich komme“. Inhalt zeichnet assets/js/help-calls.js (geladen über das
 * Layout-Partial help-call-bar).
 * Erwartet: $statusUrl, $canManage
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Messetag</div>
        <h1 class="page-title">Hilfe-Ruf</h1>
        <p class="page-sub">
            Hilfe aus deinem Raum rufen und offene Rufe sehen. Aufs Handy kommen Rufe
            mit der Berufsmesse-App und dem Schalter „Im Dienst“.
        </p>
    </div>
    <?php if ($canManage): ?>
        <div class="page-actions">
            <a class="btn" href="<?= e($ctx->schoolUrl('/admin/hilfe')) ?>">⚙️ Verwalten</a>
        </div>
    <?php endif; ?>
</div>

<?php foreach (page_blocks('hilfe', [
    'rufen' => 'Hilfe rufen',
    'offene-rufe' => 'Offene Rufe',
]) as $blockKey => $blockLabel): ?>
<?= block_open($blockKey, $blockLabel) ?>
<?php if ($blockKey === 'rufen'): ?>
    <div id="help-call-form" class="help-page-section" data-status-url="<?= e($statusUrl) ?>">
        <div class="card card-pad text-faint">Lädt …</div>
    </div>
<?php elseif ($blockKey === 'offene-rufe'): ?>
    <div id="help-call-list" class="help-page-section"></div>
<?php endif; ?>
<?= block_close() ?>
<?php endforeach; ?>
