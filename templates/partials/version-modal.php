<?php
/**
 * Versionsdialog mit Änderungsverlauf (CHANGELOG.md). Für Global- und
 * Schul-Admins; Global-Admins sehen oben zusätzlich den Update-Stand und den
 * Knopf „Nach neuen Versionen suchen“. Geöffnet über den Versionsknopf in der
 * Seitenleiste (partials/version) oder über die Adresse mit #version.
 * Prüfung und Knopf: public/assets/js/version.js.
 */

use App\Services\Changelog;
use App\Services\UpdateCheck;

$versionRole = $auth->user()['role'] ?? null;
if (!in_array($versionRole, ['admin', 'school_admin'], true)) {
    return;
}

$versionIsGlobal = $versionRole === 'admin';
$versionEntries = Changelog::local();
$versionInstalled = Changelog::currentVersion();
$versionUpdates = $versionIsGlobal ? UpdateCheck::fromContext($ctx) : null;
$versionAuto = $versionUpdates !== null
    && $versionUpdates->isDue((bool) $ctx->session->get(UpdateCheck::SESSION_DUE, false));
?>
<dialog class="modal version-modal" id="version-modal"
        <?php if ($versionIsGlobal): ?>data-version-url="<?= e($ctx->url('/api/version/pruefen')) ?>"<?php endif; ?>
        <?= $versionAuto ? 'data-version-auto' : '' ?>>
    <div class="modal-header">
        <h3>Berufsmesse <?= e($versionInstalled) ?></h3>
        <button class="modal-close" type="button" data-close-modal aria-label="Schließen">×</button>
    </div>
    <div class="modal-body">
        <?php if ($versionUpdates !== null): ?>
            <div class="version-check">
                <button class="btn btn-sm btn-primary" type="button" data-version-check>Nach neuen Versionen suchen</button>
            </div>
            <div data-version-status aria-live="polite">
                <?= $view->renderPartial('partials/version-status', ['update' => $versionUpdates->state()]) ?>
            </div>
        <?php endif; ?>

        <h4 class="version-heading">Änderungsverlauf</h4>
        <?php if ($versionEntries === []): ?>
            <p class="text-faint">Kein Änderungsverlauf vorhanden (CHANGELOG.md fehlt).</p>
        <?php endif; ?>
        <?php foreach ($versionEntries as $versionEntry): ?>
            <section class="changelog-entry">
                <div class="changelog-title">
                    <strong><?= e($versionEntry['version']) ?></strong>
                    <?php if (($versionDate = Changelog::formatDate($versionEntry['date'])) !== null): ?>
                        <span class="text-faint"><?= e($versionDate) ?></span>
                    <?php endif; ?>
                    <?php if ($versionEntry['version'] === $versionInstalled): ?>
                        <span class="badge badge-success">installiert</span>
                    <?php endif; ?>
                </div>
                <ul>
                    <?php foreach ($versionEntry['items'] as $versionItem): ?>
                        <li><?= Changelog::itemHtml($versionItem) ?></li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endforeach; ?>
    </div>
    <div class="modal-footer">
        <button class="btn btn-ghost" type="button" data-close-modal>Schließen</button>
    </div>
</dialog>
<script src="<?= e($ctx->url('/assets/js/version.js')) ?>" defer></script>
