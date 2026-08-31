<?php
/**
 * Global-Admin — Übersicht über alle Schulen und das System.
 * Erwartet: $schools, $system, $publicBaseUrl.
 */
?>
<div class="page-header">
    <div class="page-title-group">
        <div class="page-eyebrow">System</div>
        <h1 class="page-title">Global-Admin</h1>
        <p class="page-sub">Schulübergreifende Verwaltung aller Mandanten.</p>
    </div>
</div>

<?= $view->renderPartial('pages/global/_nav', ['active' => '/global-admin']) ?>

<div class="stat-grid">
    <div class="stat-card stat-accent">
        <div class="stat-value"><?= e((string) $system['schools_active']) ?> / <?= e((string) $system['schools']) ?></div>
        <div class="stat-label">Schulen aktiv / gesamt</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $system['editions_active']) ?></div>
        <div class="stat-label">Aktive Messen</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $system['users']) ?></div>
        <div class="stat-label">Nutzer gesamt</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= e((string) $system['exhibitor_accounts']) ?></div>
        <div class="stat-label">Aussteller-Konten</div>
    </div>
    <div class="stat-card<?= $system['pending_cancellations'] > 0 ? ' stat-danger' : '' ?>">
        <div class="stat-value"><?= e((string) $system['pending_cancellations']) ?></div>
        <div class="stat-label">Offene Absage-Anfragen</div>
    </div>
</div>

<div class="card mb-2">
    <div class="card-header"><h3>🌍 Öffentliche Basis-URL</h3></div>
    <div class="card-body">
        <form method="post" action="<?= e($ctx->url('/global-admin/einstellungen')) ?>" class="cluster" style="align-items:flex-end;">
            <?= $csrf->field() ?>
            <div class="field mb-0" style="flex:1;min-width:280px;">
                <label for="public_base_url">Adresse, unter der die Anwendung von außen erreichbar ist</label>
                <input class="input" type="url" id="public_base_url" name="public_base_url"
                       placeholder="https://messe.meine-schule.de" value="<?= e($publicBaseUrl) ?>">
                <div class="hint">Wird für Aussteller-Einladungslinks und QR-Codes verwendet. Leer lassen = Adresse des aktuellen Aufrufs. Pro Schule überschreibbar unter <a href="<?= e($ctx->url('/global-admin/schulen')) ?>">Schulen</a>.</div>
            </div>
            <button class="btn btn-primary" type="submit">Speichern</button>
        </form>
    </div>
</div>

<?php if ($schools === []): ?>
    <div class="empty-state">
        <div class="empty-icon">🏫</div>
        <p>Es sind noch keine Schulen angelegt.</p>
        <a class="btn btn-primary" href="<?= e($ctx->url('/global-admin/schulen')) ?>">Schule anlegen</a>
    </div>
<?php else: ?>
    <div class="grid-3">
        <?php foreach ($schools as $school): ?>
            <div class="card">
                <div class="card-header">
                    <h3 style="margin:0;"><?= e($school['name']) ?></h3>
                    <?php if ((int) $school['is_active'] === 1): ?>
                        <span class="badge badge-success">aktiv</span>
                    <?php else: ?>
                        <span class="badge badge-danger">inaktiv</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <div class="text-sm text-soft mono">/<?= e($school['slug']) ?>/</div>
                    <div class="divider"></div>
                    <?php if ($school['edition_name'] !== null): ?>
                        <div><strong><?= e($school['edition_name']) ?></strong></div>
                    <?php else: ?>
                        <div class="text-sm text-danger">Keine aktive Edition</div>
                    <?php endif; ?>
                    <div class="chip-row" style="margin-top:10px;">
                        <span class="badge badge-info"><?= e((string) $school['student_count']) ?> Schüler:innen</span>
                        <span class="badge badge-info"><?= e((string) $school['exhibitor_count']) ?> Aussteller</span>
                        <span class="badge badge-primary"><?= e((string) $school['registration_count']) ?> Anmeldungen</span>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="cluster">
                        <a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/' . $school['slug'] . '/admin/dashboard')) ?>">Öffnen</a>
                        <a class="btn btn-sm btn-ghost" href="<?= e($ctx->url('/global-admin/editionen')) ?>">Editionen</a>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
