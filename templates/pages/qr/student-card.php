<?php
/**
 * Persönliche QR-Karte einer Schüler:in (Token für den Lehrer-Scan).
 * Erwartet: $student, $token, $edition
 */
$imgBase = $ctx->schoolUrl('/api/qr/bild');
$name = trim((string) $student['firstname'] . ' ' . (string) $student['lastname']);
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">Schüler-QR-Karte</div>
        <h1 class="page-title"><?= e($name) ?></h1>
        <p class="page-sub">
            <?= e($student['class'] ?? 'Ohne Klasse') ?>
            <?php if (!empty($edition['name'])): ?> · <?= e($edition['name']) ?><?php endif; ?>
        </p>
    </div>
    <div class="page-actions no-print">
        <a class="btn btn-ghost" href="<?= e($ctx->schoolUrl('/admin/qr-codes/schueler')) ?>">← Übersicht</a>
    </div>
</div>

<div class="card card-pad" style="max-width:520px;break-inside:avoid;">
    <div class="cluster" style="align-items:center;gap:24px;">
        <div class="qr-frame">
            <img src="<?= e($imgBase . '?scale=8&data=' . urlencode($token)) ?>"
                 width="272" height="272" alt="QR-Code <?= e($name) ?>">
        </div>
        <div>
            <h2 class="mb-0"><?= e($name) ?></h2>
            <p class="text-soft"><?= e($student['class'] ?? 'Ohne Klasse') ?></p>
            <p class="text-sm text-faint mb-0">Code</p>
            <p class="mono text-sm" style="word-break:break-all;"><?= e($token) ?></p>
        </div>
    </div>
</div>

<div class="alert alert-warning mt-2 no-print">
    <span>🔒</span>
    <div>Dieser Code ist persönlich. Er dient ausschließlich dem Check-in durch die Aufsicht.</div>
</div>
