<?php
/**
 * Versionsnummer unten in der Seitenleiste, für alle sichtbar.
 * Admins (Global- und Schul-Admins) öffnen per Klick den Versionsdialog
 * (partials/version-modal). Global-Admins sehen am Knopf, ob ein Update da ist.
 */

use App\Services\Changelog;
use App\Services\UpdateCheck;

$versionRole = $auth->user()['role'] ?? null;
$versionNumber = Changelog::currentVersion();
$versionLabel = 'Version ' . $versionNumber;
?>
<?php if (!in_array($versionRole, ['admin', 'school_admin'], true)): ?>
    <span class="app-version"><?= e($versionLabel) ?></span>
<?php else: ?>
    <?php $versionUpdate = $versionRole === 'admin' && UpdateCheck::fromContext($ctx)->state()['available']; ?>
    <button class="app-version app-version-btn" type="button" data-open-modal="version-modal"
            title="Änderungsverlauf anzeigen">
        <?= e($versionLabel) ?>
        <span class="version-badge" data-version-badge<?= $versionUpdate ? '' : ' hidden' ?>>Update</span>
    </button>
<?php endif; ?>
