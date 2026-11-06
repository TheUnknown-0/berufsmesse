<?php
/**
 * Update-Stand im Versionsdialog (nur Global-Admins). Wird beim Seitenaufbau
 * und nach jeder Prüfung (VersionController) neu gerendert.
 *
 * @var array{installed: string, latest: ?string, available: bool, checkedAt: ?int, error: ?string,
 *            configured: bool, news: list<array{version: string, date: ?string, items: list<string>}>} $update
 */

use App\Services\Changelog;

$statusChecked = $update['checkedAt'] !== null ? date('d.m.Y H:i', $update['checkedAt']) : null;
$notice ??= null;
?>
<?php if ($notice !== null): ?>
    <div class="alert alert-info"><?= e($notice) ?></div>
<?php endif; ?>
<?php if (!$update['configured']): ?>
    <div class="alert alert-info">Die Update-Prüfung ist abgeschaltet (<code>UPDATE_CHECK_URL=off</code>).</div>
<?php elseif ($update['available']): ?>
    <div class="alert alert-warning version-available">
        <strong>Version <?= e($update['latest']) ?> ist verfügbar.</strong>
        Installiert ist <?= e($update['installed']) ?>.
        <?php foreach ($update['news'] as $statusEntry): ?>
            <div class="changelog-entry">
                <div class="changelog-title">
                    <strong><?= e($statusEntry['version']) ?></strong>
                    <?php if (($statusDate = Changelog::formatDate($statusEntry['date'])) !== null): ?>
                        <span class="text-faint"><?= e($statusDate) ?></span>
                    <?php endif; ?>
                </div>
                <ul>
                    <?php foreach ($statusEntry['items'] as $statusItem): ?>
                        <li><?= Changelog::itemHtml($statusItem) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
        <div class="text-sm">
            Einspielen auf dem Server im Projektordner:
            <code>git pull</code>, danach <code>docker compose up -d --build</code>.
        </div>
    </div>
<?php elseif ($update['error'] !== null && $update['error'] !== ''): ?>
    <div class="alert alert-error">Die letzte Prüfung ist fehlgeschlagen: <?= e($update['error']) ?></div>
<?php elseif ($statusChecked !== null): ?>
    <div class="alert alert-success">Die Berufsmesse ist auf dem neuesten Stand.</div>
<?php endif; ?>
<?php if ($update['configured']): ?>
    <p class="text-sm text-faint version-checked">
        <?= $statusChecked !== null ? 'Zuletzt geprüft: ' . e($statusChecked) : 'Noch nicht geprüft.' ?>
    </p>
<?php endif; ?>
